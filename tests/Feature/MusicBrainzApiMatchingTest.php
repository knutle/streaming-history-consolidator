<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\ProcessRecordingAssociation;
use App\Jobs\ScanArtistRecordings;
use App\Models\Listen;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MusicBrainzApiMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Sleep::fake(false);
        parent::tearDown();
    }

    private function importedListen(): Listen
    {
        config(['musicbrainz.enabled' => false]);
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'spotify',
            'file' => UploadedFile::fake()->createWithContent('history.json', json_encode([[
                'ts' => '2024-01-01T12:00:00Z', 'ms_played' => 60000,
                'master_metadata_track_name' => 'Song', 'master_metadata_album_artist_name' => 'Artist',
                'master_metadata_album_album_name' => 'Album', 'spotify_track_uri' => 'spotify:track:0123456789ABCDEFGHIJKL',
            ]], JSON_THROW_ON_ERROR)),
        ])->assertSessionHasNoErrors();

        return Listen::query()->sole();
    }

    private function recording(): array
    {
        return [
            'id' => '22222222-2222-4222-8222-222222222222', 'title' => 'Song',
            'artist-credit' => [
                ['name' => 'Artist', 'artist' => ['id' => '11111111-1111-4111-8111-111111111111']],
            ],
        ];
    }

    public function test_api_search_and_selection_work_without_local_database(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        $listen = $this->importedListen();
        Http::fake([
            'musicbrainz.org/ws/2/recording?*' => Http::response(['count' => 31, 'recordings' => [$this->recording()]]),
            'musicbrainz.org/ws/2/recording/22222222-2222-4222-8222-222222222222?*' => Http::response($this->recording()),
        ]);
        $this->get(route('listens.show', ['listen' => $listen->id, 'q' => 'Song', 'artist' => 'Artist', 'search_source' => 'api']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('searchSource', 'api')
            ->where('suggestions.0.recording_mbid', '22222222-2222-4222-8222-222222222222')
            ->where('suggestions.0.artist_name', 'Artist')->where('hasMore', true));
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://musicbrainz.org/ws/2/recording?')
            && $request['query'] === 'recording:"Song" AND artist:"Artist"' && $request->hasHeader('User-Agent'));

        $this->post(route('listens.match', $listen->id), [
            'recording_mbid' => '22222222-2222-4222-8222-222222222222',
            'track_name' => 'Song', 'artist_name' => 'Artist', 'search_source' => 'api',
        ])->assertSessionHasNoErrors();
        $this->assertNull($listen->fresh()->recording_mbid);
        $this->artisan('queue:work', ['connection' => 'imports', '--once' => true])->assertSuccessful();
        $run = DB::table('recording_association_runs')->sole();
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->matched);
        app()->call([new ProcessRecordingAssociation($run->id), 'handle']);
        $this->assertSame('22222222-2222-4222-8222-222222222222', $listen->fresh()->recording_mbid);
        $this->assertSame(['11111111-1111-4111-8111-111111111111'], $listen->fresh()->track_metadata['additional_info']['artist_mbids']);
        $this->post(route('listens.import'), [
            'source' => 'spotify', 'file' => UploadedFile::fake()->createWithContent('history.json', json_encode([[
                'ts' => '2024-01-02T12:00:00Z', 'ms_played' => 60000,
                'master_metadata_track_name' => 'Song', 'master_metadata_album_artist_name' => 'Artist',
                'master_metadata_album_album_name' => 'Album', 'spotify_track_uri' => 'spotify:track:0123456789ABCDEFGHIJKL',
            ]], JSON_THROW_ON_ERROR)),
        ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 1]);
        $this->assertSame('22222222-2222-4222-8222-222222222222', Listen::query()->latest('id')->firstOrFail()->recording_mbid);
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_recording_selection_is_queued_and_a_stale_choice_fails_without_overwriting(): void
    {
        Http::preventStrayRequests();
        $listen = $this->importedListen();
        Http::fake(['musicbrainz.org/ws/2/recording/*' => Http::response($this->recording())]);
        $values = [
            'recording_mbid' => $this->recording()['id'], 'track_name' => 'Song',
            'artist_name' => 'Artist', 'search_source' => 'api',
        ];
        $this->post(route('listens.match', $listen->id), $values)->assertSessionHasNoErrors();
        $this->assertNull($listen->fresh()->recording_mbid);
        $run = DB::table('recording_association_runs')->sole();
        $this->assertSame('queued', $run->status);
        $this->assertDatabaseHas('jobs', ['queue' => 'imports']);
        $this->post(route('listens.match', $listen->id), $values)->assertSessionHasErrors('recording_mbid');
        $this->get(route('listens.show', ['listen' => $listen->id, 'search_source' => 'api']), [
            'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'listens/show',
            'X-Inertia-Partial-Data' => 'recordingAssociation',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        ])->assertOk()->assertJsonPath('props.recordingAssociation.status', 'queued');
        Http::assertSentCount(2);
        $listen->update(['recording_mbid' => '33333333-3333-4333-8333-333333333333']);
        $this->artisan('queue:work', ['connection' => 'imports', '--once' => true, '--tries' => 1])
            ->assertSuccessful();
        $this->assertSame('33333333-3333-4333-8333-333333333333', $listen->fresh()->recording_mbid);
        $this->assertDatabaseHas('recording_association_runs', [
            'id' => $run->id, 'status' => 'failed', 'error' => 'This listen has changed or has already been identified.',
        ]);
    }

    public function test_matched_recordings_require_explicit_search_and_a_current_snapshot_before_replacement(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        $listen = $this->importedListen();
        $owner = User::query()->findOrFail($listen->user_id);
        $oldRecording = '33333333-3333-4333-8333-333333333333';
        $listen->update(['recording_mbid' => $oldRecording]);
        $values = ['recording_mbid' => $this->recording()['id'], 'track_name' => 'Song',
            'artist_name' => 'Artist', 'search_source' => 'api'];
        $this->get(route('listens.show', ['listen' => $listen->id, 'search_source' => 'api']))
            ->assertInertia(fn (Assert $page) => $page->where('rematching', false)->has('suggestions', 0));
        $this->post(route('listens.match', $listen->id), $values)->assertSessionHasErrors('recording_mbid');
        $this->post(route('listens.match', $listen->id), $values + ['expected_recording_mbid' => $this->recording()['id']])
            ->assertSessionHasErrors('recording_mbid');
        $this->actingAs(User::factory()->create())
            ->get(route('listens.show', ['listen' => $listen->id, 'search_source' => 'api', 'rematch' => 1]))->assertNotFound();
        $this->post(route('listens.match', $listen->id), $values + ['expected_recording_mbid' => $oldRecording])->assertNotFound();
        Http::assertNothingSent();

        $this->actingAs($owner);
        Http::fake([
            'musicbrainz.org/ws/2/recording?*' => Http::response(['count' => 1, 'recordings' => [$this->recording()]]),
            'musicbrainz.org/ws/2/recording/*' => Http::response($this->recording()),
        ]);
        $this->get(route('listens.show', ['listen' => $listen->id, 'search_source' => 'api', 'rematch' => 1]))
            ->assertInertia(fn (Assert $page) => $page->where('rematching', true)
                ->where('suggestions.0.recording_mbid', $this->recording()['id']));
        $this->post(route('listens.match', $listen->id), $values + ['expected_recording_mbid' => $oldRecording])
            ->assertSessionHasNoErrors();
        $this->assertSame($oldRecording, $listen->fresh()->recording_mbid);
        $this->artisan('queue:work', ['connection' => 'imports', '--once' => true])->assertSuccessful();
        $this->assertSame($this->recording()['id'], $listen->fresh()->recording_mbid);
        Http::assertSentCount(2);
    }

    public function test_a_queued_replacement_cannot_overwrite_a_newer_recording_choice(): void
    {
        Http::preventStrayRequests();
        $listen = $this->importedListen();
        $oldRecording = '33333333-3333-4333-8333-333333333333';
        $newerRecording = '44444444-4444-4444-8444-444444444444';
        $listen->update(['recording_mbid' => $oldRecording]);
        Http::fake(['musicbrainz.org/ws/2/recording/*' => Http::response($this->recording())]);
        $this->post(route('listens.match', $listen->id), [
            'recording_mbid' => $this->recording()['id'], 'expected_recording_mbid' => $oldRecording,
            'track_name' => 'Song', 'artist_name' => 'Artist', 'search_source' => 'api',
        ])->assertSessionHasNoErrors();
        $listen->update(['recording_mbid' => $newerRecording]);
        $this->artisan('queue:work', ['connection' => 'imports', '--once' => true, '--tries' => 1])->assertSuccessful();
        $this->assertSame($newerRecording, $listen->fresh()->recording_mbid);
        $this->assertDatabaseHas('recording_association_runs', ['status' => 'failed']);
        $this->assertDatabaseCount('listen_matches', 0);
        Http::assertSentCount(1);
    }

    public function test_api_recording_suggestions_show_release_details_and_missing_metadata(): void
    {
        Http::preventStrayRequests();
        $listen = $this->importedListen();
        $recording = $this->recording();
        $recording['first-release-date'] = '1998-06-01';
        $recording['releases'] = [
            ['id' => '33333333-3333-4333-8333-333333333333', 'title' => 'Collection',
                'artist-credit' => [
                    ['name' => 'First', 'joinphrase' => ' & '], ['name' => 'Second'],
                ],
                'release-group' => ['primary-type' => 'Album', 'secondary-types' => ['Compilation']]],
            ['id' => '44444444-4444-4444-8444-444444444444', 'title' => 'Untitled metadata'],
        ];
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::response(['count' => 1, 'recordings' => [$recording]])]);

        $this->get(route('listens.show', ['listen' => $listen->id, 'q' => 'Song', 'search_source' => 'api']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('suggestions.0.first_release_year', 1998)
            ->where('suggestions.0.releases', [
                ['mbid' => '33333333-3333-4333-8333-333333333333', 'title' => 'Collection',
                    'artist' => 'First & Second', 'group_type' => 'Album / Compilation'],
                ['mbid' => '44444444-4444-4444-8444-444444444444', 'title' => 'Untitled metadata',
                    'artist' => null, 'group_type' => null],
            ]));
        Http::assertSentCount(1);
    }

    public function test_api_recording_match_saves_individual_musicbrainz_artist_names(): void
    {
        Http::preventStrayRequests();
        $listen = $this->importedListen();
        $recording = $this->recording();
        $recording['artist-credit'] = [
            ['name' => 'Credit alias', 'joinphrase' => ' & ',
                'artist' => ['id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Real artist']],
            ['name' => 'Guest credit',
                'artist' => ['id' => '33333333-3333-4333-8333-333333333333', 'name' => 'Real guest']],
        ];
        Http::fake(['musicbrainz.org/ws/2/recording/*' => Http::response($recording)]);

        $this->post(route('listens.match', $listen->id), [
            'recording_mbid' => $recording['id'], 'track_name' => 'Song',
            'artist_name' => 'Credit alias & Guest credit', 'search_source' => 'api',
        ])->assertSessionHasNoErrors();
        $this->processRecordingAssociation();
        $this->get(route('artists.show', '33333333-3333-4333-8333-333333333333'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('entity.name', 'Real guest')
            ->where('entity.nameSource', 'musicbrainz'));
        $this->get(route('listens.show', $listen->id))->assertInertia(fn (Assert $page) => $page
            ->where('matchedArtists.0.name', 'Real artist')->where('matchedArtists.1.name', 'Real guest'));
        Http::assertSentCount(1);
    }

    public function test_missing_artist_name_can_be_fetched_only_for_a_users_own_listens(): void
    {
        Http::preventStrayRequests();
        $listen = $this->importedListen();
        $owner = User::query()->findOrFail($listen->user_id);
        $id = DB::table('music_artists')->insertGetId(['mbid' => '11111111-1111-4111-8111-111111111111', 'name' => null]);
        DB::table('listen_artists')->insert(['listen_id' => $listen->id, 'music_artist_id' => $id]);
        $url = route('artists.lookup-name', '11111111-1111-4111-8111-111111111111');
        Http::fake(['musicbrainz.org/ws/2/artist/*' => Http::response([
            'id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Verified artist',
        ])]);

        $this->actingAs(User::factory()->create())->post($url)->assertNotFound();
        Http::assertNothingSent();
        $this->actingAs($owner)->post($url)->assertRedirect(route('artists.show', '11111111-1111-4111-8111-111111111111'));
        $this->get(route('artists.show', '11111111-1111-4111-8111-111111111111'))
            ->assertInertia(fn (Assert $page) => $page->where('entity.name', 'Verified artist')->where('entity.nameSource', 'musicbrainz'));
        $this->post($url)->assertRedirect(route('artists.show', '11111111-1111-4111-8111-111111111111'));
        Http::assertSentCount(1);
    }

    public function test_missing_artist_name_lookup_reports_api_failure(): void
    {
        Http::preventStrayRequests();
        $listen = $this->importedListen();
        $id = DB::table('music_artists')->insertGetId(['mbid' => '11111111-1111-4111-8111-111111111111', 'name' => null]);
        DB::table('listen_artists')->insert(['listen_id' => $listen->id, 'music_artist_id' => $id]);
        Http::fake(['musicbrainz.org/ws/2/artist/*' => Http::response([], 503)]);

        $this->post(route('artists.lookup-name', '11111111-1111-4111-8111-111111111111'))
            ->assertSessionHasErrors('artist_name');
        $this->assertDatabaseHas('music_artists', ['id' => $id, 'name' => null]);
        Http::assertSentCount(1);
    }

    public function test_api_search_reports_malformed_release_metadata(): void
    {
        Http::preventStrayRequests();
        $listen = $this->importedListen();
        $recording = $this->recording();
        $recording['releases'] = [['id' => '33333333-3333-4333-8333-333333333333', 'title' => 'Album', 'release-group' => ['secondary-types' => 'Compilation']]];
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::response(['count' => 1, 'recordings' => [$recording]])]);

        $this->get(route('listens.show', ['listen' => $listen->id, 'q' => 'Song', 'search_source' => 'api']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('apiError', 'MusicBrainz returned invalid release group metadata.')
            ->has('suggestions', 0));
        Http::assertSentCount(1);
    }

    public function test_local_default_and_other_users_never_trigger_api_requests(): void
    {
        Http::preventStrayRequests();
        $listen = $this->importedListen();
        $this->get(route('listens.show', $listen->id))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('searchSource', 'local'));
        $this->actingAs(User::factory()->create())
            ->get(route('listens.show', ['listen' => $listen->id, 'search_source' => 'api']))->assertNotFound();
        $this->post(route('listens.match', $listen->id), ['search_source' => 'api'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_unavailable_api_is_reported_without_empty_success_or_saving_a_match(): void
    {
        Http::preventStrayRequests();
        $listen = $this->importedListen();
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::response([], 503)]);
        $this->get(route('listens.show', ['listen' => $listen->id, 'q' => 'Song', 'search_source' => 'api']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('apiError', 'MusicBrainz is unavailable or rate-limited. Please try again later.'));
        $this->assertNull($listen->fresh()->recording_mbid);
        Http::assertSentCount(1);
    }

    public function test_api_selection_rejects_tampered_recording_metadata(): void
    {
        Http::preventStrayRequests();
        $listen = $this->importedListen();
        Http::fake(['musicbrainz.org/ws/2/recording/*' => Http::response($this->recording())]);
        $this->post(route('listens.match', $listen->id), [
            'recording_mbid' => '22222222-2222-4222-8222-222222222222',
            'track_name' => 'Fake', 'artist_name' => 'Artist', 'search_source' => 'api',
        ])->assertSessionHasErrors('recording_mbid');
        $this->assertNull($listen->fresh()->recording_mbid);
        Http::assertSentCount(1);
    }

    public function test_api_search_escapes_special_characters_and_preserves_credit_join_phrases(): void
    {
        Http::preventStrayRequests();
        $listen = $this->importedListen();
        $recording = $this->recording();
        $recording['artist-credit'][0]['joinphrase'] = ' & ';
        $recording['artist-credit'][] = ['name' => 'Guest', 'artist' => ['id' => '33333333-3333-4333-8333-333333333333']];
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::response(['count' => 1, 'recordings' => [$recording]])]);

        $this->get(route('listens.show', ['listen' => $listen->id, 'q' => 'Song" OR artist:*', 'artist' => 'A/B', 'search_source' => 'api']))
            ->assertInertia(fn (Assert $page) => $page->where('suggestions.0.artist_name', 'Artist & Guest'));
        Http::assertSent(fn (Request $request) => $request['query'] === 'recording:"Song\\" OR artist\\:\\*" AND artist:"A\\/B"');
    }

    public function test_invalid_api_response_is_reported(): void
    {
        Http::preventStrayRequests();
        $listen = $this->importedListen();
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::response(['count' => 1, 'recordings' => [['id' => 'invalid']]])]);
        $url = route('listens.show', ['listen' => $listen->id, 'q' => 'Song', 'search_source' => 'api']);

        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('apiError', 'MusicBrainz returned invalid recording metadata.'));
        Http::assertSentCount(1);
    }

    public function test_api_waits_for_a_numeric_cache_timestamp(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        $listen = $this->importedListen();
        Cache::put('musicbrainz-api-last-request', (string) microtime(true), 60);
        Http::fake(['musicbrainz.org/ws/2/recording?*' => Http::response(['count' => 0, 'recordings' => []])]);

        $this->get(route('listens.show', ['listen' => $listen->id, 'q' => 'Song', 'search_source' => 'api']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('suggestions', 0));
        Sleep::assertSleptTimes(1);
        Http::assertSentCount(1);
    }

    public function test_artist_and_release_api_search_and_selection_work_without_local_database(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        $listen = $this->importedListen();
        $artist = ['id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Artist', 'disambiguation' => 'a band'];
        $release = ['id' => '33333333-3333-4333-8333-333333333333', 'title' => 'Album',
            'artist-credit' => [
                ['artist' => ['id' => $artist['id'], 'name' => 'Artist'], 'joinphrase' => ' & '],
                ['name' => 'Guest credit', 'artist' => ['id' => '44444444-4444-4444-8444-444444444444', 'name' => 'Guest']],
            ],
            'date' => '2024-01-01', 'country' => 'US'];
        DB::table('music_artists')->insert(['mbid' => $artist['id'], 'name' => null, 'name_source' => null]);
        DB::table('music_releases')->insert(['mbid' => $release['id'], 'name' => 'Unverified album']);
        Http::fake([
            'musicbrainz.org/ws/2/artist?*' => Http::response(['count' => 31, 'artists' => [$artist]]),
            'musicbrainz.org/ws/2/release?*' => Http::response(['count' => 1, 'releases' => [$release]]),
            'musicbrainz.org/ws/2/artist/*' => Http::response($artist),
            'musicbrainz.org/ws/2/release/*' => Http::response($release),
        ]);
        foreach (['artist' => $artist, 'album' => $release] as $type => $result) {
            $args = ['type' => $type, 'listen' => $listen->id];
            $name = $result[$type === 'artist' ? 'name' : 'title'];
            $this->get(route('music-entities.manage', $args + ['q' => $name, 'search_source' => 'api']))
                ->assertOk()->assertInertia(fn (Assert $page) => $page->where('searchSource', 'api')
                ->where('suggestions.0.mbid', $result['id'])->where('suggestions.0.name', $name)
                ->where('suggestions.0.context', $type === 'artist' ? 'a band' : 'Artist & Guest credit · 2024-01-01 · US')
                ->where('hasMore', $type === 'artist'));
            $this->post(route('music-entities.musicbrainz', $args), [
                'mbid' => $result['id'], 'name' => $name, 'search_source' => 'api',
            ])->assertSessionHasNoErrors();
        }
        $this->assertSame([$artist['id']], $listen->fresh()->track_metadata['additional_info']['artist_mbids']);
        $this->assertSame($release['id'], $listen->fresh()->track_metadata['additional_info']['release_mbid']);
        $this->assertDatabaseHas('music_artists', ['mbid' => $artist['id'], 'name' => 'Artist', 'name_source' => 'musicbrainz']);
        $this->assertDatabaseHas('music_releases', ['mbid' => $release['id'], 'name' => 'Album']);
        $this->assertSame(2, DB::table('music_entity_matches')->count());
        $this->post(route('listens.import'), [
            'source' => 'spotify', 'file' => UploadedFile::fake()->createWithContent('history.json', json_encode([[
                'ts' => '2024-01-02T12:00:00Z', 'ms_played' => 60000,
                'master_metadata_track_name' => 'Another song', 'master_metadata_album_artist_name' => 'Artist',
                'master_metadata_album_album_name' => 'Album', 'spotify_track_uri' => 'spotify:track:0123456789ABCDEFGHIJKL',
            ]], JSON_THROW_ON_ERROR)),
        ])->assertSessionHasNoErrors();
        $newListen = Listen::query()->latest('id')->firstOrFail();
        $this->assertSame([$artist['id']], $newListen->track_metadata['additional_info']['artist_mbids']);
        $this->assertSame($release['id'], $newListen->track_metadata['additional_info']['release_mbid']);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://musicbrainz.org/ws/2/artist?')
            && $request['query'] === 'artist:"Artist"' && $request->hasHeader('User-Agent'));
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://musicbrainz.org/ws/2/release?')
            && $request['query'] === 'release:"Album"');
        Http::assertSentCount(4);
    }

    public function test_entity_api_errors_and_tampered_selection_are_reported_without_saving(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        $listen = $this->importedListen();
        Http::fake([
            'musicbrainz.org/ws/2/artist?*' => Http::response([], 503),
            'musicbrainz.org/ws/2/release?*' => Http::response(['count' => 1, 'releases' => [['id' => 'invalid']]]),
            'musicbrainz.org/ws/2/artist/*' => Http::response(['id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Actual']),
            'musicbrainz.org/ws/2/release/*' => Http::response(['id' => '33333333-3333-4333-8333-333333333333', 'title' => 'Actual']),
        ]);
        foreach (['artist', 'album'] as $type) {
            $args = ['type' => $type, 'listen' => $listen->id];
            $this->get(route('music-entities.manage', $args + ['q' => 'Name', 'search_source' => 'api']))
                ->assertInertia(fn (Assert $page) => $page->where('apiError', $type === 'artist'
                    ? 'MusicBrainz is unavailable or rate-limited. Please try again later.'
                    : 'MusicBrainz returned invalid release metadata.'));
            $this->post(route('music-entities.musicbrainz', $args), [
                'mbid' => $type === 'artist' ? '11111111-1111-4111-8111-111111111111' : '33333333-3333-4333-8333-333333333333',
                'name' => 'Tampered', 'search_source' => 'api',
            ])->assertSessionHasErrors('mbid');
        }
        $this->assertDatabaseCount('music_entity_matches', 0);
        Http::assertSentCount(4);
    }

    public function test_entity_search_defaults_to_local_and_api_is_scoped_to_the_owner(): void
    {
        Http::preventStrayRequests();
        $listen = $this->importedListen();
        $args = ['type' => 'artist', 'listen' => $listen->id];
        $this->get(route('music-entities.manage', $args + ['q' => 'Artist']))
            ->assertInertia(fn (Assert $page) => $page->where('searchSource', 'local'));
        $this->actingAs(User::factory()->create())
            ->get(route('music-entities.manage', $args + ['q' => 'Artist', 'search_source' => 'api']))->assertNotFound();
        $this->post(route('music-entities.musicbrainz', $args), [
            'mbid' => '11111111-1111-4111-8111-111111111111', 'name' => 'Artist', 'search_source' => 'api',
        ])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_entity_api_search_escapes_names_and_uses_requested_page(): void
    {
        Http::preventStrayRequests();
        $listen = $this->importedListen();
        Http::fake(['musicbrainz.org/ws/2/release?*' => Http::response([
            'count' => 32, 'releases' => [['id' => '33333333-3333-4333-8333-333333333333', 'title' => 'Album']],
        ])]);
        $args = ['type' => 'album', 'listen' => $listen->id];
        $this->get(route('music-entities.manage', $args + ['search_source' => 'api']))
            ->assertInertia(fn (Assert $page) => $page->has('suggestions', 0));
        Http::assertNothingSent();
        $this->get(route('music-entities.manage', $args + [
            'q' => 'A" OR artist:*', 'page' => 2, 'search_source' => 'api',
        ]))->assertInertia(fn (Assert $page) => $page->where('searchPage', 2)->where('hasMore', true));
        Http::assertSent(fn (Request $request) => $request['query'] === 'release:"A\\" OR artist\\:\\*"'
            && $request['offset'] === 30 && $request['limit'] === 30);
        Http::assertSentCount(1);
    }

    public function test_artist_scan_uses_opt_in_api_only_for_unambiguous_exact_recordings(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        Queue::fake([ScanArtistRecordings::class]);
        $listen = $this->importedListen();
        $this->post(route('listens.import'), [
            'source' => 'spotify', 'file' => UploadedFile::fake()->createWithContent('history.json', json_encode([
                ['ts' => '2024-01-03T12:00:00Z', 'ms_played' => 60000,
                    'master_metadata_track_name' => 'Ambiguous', 'master_metadata_album_artist_name' => 'Artist',
                    'master_metadata_album_album_name' => 'Album', 'spotify_track_uri' => 'spotify:track:0123456789ABCDEFGHIJKL'],
                ['ts' => '2024-01-04T12:00:00Z', 'ms_played' => 60000,
                    'master_metadata_track_name' => 'Wrong release', 'master_metadata_album_artist_name' => 'Artist',
                    'master_metadata_album_album_name' => 'Album', 'spotify_track_uri' => 'spotify:track:0123456789ABCDEFGHIJKL'],
            ], JSON_THROW_ON_ERROR)),
        ])->assertSessionHasNoErrors();
        $recording = $this->recording();
        $recording['releases'] = [['title' => 'Album']];
        $ambiguous = $recording;
        $ambiguous['title'] = 'Ambiguous';
        $second = $ambiguous;
        $second['id'] = '44444444-4444-4444-8444-444444444444';
        $wrong = $recording;
        $wrong['title'] = 'Wrong release';
        $wrong['releases'] = [['title' => 'Different Album']];
        Http::fake([
            'musicbrainz.org/ws/2/artist/*' => Http::response([
                'id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Artist', 'aliases' => [['name' => 'Alias']],
            ]),
            'musicbrainz.org/ws/2/recording?*' => fn (Request $request) => match (true) {
                str_contains($request['query'], 'Ambiguous') => Http::response(['count' => 2, 'recordings' => [$ambiguous, $second]]),
                str_contains($request['query'], 'Wrong release') => Http::response(['count' => 1, 'recordings' => [$wrong]]),
                default => Http::response(['count' => 1, 'recordings' => [$recording]]),
            },
        ]);
        $this->post(route('music-entities.musicbrainz', ['type' => 'artist', 'listen' => $listen->id]), [
            'mbid' => '11111111-1111-4111-8111-111111111111', 'name' => 'Artist', 'search_source' => 'api', 'scan_source' => 'api',
        ])->assertSessionHasNoErrors();
        Queue::assertPushed(ScanArtistRecordings::class, function (ScanArtistRecordings $job): bool {
            app()->call([$job, 'handle']);

            return true;
        });
        $this->assertSame('22222222-2222-4222-8222-222222222222', $listen->fresh()->recording_mbid);
        $this->assertNull(Listen::query()->where('track_name', 'Ambiguous')->sole()->recording_mbid);
        $this->assertNull(Listen::query()->where('track_name', 'Wrong release')->sole()->recording_mbid);
        $this->assertDatabaseHas('artist_recording_scans', ['user_id' => $listen->user_id, 'status' => 'completed', 'processed' => 3, 'matched' => 1]);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/ws/2/recording?')
            && $request['query'] === 'recording:"Song" AND arid:11111111-1111-4111-8111-111111111111 AND release:"Album"');
        Http::assertSentCount(4);
    }

    public function test_failed_and_superseded_artist_scans_are_visible_and_do_not_apply_matches(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        Queue::fake([ScanArtistRecordings::class]);
        $listen = $this->importedListen();
        Http::fake([
            'musicbrainz.org/ws/2/artist/*' => Http::response([
                'id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Artist', 'aliases' => [],
            ]),
            'musicbrainz.org/ws/2/recording?*' => Http::response([], 503),
        ]);
        $args = ['type' => 'artist', 'listen' => $listen->id];
        $select = fn () => $this->post(route('music-entities.musicbrainz', $args), [
            'mbid' => '11111111-1111-4111-8111-111111111111', 'name' => 'Artist', 'search_source' => 'api', 'scan_source' => 'api',
        ])->assertSessionHasNoErrors();
        $select();
        $firstId = DB::table('artist_recording_scans')->value('id');
        $select();
        $this->assertDatabaseHas('artist_recording_scans', ['id' => $firstId, 'status' => 'cancelled']);
        app()->call([new ScanArtistRecordings($firstId), 'handle']);
        $secondId = DB::table('artist_recording_scans')->orderByDesc('id')->value('id');
        $job = new ScanArtistRecordings($secondId);
        try {
            app()->call([$job, 'handle']);
            $this->fail('Expected the MusicBrainz API failure.');
        } catch (ValidationException $exception) {
            $job->failed($exception);
        }
        $this->get(route('music-entities.manage', $args))->assertInertia(fn (Assert $page) => $page
            ->where('artistScan.status', 'failed')->where('artistScan.error', fn ($error) => str_contains($error, 'failed')));
        $this->assertNull($listen->fresh()->recording_mbid);
        Http::assertSentCount(3);
    }
}
