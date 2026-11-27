<?php

namespace Tests\Feature;

use App\Jobs\ScanArtistRecordings;
use App\Models\Listen;
use App\Models\User;
use App\Services\ManualMusicEntityMatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MusicEntityTest extends TestCase
{
    use RefreshDatabase;

    private const ARTIST = '11111111-1111-4111-8111-111111111111';

    private const OTHER_ARTIST = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const ALBUM = '33333333-3333-4333-8333-333333333333';

    private function listen(User $user, string $artist = 'Artist', string $album = 'Album'): Listen
    {
        return Listen::create([
            'user_id' => $user->id, 'source' => 'spotify', 'fingerprint' => hash('sha256', uniqid('', true)),
            'listened_at' => 1700000000, 'track_name' => 'Track', 'artist_name' => $artist,
            'release_name' => $album, 'played_ms' => 60000,
            'track_metadata' => ['track_name' => 'Track', 'artist_name' => $artist, 'additional_info' => []],
        ]);
    }

    private function database(string $format = 'full'): void
    {
        config(['musicbrainz.enabled' => true, 'musicbrainz.format' => $format, 'database.connections.musicbrainz' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('musicbrainz');
        $schema = Schema::connection('musicbrainz');
        if ($format === 'canonical') {
            $schema->create('canonical_recordings', function (Blueprint $table) {
                $table->string('artist_mbids');
                $table->string('artist_credit_name');
                $table->string('release_mbid');
                $table->string('release_name');
                $table->string('recording_mbid');
                $table->string('recording_name');
            });
            DB::connection('musicbrainz')->table('canonical_recordings')->insert([
                ['artist_mbids' => self::ARTIST, 'artist_credit_name' => 'Artist', 'release_mbid' => self::ALBUM, 'release_name' => 'Album',
                    'recording_mbid' => '22222222-2222-4222-8222-222222222222', 'recording_name' => 'Track'],
                ['artist_mbids' => self::ARTIST.','.self::OTHER_ARTIST, 'artist_credit_name' => 'Artist & Guest', 'release_mbid' => self::ALBUM, 'release_name' => 'Album',
                    'recording_mbid' => '22222222-2222-4222-8222-222222222222', 'recording_name' => 'Track'],
            ]);

            return;
        }
        $schema->create('artist', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('gid');
            $table->string('name');
        });
        $schema->create('artist_credit', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('name');
        });
        $schema->create('release', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('gid');
            $table->string('name');
            $table->integer('artist_credit');
        });
        $db = DB::connection('musicbrainz');
        $db->table('artist')->insert([
            ['id' => 1, 'gid' => self::ARTIST, 'name' => 'Artist'],
            ['id' => 2, 'gid' => self::OTHER_ARTIST, 'name' => 'Other'],
        ]);
        $db->table('artist_credit')->insert(['id' => 1, 'name' => 'Artist']);
        $db->table('release')->insert(['id' => 1, 'gid' => self::ALBUM, 'name' => 'Album', 'artist_credit' => 1]);
    }

    public function test_pages_require_authentication_and_do_not_expose_other_users_listens(): void
    {
        $other = $this->listen(User::factory()->create());
        $args = ['type' => 'artist', 'listen' => $other->id];
        $this->get(route('artists.index'))->assertRedirect(route('login'));
        $this->get(route('albums.index'))->assertRedirect(route('login'));
        $this->get(route('music-entities.manage', $args))->assertRedirect(route('login'));
        $this->post(route('music-entities.catalog', $args))->assertRedirect(route('login'));
        $this->post(route('music-entities.musicbrainz', $args))->assertRedirect(route('login'));
        $this->delete(route('music-entities.remove', $args + ['kind' => 'musicbrainz']))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create());
        $this->get(route('music-entities.manage', $args))->assertNotFound();
        $this->post(route('music-entities.catalog', $args), [])->assertNotFound();
        $this->post(route('music-entities.musicbrainz', $args), [])->assertNotFound();
        $this->delete(route('music-entities.remove', $args + ['kind' => 'musicbrainz']))->assertNotFound();
        $this->get(route('music-entities.manage', ['type' => 'invalid', 'listen' => $other->id]))->assertNotFound();
    }

    public function test_listing_groups_exact_names_by_user_and_distinguishes_partial_matches(): void
    {
        config(['musicbrainz.enabled' => false]);
        $user = User::factory()->create();
        $first = $this->listen($user);
        $this->listen($user);
        $this->listen($user, 'Other', 'Album');
        $this->listen(User::factory()->create(), 'Secret', 'Album');
        $id = DB::table('music_artists')->insertGetId(['mbid' => self::ARTIST, 'name' => 'Artist']);
        DB::table('listen_artists')->insert(['listen_id' => $first->id, 'music_artist_id' => $id]);

        $this->actingAs($user)->get(route('artists.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('music-entities/index')
                ->where('entities.total', 2)->where('entities.data.0.artist_name', 'Artist')
                ->where('entities.data.0.listen_count', 2)->where('entities.data.0.matched_count', 1));
        $this->get(route('artists.index', ['match' => 'matched']))->assertInertia(fn (Assert $page) => $page->where('entities.total', 1));
        $this->get(route('artists.index', ['match' => 'unmatched']))->assertInertia(fn (Assert $page) => $page->where('entities.total', 2));
        $this->get(route('albums.index', ['search' => 'Album']))->assertInertia(fn (Assert $page) => $page->where('entities.total', 2));
        $this->get(route('artists.index', ['search' => '%']))->assertInertia(fn (Assert $page) => $page->where('entities.total', 0));
    }

    public function test_manual_matches_replace_existing_associations_and_apply_to_future_and_reimported_listens(): void
    {
        $this->database();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $first = $this->listen($user);
        $second = $this->listen($user);
        $sameAlbumOtherArtist = $this->listen($user, 'Other', 'Album');
        $differentCase = $this->listen($user, 'artist', 'Album');
        $differentAlbumCase = $this->listen($user, 'Artist', 'album');
        $otherListen = $this->listen($other);
        $args = ['type' => 'artist', 'listen' => $first->id];
        $old = DB::table('music_artists')->insertGetId(['mbid' => self::OTHER_ARTIST, 'name' => 'Other']);
        DB::table('listen_artists')->insert(['listen_id' => $first->id, 'music_artist_id' => $old]);

        $this->actingAs($user)->get(route('music-entities.manage', $args + ['q' => 'Art']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('suggestions.0.mbid', self::ARTIST));
        $this->post(route('music-entities.musicbrainz', $args), ['mbid' => self::ARTIST, 'name' => 'Wrong'])
            ->assertSessionHasErrors('mbid');
        $this->post(route('music-entities.musicbrainz', $args), ['mbid' => self::ARTIST, 'name' => 'Artist'])
            ->assertSessionHasNoErrors();
        $this->post(route('music-entities.musicbrainz', ['type' => 'album', 'listen' => $first->id]), ['mbid' => self::ALBUM, 'name' => 'Album'])
            ->assertSessionHasNoErrors();
        foreach ([$first, $second] as $listen) {
            $this->assertSame([self::ARTIST], $listen->fresh()->track_metadata['additional_info']['artist_mbids']);
            $this->assertSame(self::ALBUM, $listen->fresh()->track_metadata['additional_info']['release_mbid']);
            $this->assertSame(1, DB::table('listen_artists')->where('listen_id', $listen->id)->count());
        }
        $this->assertDatabaseMissing('listen_artists', ['listen_id' => $first->id, 'music_artist_id' => $old]);
        $this->assertDatabaseMissing('listen_releases', ['listen_id' => $sameAlbumOtherArtist->id]);
        $this->assertDatabaseMissing('listen_releases', ['listen_id' => $differentAlbumCase->id]);
        $this->assertDatabaseMissing('listen_artists', ['listen_id' => $differentCase->id]);
        $this->assertDatabaseMissing('listen_artists', ['listen_id' => $otherListen->id]);
        $this->post(route('music-entities.musicbrainz', $args), ['mbid' => self::OTHER_ARTIST, 'name' => 'Other'])
            ->assertSessionHasNoErrors();
        $this->assertSame([self::OTHER_ARTIST], $second->fresh()->track_metadata['additional_info']['artist_mbids']);
        $this->post(route('music-entities.musicbrainz', $args), ['mbid' => self::ARTIST, 'name' => 'Artist'])
            ->assertSessionHasNoErrors();

        config(['musicbrainz.enabled' => false]);
        $row = ['ts' => '2024-01-01T12:00:00Z', 'ms_played' => 60000, 'master_metadata_track_name' => 'New track',
            'master_metadata_album_artist_name' => 'Artist', 'master_metadata_album_album_name' => 'Album'];
        $file = fn () => UploadedFile::fake()->createWithContent('history.json', json_encode([$row], JSON_THROW_ON_ERROR));
        $this->post(route('listens.import'), ['source' => 'spotify', 'file' => $file()])->assertSessionHasNoErrors();
        $new = Listen::query()->where('user_id', $user->id)->where('track_name', 'New track')->sole();
        $this->assertSame([self::ARTIST], $new->track_metadata['additional_info']['artist_mbids']);
        $this->assertSame(self::ALBUM, $new->track_metadata['additional_info']['release_mbid']);
        $this->post(route('listens.import'), ['source' => 'spotify', 'file' => $file()])->assertSessionHas('import_result', ['imported' => 0, 'duplicates' => 1, 'matched' => 0]);
        $this->assertSame([self::ARTIST], $new->fresh()->track_metadata['additional_info']['artist_mbids']);
        $this->delete(route('music-entities.remove', $args + ['kind' => 'musicbrainz']))->assertSessionHasNoErrors();
        $this->assertSame([self::ARTIST], $first->fresh()->track_metadata['additional_info']['artist_mbids']);
        $this->get(route('music-entities.manage', $args))->assertInertia(fn (Assert $page) => $page->where('saved', null));
        $row['ts'] = '2024-01-02T12:00:00Z';
        $row['master_metadata_track_name'] = 'Another track';
        $this->post(route('listens.import'), ['source' => 'spotify', 'file' => UploadedFile::fake()->createWithContent('history.json', json_encode([$row], JSON_THROW_ON_ERROR))])
            ->assertSessionHasNoErrors();
        $later = Listen::query()->where('user_id', $user->id)->where('track_name', 'Another track')->sole();
        $this->assertArrayNotHasKey('artist_mbids', $later->track_metadata['additional_info']);
        $this->assertSame(self::ALBUM, $later->track_metadata['additional_info']['release_mbid']);
    }

    public function test_artist_details_offer_only_the_owners_exact_imported_credit_groups_for_rematching(): void
    {
        config(['musicbrainz.enabled' => false]);
        $user = User::factory()->create();
        $first = $this->listen($user);
        $second = $this->listen($user);
        $caseVariant = $this->listen($user, 'artist');
        $foreign = $this->listen(User::factory()->create(), 'Private artist');
        $artistId = DB::table('music_artists')->insertGetId(['mbid' => self::ARTIST, 'name' => 'Artist']);
        foreach ([$first, $second, $caseVariant, $foreign] as $listen) {
            DB::table('listen_artists')->insert(['listen_id' => $listen->id, 'music_artist_id' => $artistId]);
        }

        $this->actingAs($user)->get(route('artists.show', self::ARTIST))
            ->assertInertia(fn (Assert $page) => $page->where('artistGroups.total', 2)
                ->where('artistGroups.data.0.artist_name', 'Artist')->where('artistGroups.data.0.listen_count', 2)
                ->where('artistGroups.data.0.listen_id', $first->id)->where('artistGroups.data.1.artist_name', 'artist'));
        $this->get(route('music-entities.manage', ['type' => 'artist', 'listen' => $first->id, 'rematch' => 1]))
            ->assertInertia(fn (Assert $page) => $page->where('rematching', true)
                ->where('listens.total', 2)->where('artistSnapshot', fn ($value) => is_string($value) && strlen($value) === 64));
    }

    public function test_artist_recording_rematching_requires_a_fresh_artist_snapshot(): void
    {
        $this->database();
        $user = User::factory()->create();
        $listen = $this->listen($user);
        $args = ['type' => 'artist', 'listen' => $listen->id];
        $manual = app(ManualMusicEntityMatcher::class);
        $snapshot = $manual->artistSnapshot($listen);
        $this->actingAs($user)->post(route('music-entities.musicbrainz', $args), [
            'mbid' => self::ARTIST, 'name' => 'Artist', 'rematch_existing' => 1,
        ])->assertSessionHasErrors('expected_artist_snapshot');
        $this->post(route('music-entities.musicbrainz', $args), [
            'mbid' => self::OTHER_ARTIST, 'name' => 'Other',
        ])->assertSessionHasNoErrors();
        $this->post(route('music-entities.musicbrainz', $args), [
            'mbid' => self::ARTIST, 'name' => 'Artist', 'rematch_existing' => 1, 'expected_artist_snapshot' => $snapshot,
        ])->assertSessionHasErrors('mbid');

        $this->assertSame([self::OTHER_ARTIST], $listen->fresh()->track_metadata['additional_info']['artist_mbids']);
        $this->assertDatabaseCount('artist_recording_scans', 1);
        $this->assertDatabaseMissing('artist_recording_scans', ['rematch_existing' => true]);
        $this->post(route('music-entities.musicbrainz', ['type' => 'album', 'listen' => $listen->id]), [
            'mbid' => self::ALBUM, 'name' => 'Album', 'rematch_existing' => 1,
        ])->assertSessionHasErrors('rematch_existing');
    }

    public function test_canonical_artist_rematching_applies_only_the_latest_confirmed_artist_scan(): void
    {
        Http::preventStrayRequests();
        $this->database('canonical');
        $newRecording = '44444444-4444-4444-8444-444444444444';
        DB::connection('musicbrainz')->table('canonical_recordings')->insert([
            'artist_mbids' => self::OTHER_ARTIST, 'artist_credit_name' => 'Other',
            'release_mbid' => self::ALBUM, 'release_name' => 'Album', 'recording_name' => 'Track', 'recording_mbid' => $newRecording,
        ]);
        $listen = $this->listen(User::factory()->create());
        $old = '22222222-2222-4222-8222-222222222222';
        $listen->update(['recording_mbid' => $old]);
        $this->actingAs(User::query()->findOrFail($listen->user_id));
        $args = ['type' => 'artist', 'listen' => $listen->id];
        foreach ([self::ARTIST => 'Artist', self::OTHER_ARTIST => 'Other'] as $mbid => $name) {
            $this->post(route('music-entities.musicbrainz', $args), [
                'mbid' => $mbid, 'name' => $name, 'rematch_existing' => 1,
                'expected_artist_snapshot' => app(ManualMusicEntityMatcher::class)->artistSnapshot($listen),
            ])->assertSessionHasNoErrors();
        }
        $scans = DB::table('artist_recording_scans')->orderBy('id')->get();
        $this->assertSame('cancelled', $scans[0]->status);
        app()->call([new ScanArtistRecordings($scans[0]->id), 'handle']);
        $this->assertSame($old, $listen->fresh()->recording_mbid);
        app()->call([new ScanArtistRecordings($scans[1]->id), 'handle']);

        $this->assertSame($newRecording, $listen->fresh()->recording_mbid);
        $this->assertSame([self::OTHER_ARTIST], $listen->fresh()->track_metadata['additional_info']['artist_mbids']);
        $this->assertDatabaseHas('artist_recording_scans', ['id' => $scans[1]->id, 'status' => 'completed', 'matched' => 1]);
        Http::assertNothingSent();
    }

    public function test_catalog_links_are_validated_saved_per_group_and_can_be_replaced(): void
    {
        config(['musicbrainz.enabled' => false]);
        $user = User::factory()->create();
        $listen = $this->listen($user);
        $args = ['type' => 'album', 'listen' => $listen->id];
        $this->actingAs($user);
        $this->post(route('music-entities.catalog', $args), ['provider' => 'apple', 'catalog_url' => 'https://music.apple.com/us/album/album/123?i=456'])
            ->assertSessionHasErrors('catalog_url');
        $this->post(route('music-entities.catalog', $args), ['provider' => 'spotify', 'catalog_url' => 'https://evil.example/album/1234567890123456789012'])
            ->assertSessionHasErrors('catalog_url');
        $this->post(route('music-entities.catalog', $args), ['provider' => 'spotify', 'catalog_url' => 'spotify:album:1234567890123456789012'])
            ->assertSessionHasNoErrors();
        $this->post(route('music-entities.catalog', $args), ['provider' => 'apple', 'catalog_url' => 'https://music.apple.com/us/album/album/123'])
            ->assertSessionHasNoErrors();
        $this->post(route('music-entities.catalog', $args), ['provider' => 'apple', 'catalog_url' => 'https://music.apple.com/us/album/album/456'])
            ->assertSessionHasNoErrors();
        $this->get(route('music-entities.manage', $args))->assertInertia(fn (Assert $page) => $page
            ->where('saved.catalogs.spotify', '1234567890123456789012')->where('saved.catalogs.apple', '456'));
        $this->delete(route('music-entities.remove', $args + ['kind' => 'apple']))->assertSessionHasNoErrors();
        $this->get(route('music-entities.manage', $args))->assertInertia(fn (Assert $page) => $page
            ->where('saved.catalogs.spotify', '1234567890123456789012')->missing('saved.catalogs.apple'));
        $unknown = $this->listen($user, 'Unknown artist');
        $this->post(route('music-entities.catalog', ['type' => 'artist', 'listen' => $unknown->id]), [
            'provider' => 'spotify', 'catalog_url' => 'spotify:artist:1234567890123456789012',
        ])->assertSessionHasErrors('match');
    }

    public function test_canonical_and_full_search_expose_safe_entity_candidates(): void
    {
        $user = User::factory()->create();
        $listen = $this->listen($user);
        $this->actingAs($user);
        $this->database('canonical');
        $this->get(route('music-entities.manage', ['type' => 'artist', 'listen' => $listen->id, 'q' => 'Artist']))
            ->assertInertia(fn (Assert $page) => $page->has('suggestions', 1)->where('suggestions.0.mbid', self::ARTIST));
        $this->get(route('music-entities.manage', ['type' => 'album', 'listen' => $listen->id, 'q' => 'Album']))
            ->assertInertia(fn (Assert $page) => $page->has('suggestions', 2)->where('suggestions.0.context', 'Artist'));
        $this->post(route('music-entities.musicbrainz', ['type' => 'artist', 'listen' => $listen->id]), ['mbid' => self::ARTIST, 'name' => 'Artist'])
            ->assertSessionHasNoErrors();
        $this->database();
        $this->get(route('music-entities.manage', ['type' => 'album', 'listen' => $listen->id, 'q' => 'Album']))
            ->assertInertia(fn (Assert $page) => $page->where('suggestions.0.mbid', self::ALBUM)->where('suggestions.0.context', 'Artist'));
    }

    public function test_artist_and_release_editors_are_seeded_with_unverified_names_and_user_catalog_links(): void
    {
        config(['musicbrainz.enabled' => false]);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $listen = $this->listen($user, 'Artist & Guest', 'Album: Live');
        $other = $this->listen($otherUser, 'Artist & Guest', 'Album: Live');
        $this->actingAs($user);
        $this->post(route('music-entities.catalog', ['type' => 'artist', 'listen' => $listen->id]), [
            'provider' => 'spotify', 'catalog_url' => 'spotify:artist:1234567890123456789012',
        ])->assertSessionHasNoErrors();
        $this->post(route('music-entities.catalog', ['type' => 'album', 'listen' => $listen->id]), [
            'provider' => 'apple', 'catalog_url' => 'https://music.apple.com/us/album/album/123',
        ])->assertSessionHasNoErrors();
        $this->actingAs($otherUser)
            ->post(route('music-entities.catalog', ['type' => 'album', 'listen' => $other->id]), [
                'provider' => 'apple', 'catalog_url' => 'https://music.apple.com/us/album/album/456',
            ])->assertSessionHasNoErrors();

        $this->actingAs($user)->get(route('music-entities.manage', ['type' => 'artist', 'listen' => $listen->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('musicBrainzSeed.action', 'https://musicbrainz.org/artist/create')
            ->where('musicBrainzSeed.method', 'get')
            ->where('musicBrainzSeed.fields', fn ($fields) => $fields['edit-artist.name'] === 'Artist & Guest'
                && str_contains($fields['edit-artist.edit_note'], 'https://open.spotify.com/artist/1234567890123456789012')
                && ! isset($fields['edit-artist.sort_name'])));
        $this->get(route('music-entities.manage', ['type' => 'album', 'listen' => $listen->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('musicBrainzSeed.action', 'https://musicbrainz.org/release/add')
            ->where('musicBrainzSeed.method', 'post')
            ->where('musicBrainzSeed.fields', fn ($fields) => $fields['name'] === 'Album: Live'
                && $fields['artist_credit.names.0.name'] === 'Artist & Guest'
                && $fields['artist_credit.names.0.artist.name'] === 'Artist & Guest'
                && str_contains($fields['edit_note'], 'https://music.apple.com/album/123')
                && ! str_contains($fields['edit_note'], 'album/456')
                && ! isset($fields['type']) && ! isset($fields['status'])));
        Http::assertNothingSent();
    }

    public function test_editors_do_not_seed_unknown_artist_names_or_empty_album_names(): void
    {
        config(['musicbrainz.enabled' => false]);
        $user = User::factory()->create();
        $listen = $this->listen($user, 'Unknown artist');
        $emptyAlbum = $this->listen($user, 'Artist', ' ');
        $this->actingAs($user)
            ->get(route('music-entities.manage', ['type' => 'artist', 'listen' => $listen->id]))
            ->assertInertia(fn (Assert $page) => $page->where('musicBrainzSeed', null));
        $this->get(route('music-entities.manage', ['type' => 'album', 'listen' => $listen->id]))
            ->assertInertia(fn (Assert $page) => $page->where('musicBrainzSeed.fields', fn ($fields) => $fields['name'] === 'Album'
                && ! isset($fields['artist_credit.names.0.name'])
                && ! str_contains($fields['edit_note'], 'Unknown artist')));
        $this->get(route('music-entities.manage', ['type' => 'album', 'listen' => $emptyAlbum->id]))->assertNotFound();
    }
}
