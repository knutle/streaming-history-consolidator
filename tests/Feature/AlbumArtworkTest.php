<?php

namespace Tests\Feature;

use App\Models\Listen;
use App\Models\User;
use App\Services\AlbumArtwork;
use App\Services\ManualMusicEntityMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AlbumArtworkTest extends TestCase
{
    use RefreshDatabase;

    private const RELEASE = '33333333-3333-4333-8333-333333333333';

    private const OTHER_RELEASE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const RECORDING = '11111111-1111-4111-8111-111111111111';

    private const COVER_URL = 'https://coverartarchive.org/release/'.self::RELEASE.'/front-500';

    private function listen(User $user, ?string $release = self::RELEASE, string $album = 'Album'): Listen
    {
        return Listen::create([
            'user_id' => $user->id, 'source' => 'spotify', 'fingerprint' => fake()->sha256(),
            'track_name' => 'Track', 'artist_name' => 'Artist', 'release_name' => $album,
            'listened_at' => 1700000000, 'played_ms' => 60000, 'recording_mbid' => self::RECORDING,
            'track_metadata' => ['additional_info' => $release === null ? [] : ['release_mbid' => $release]],
        ]);
    }

    private function client(string $address = '93.184.216.34'): AlbumArtwork
    {
        $client = Mockery::mock(AlbumArtwork::class, [app(ManualMusicEntityMatcher::class)])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $client->shouldReceive('addresses')->andReturn([$address]);
        $this->app->instance(AlbumArtwork::class, $client);

        return $client;
    }

    private function image(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a0uUAAAAASUVORK5CYII=', true);
    }

    public function test_disabled_artwork_never_fetches_displays_or_searches_even_with_a_cached_cover(): void
    {
        config(['artwork.enabled' => false]);
        Http::preventStrayRequests();
        Storage::fake('local');
        $listen = $this->listen(User::factory()->create());
        $client = $this->client();
        Storage::disk('local')->put($client->path(self::RELEASE), $this->image());
        $this->assertSame('disabled', $client->forListen($listen)['status']);
        $this->actingAs(User::findOrFail($listen->user_id));
        $this->get(route('artwork.image', self::RELEASE))->assertNotFound();
        $this->postJson(route('artwork.search', $listen->id), ['q' => 'Album'])->assertNotFound();
        $this->post(route('artwork.store', $listen->id), ['mbid' => self::RELEASE, 'name' => 'Album'])->assertNotFound();
        $this->delete(route('artwork.destroy', $listen->id))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_initial_detail_response_defers_cover_work_and_serves_cached_images_only_to_owners(): void
    {
        config(['artwork.enabled' => true, 'musicbrainz.enabled' => false]);
        Http::preventStrayRequests();
        Http::fake([self::COVER_URL => fn () => Http::response($this->image(), 200, ['Content-Type' => 'image/png'])]);
        Storage::fake('local');
        $listen = $this->listen(User::factory()->create());
        $client = $this->client();
        $response = $this->actingAs(User::findOrFail($listen->user_id))->get(route('listens.show', $listen->id));
        Http::assertNothingSent();
        $response->assertInertia(fn (Assert $page) => $page->where('artworkEnabled', true)->missing('artwork')
            ->loadDeferredProps('artwork', fn (Assert $reload) => $reload
                ->where('artwork.status', 'ready')->where('artwork.mbid', self::RELEASE)));
        $this->get(route('artwork.image', self::RELEASE))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertStreamedContent($this->image());
        Storage::disk('local')->assertExists($client->path(self::RELEASE));
        $this->actingAs(User::factory()->create())->get(route('artwork.image', self::RELEASE))->assertNotFound();
        Http::assertSentCount(1);
    }

    public function test_cover_is_shared_by_release_and_refetched_after_thirty_days_or_when_file_is_missing(): void
    {
        config(['artwork.enabled' => true]);
        $this->freezeTime();
        Http::preventStrayRequests();
        Http::fake([self::COVER_URL => fn () => Http::response($this->image(), 200, ['Content-Type' => 'image/png'])]);
        Storage::fake('local');
        $client = $this->client();
        $this->assertSame('ready', $client->cover(self::RELEASE)['status']);
        $other = $this->listen(User::factory()->create());
        $this->assertSame('ready', $client->forListen($other)['status']);
        Http::assertSentCount(1);
        $this->travel(30)->days();
        $this->assertSame('ready', $client->cover(self::RELEASE)['status']);
        Http::assertSentCount(2);
        Storage::disk('local')->delete($client->path(self::RELEASE));
        $this->assertSame('ready', $client->cover(self::RELEASE)['status']);
        Http::assertSentCount(3);
    }

    public function test_missing_cover_is_cached_for_twenty_four_hours(): void
    {
        config(['artwork.enabled' => true]);
        $this->freezeTime();
        Http::preventStrayRequests();
        Http::fake([self::COVER_URL => Http::response('', 404)]);
        Storage::fake('local');
        $client = $this->client();
        $this->assertSame('missing', $client->cover(self::RELEASE)['status']);
        $this->travel(23)->hours();
        $this->assertSame('missing', $client->cover(self::RELEASE)['status']);
        Http::assertSentCount(1);
        $this->travel(1)->hours();
        $this->assertSame('missing', $client->cover(self::RELEASE)['status']);
        Http::assertSentCount(2);
        Storage::disk('local')->assertMissing($client->path(self::RELEASE));
    }

    public function test_provider_cooldown_is_shared_and_failed_refresh_preserves_the_previous_cover(): void
    {
        config(['artwork.enabled' => true]);
        $this->freezeTime();
        Exceptions::fake();
        Http::preventStrayRequests();
        Http::fake([self::COVER_URL => Http::sequence()
            ->push($this->image(), 200, ['Content-Type' => 'image/png'])
            ->push('', 503, ['Retry-After' => '600'])
            ->push($this->image(), 200, ['Content-Type' => 'image/png'])]);
        Storage::fake('local');
        $client = $this->client();
        $this->assertSame('ready', $client->cover(self::RELEASE)['status']);
        $this->travel(30)->days();
        $this->assertSame('stale', $client->cover(self::RELEASE)['status']);
        $this->assertSame('error', $client->cover(self::OTHER_RELEASE)['status']);
        $this->travel(5)->minutes();
        $this->assertSame('stale', $client->cover(self::RELEASE)['status']);
        Http::assertSentCount(2);
        $this->travel(5)->minutes();
        $this->assertSame('ready', $client->cover(self::RELEASE)['status']);
        Http::assertSentCount(3);
        Storage::disk('local')->assertExists($client->path(self::RELEASE));
        Exceptions::assertReported(\RuntimeException::class);
    }

    public function test_safe_archive_redirect_is_followed_and_dns_is_pinned(): void
    {
        config(['artwork.enabled' => true]);
        Http::preventStrayRequests();
        Http::fake([
            self::COVER_URL => Http::response('', 307, ['Location' => 'https://archive.org/download/cover.png']),
            'https://archive.org/download/cover.png' => function (Request $request, array $options) {
                $this->assertSame(['archive.org:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
                $this->assertFalse($options['allow_redirects']);

                return Http::response($this->image(), 200, ['Content-Type' => 'image/png']);
            },
        ]);
        Storage::fake('local');
        $this->assertSame('ready', $this->client()->cover(self::RELEASE)['status']);
        Http::assertSentCount(2);
    }

    #[TestWith(['https://127.0.0.1/cover'])]
    #[TestWith(['https://example.com/cover'])]
    #[TestWith(['https://archive.org.example.com/cover'])]
    #[TestWith(['http://archive.org/cover'])]
    #[TestWith(['https://user:pass@archive.org/cover'])]
    #[TestWith(['https://archive.org:8443/cover'])]
    public function test_unsafe_redirects_are_reported_without_connecting_to_the_destination(string $url): void
    {
        config(['artwork.enabled' => true]);
        Exceptions::fake();
        Http::preventStrayRequests();
        Http::fake([self::COVER_URL => Http::response('', 307, ['Location' => $url])]);
        Storage::fake('local');
        $this->assertSame('error', $this->client()->cover(self::RELEASE)['status']);
        Http::assertSentCount(1);
        Exceptions::assertReported(\RuntimeException::class);
        Storage::disk('local')->assertDirectoryEmpty('album-artwork');
    }

    #[TestWith(['127.0.0.1'])]
    #[TestWith(['100.64.0.1'])]
    #[TestWith(['224.0.0.1'])]
    public function test_private_dns_addresses_and_invalid_ids_are_rejected_before_fetching(string $address): void
    {
        config(['artwork.enabled' => true]);
        Exceptions::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        $client = $this->client($address);
        $this->assertSame('unavailable', $client->cover('../image')['status']);
        $this->assertSame('error', $client->cover(self::RELEASE)['status']);
        Http::assertNothingSent();
        Exceptions::assertReported(\RuntimeException::class);
    }

    #[TestWith(['<svg xmlns="http://www.w3.org/2000/svg"/>', 'image/svg+xml', 32])]
    #[TestWith(['not a png', 'image/png', 9])]
    #[TestWith(['too large', 'image/png', 2000001])]
    public function test_invalid_or_oversized_downloads_are_not_stored(string $body, string $mime, int $length): void
    {
        config(['artwork.enabled' => true]);
        Exceptions::fake();
        Http::preventStrayRequests();
        Http::fake([self::COVER_URL => Http::response($body, 200, ['Content-Type' => $mime, 'Content-Length' => $length])]);
        Storage::fake('local');
        $client = $this->client();
        $result = $client->cover(self::RELEASE);
        $this->assertSame('error', $result['status']);
        $this->assertNotNull($result['error']);
        Storage::disk('local')->assertMissing($client->path(self::RELEASE));
        Http::assertSentCount(1);
        Exceptions::assertReported(\RuntimeException::class);
    }

    public function test_connection_failure_is_not_cached_as_a_missing_cover(): void
    {
        config(['artwork.enabled' => true]);
        $this->freezeTime();
        Exceptions::fake();
        Http::preventStrayRequests();
        Http::fake([self::COVER_URL => Http::sequence()->pushFailedConnection()->push('', 404)]);
        Storage::fake('local');
        $client = $this->client();
        $this->assertSame('error', $client->cover(self::RELEASE)['status']);
        $this->travel(5)->minutes();
        $this->assertSame('missing', $client->cover(self::RELEASE)['status']);
        Http::assertSentCount(2);
        Exceptions::assertReported(ConnectionException::class);
    }

    public function test_manual_search_and_selection_are_owner_scoped_and_do_not_change_music_matches(): void
    {
        config(['artwork.enabled' => true]);
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://musicbrainz.org/ws/2/release?*' => Http::response([
                'count' => 1, 'releases' => [['id' => self::OTHER_RELEASE, 'title' => 'Selected album']],
            ]),
            'https://musicbrainz.org/ws/2/release/'.self::OTHER_RELEASE.'?*' => Http::response([
                'id' => self::OTHER_RELEASE, 'title' => 'Selected album',
            ]),
        ]);
        $listen = $this->listen(User::factory()->create());
        $sameGroup = $this->listen(User::findOrFail($listen->user_id));
        $differentCase = $this->listen(User::findOrFail($listen->user_id), self::RELEASE, 'ALBUM');
        $other = $this->listen(User::factory()->create());
        $client = $this->client();
        $this->assertSame(self::RELEASE, $client->releaseForListen($listen));
        Http::assertNothingSent();
        $this->actingAs(User::findOrFail($listen->user_id))->postJson(route('artwork.search', $listen->id), ['q' => 'Selected album'])
            ->assertOk()->assertJsonPath('results.0.mbid', self::OTHER_RELEASE);
        $this->from(route('listens.show', $listen->id))->post(route('artwork.store', $listen->id), [
            'mbid' => self::OTHER_RELEASE, 'name' => 'Selected album',
        ])->assertRedirect(route('listens.show', $listen->id));
        $this->assertSame(self::OTHER_RELEASE, $client->releaseForListen($sameGroup));
        $this->assertSame(self::RELEASE, $client->releaseForListen($other));
        $this->assertSame(self::RELEASE, $client->releaseForListen($differentCase));
        $this->assertSame(self::RECORDING, $listen->fresh()->recording_mbid);
        $this->assertSame(self::RELEASE, $listen->fresh()->track_metadata['additional_info']['release_mbid']);
        $this->assertDatabaseCount('music_entity_matches', 0);
        $this->assertDatabaseCount('listen_releases', 0);
        $this->delete(route('artwork.destroy', $listen->id))->assertRedirect();
        $this->assertSame(self::RELEASE, $client->releaseForListen($sameGroup));
        Http::assertSentCount(2);
    }

    public function test_guests_other_owners_invalid_input_and_tampered_selections_are_rejected(): void
    {
        config(['artwork.enabled' => true]);
        Http::preventStrayRequests();
        $listen = $this->listen(User::factory()->create());
        $this->post(route('artwork.search', $listen->id))->assertRedirect(route('login'));
        $this->get(route('artwork.image', self::RELEASE))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create());
        $this->postJson(route('artwork.search', $listen->id), ['q' => 'Album'])->assertNotFound();
        $this->post(route('artwork.store', $listen->id), ['mbid' => self::RELEASE, 'name' => 'Album'])->assertNotFound();
        $this->delete(route('artwork.destroy', $listen->id))->assertNotFound();
        Http::assertNothingSent();
        $this->actingAs(User::findOrFail($listen->user_id));
        $this->postJson(route('artwork.search', $listen->id), ['q' => '', 'page' => 0])->assertUnprocessable()
            ->assertJsonValidationErrors(['q', 'page']);
        $this->post(route('artwork.store', $listen->id), ['mbid' => '../image'])->assertInvalid(['mbid', 'name']);
        Http::fake(['https://musicbrainz.org/ws/2/release/'.self::RELEASE.'?*' => Http::response([
            'id' => self::RELEASE, 'title' => 'Actual title',
        ])]);
        $this->post(route('artwork.store', $listen->id), ['mbid' => self::RELEASE, 'name' => 'Tampered title'])
            ->assertInvalid(['mbid']);
        $this->assertDatabaseCount('album_artwork_choices', 0);
        Http::assertSentCount(1);
    }

    public function test_conflicting_release_ids_do_not_guess_a_cover_and_album_groups_consider_all_members(): void
    {
        config(['artwork.enabled' => true]);
        Http::preventStrayRequests();
        $listen = $this->listen(User::factory()->create());
        $this->listen(User::findOrFail($listen->user_id), self::OTHER_RELEASE);
        $release = DB::table('music_releases')->insertGetId(['mbid' => self::OTHER_RELEASE, 'name' => 'Other release']);
        DB::table('listen_releases')->insert(['listen_id' => $listen->id, 'music_release_id' => $release]);
        $client = $this->client();
        $this->assertNull($client->releaseForListen($listen));
        $this->assertSame('unavailable', $client->forGroup($listen)['status']);
        Http::assertNothingSent();
    }

    public function test_oversized_stream_without_a_length_header_is_rejected(): void
    {
        config(['artwork.enabled' => true]);
        Exceptions::fake();
        Http::preventStrayRequests();
        Http::fake([self::COVER_URL => Http::response(str_repeat('x', 2_000_001), 200, ['Content-Type' => 'image/png'])]);
        Storage::fake('local');
        $client = $this->client();
        $this->assertSame('error', $client->cover(self::RELEASE)['status']);
        Storage::disk('local')->assertMissing($client->path(self::RELEASE));
        Http::assertSentCount(1);
        Exceptions::assertReported(\RuntimeException::class);
    }

    public function test_storage_failure_is_reported_instead_of_returning_a_successful_cover(): void
    {
        config(['artwork.enabled' => true]);
        Exceptions::fake();
        Http::preventStrayRequests();
        Http::fake([self::COVER_URL => Http::response($this->image(), 200, ['Content-Type' => 'image/png'])]);
        $disk = Storage::fake('local');
        $failingDisk = Mockery::mock($disk)->makePartial();
        $failingDisk->shouldReceive('put')->once()->andReturnFalse();
        Storage::shouldReceive('disk')->with('local')->andReturn($failingDisk);
        $result = $this->client()->cover(self::RELEASE);
        $this->assertSame('error', $result['status']);
        $this->assertNull($result['url']);
        $disk->assertMissing('album-artwork/'.self::RELEASE.'.image');
        Http::assertSentCount(1);
        Exceptions::assertReported(\RuntimeException::class);
    }

    public function test_album_group_resolves_a_known_release_from_another_member_without_name_searches(): void
    {
        config(['artwork.enabled' => true]);
        Http::preventStrayRequests();
        Http::fake([self::COVER_URL => Http::response($this->image(), 200, ['Content-Type' => 'image/png'])]);
        Storage::fake('local');
        $user = User::factory()->create();
        $listen = $this->listen($user, null);
        $this->listen($user);
        $this->assertSame('ready', $this->client()->forGroup($listen)['status']);
        Http::assertSentCount(1);
    }

    public function test_uppercase_release_metadata_resolves_and_authorizes_the_same_cached_cover(): void
    {
        config(['artwork.enabled' => true]);
        Http::preventStrayRequests();
        $url = 'https://coverartarchive.org/release/'.self::OTHER_RELEASE.'/front-500';
        Http::fake([$url => Http::response($this->image(), 200, ['Content-Type' => 'image/png'])]);
        Storage::fake('local');
        $user = User::factory()->create();
        $listen = $this->listen($user, strtoupper(self::OTHER_RELEASE));
        $result = $this->client()->forListen($listen);
        $this->assertSame('ready', $result['status']);
        $this->assertSame(self::OTHER_RELEASE, $result['mbid']);
        $this->actingAs($user)->get($result['url'])->assertOk()->assertStreamedContent($this->image());
        Http::assertSentCount(1);
    }
}
