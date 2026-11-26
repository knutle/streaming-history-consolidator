<?php

namespace Tests\Feature;

use App\Models\Listen;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogMatchingTest extends TestCase
{
    use RefreshDatabase;

    private function import(string $date, string $album = 'Album'): void
    {
        $rows = [[
            'ts' => $date,
            'ms_played' => 60000,
            'master_metadata_track_name' => 'Track',
            'master_metadata_album_artist_name' => 'Artist',
            'master_metadata_album_album_name' => $album,
            'spotify_track_uri' => 'spotify:track:0123456789ABCDEFGHIJKL',
        ]];
        $this->post(route('listens.import'), [
            'source' => 'spotify',
            'file' => UploadedFile::fake()->createWithContent('history.json', json_encode($rows, JSON_THROW_ON_ERROR)),
        ])->assertSessionHasNoErrors();
    }

    private function appleMatch(): array
    {
        return [
            'provider' => 'apple',
            'catalog_url' => 'https://music.apple.com/us/album/example/123?i=987',
            'catalog_track' => 'Catalog track',
            'catalog_artist' => 'Catalog artist',
            'catalog_album' => 'Catalog album',
            'catalog_duration' => 181000,
        ];
    }

    public function test_import_uses_source_identifiers_without_external_requests_and_reimports_enrich_old_listens(): void
    {
        config(['musicbrainz.enabled' => false]);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
        $this->import('2024-01-01T12:00:00Z');
        $listen = Listen::query()->sole();
        $spotify = $listen->track_metadata['additional_info']['catalogs']['spotify'];
        $this->assertSame('0123456789ABCDEFGHIJKL', $spotify['id']);
        $this->assertSame('import', $spotify['origin']);

        $metadata = $listen->track_metadata;
        unset($metadata['additional_info']['catalogs']);
        $listen->update(['track_metadata' => $metadata]);
        $this->import('2024-01-01T12:00:00Z');
        $this->assertSame('0123456789ABCDEFGHIJKL', $listen->fresh()->track_metadata['additional_info']['catalogs']['spotify']['id']);
        $this->assertDatabaseCount('listens', 1);
        Http::assertNothingSent();
    }

    public function test_manual_matches_apply_to_same_album_and_future_imports_only_for_owner(): void
    {
        config(['musicbrainz.enabled' => false]);
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user);
        $this->import('2024-01-01T12:00:00Z');
        $this->import('2024-01-02T12:00:00Z');
        $this->import('2024-01-03T12:00:00Z', 'Different album');
        $listen = Listen::query()->where('user_id', $user->id)->firstOrFail();
        $this->actingAs($other);
        $this->import('2024-01-01T12:00:00Z');

        $this->actingAs($user)->post(route('listens.catalog', $listen->id), $this->appleMatch())
            ->assertRedirect(route('listens.show', $listen->id))->assertSessionHas('catalog_result', 2);

        $own = Listen::query()->where('user_id', $user->id)->where('release_name', 'Album')->get();
        $this->assertSame('987', $own[0]->track_metadata['additional_info']['catalogs']['apple']['id']);
        $this->assertSame('987', $own[1]->track_metadata['additional_info']['catalogs']['apple']['id']);
        $this->assertArrayNotHasKey('apple', Listen::query()->where('release_name', 'Different album')->sole()->track_metadata['additional_info']['catalogs']);
        $this->assertArrayNotHasKey('apple', Listen::query()->where('user_id', $other->id)->sole()->track_metadata['additional_info']['catalogs']);
        $this->import('2024-01-04T12:00:00Z');
        $this->assertSame('987', Listen::query()->where('user_id', $user->id)->latest('id')->firstOrFail()->track_metadata['additional_info']['catalogs']['apple']['id']);
        $this->delete(route('listens.destroy'))->assertRedirect(route('listens.create'));
        $this->import('2024-01-05T12:00:00Z');
        $this->assertSame('987', Listen::query()->where('user_id', $user->id)->sole()->track_metadata['additional_info']['catalogs']['apple']['id']);
    }

    public function test_manual_match_rejects_other_users_listens_and_invalid_track_links(): void
    {
        config(['musicbrainz.enabled' => false]);
        $this->post(route('listens.catalog', 1), $this->appleMatch())->assertRedirect(route('login'));
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $this->import('2024-01-01T12:00:00Z');
        $listen = Listen::query()->sole();
        $this->actingAs(User::factory()->create())->post(route('listens.catalog', $listen->id), $this->appleMatch())->assertNotFound();
        $this->actingAs($owner)->post(route('listens.catalog', $listen->id), [
            ...$this->appleMatch(), 'catalog_url' => 'https://music.apple.com.attacker.test/us/song/123',
        ])->assertSessionHasErrors('catalog_url');
        $this->post(route('listens.catalog', $listen->id), [
            ...$this->appleMatch(), 'provider' => 'spotify',
        ])->assertSessionHasErrors('catalog_url');
        $this->assertDatabaseCount('catalog_matches', 0);
    }

    public function test_saved_catalog_choices_can_be_reviewed_and_removed_without_altering_existing_listens(): void
    {
        config(['musicbrainz.enabled' => false]);
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->import('2024-01-01T12:00:00Z');
        $listen = Listen::query()->sole();
        $this->post(route('listens.catalog', $listen->id), $this->appleMatch())->assertSessionHasNoErrors();
        $id = DB::table('catalog_matches')->value('id');
        $this->get(route('matches.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('catalogs.data', 1)->where('catalogs.data.0.source_track', 'Track')
            ->where('catalogs.data.0.match.track_name', 'Catalog track'));
        $this->actingAs(User::factory()->create())
            ->delete(route('matches.destroy', ['type' => 'catalog', 'match' => $id]))->assertNotFound();
        $this->actingAs($user)->delete(route('matches.destroy', ['type' => 'catalog', 'match' => $id]))
            ->assertRedirect(route('matches.index'));
        $this->assertSame('987', $listen->fresh()->track_metadata['additional_info']['catalogs']['apple']['id']);
        $this->import('2024-01-02T12:00:00Z');
        $this->assertArrayNotHasKey('apple', Listen::query()->latest('id')->firstOrFail()->track_metadata['additional_info']['catalogs']);
    }

    public function test_incomplete_song_identity_does_not_propagate_manual_matches(): void
    {
        config(['musicbrainz.enabled' => false]);
        $this->actingAs(User::factory()->create());
        $this->import('2024-01-01T12:00:00Z', '');
        $this->import('2024-01-02T12:00:00Z', '');
        $listen = Listen::query()->oldest('id')->firstOrFail();

        $this->post(route('listens.catalog', $listen->id), $this->appleMatch())
            ->assertSessionHas('catalog_result', 1);
        $this->assertSame('987', $listen->fresh()->track_metadata['additional_info']['catalogs']['apple']['id']);
        $this->assertArrayNotHasKey('apple', Listen::query()->latest('id')->firstOrFail()->track_metadata['additional_info']['catalogs']);
        $this->assertDatabaseCount('catalog_matches', 0);
    }

    public function test_spotify_manual_match_accepts_track_uri_and_preserves_imported_ids_on_other_listens(): void
    {
        config(['musicbrainz.enabled' => false]);
        $this->actingAs(User::factory()->create());
        $this->import('2024-01-01T12:00:00Z');
        $this->import('2024-01-02T12:00:00Z');
        $listen = Listen::query()->oldest('id')->firstOrFail();
        $this->post(route('listens.catalog', $listen->id), [
            ...$this->appleMatch(),
            'provider' => 'spotify',
            'catalog_url' => 'spotify:track:ABCDEFGHIJKLMNOPQRSTUV',
        ])->assertSessionHas('catalog_result', 1);

        $this->assertSame('ABCDEFGHIJKLMNOPQRSTUV', $listen->fresh()->track_metadata['additional_info']['catalogs']['spotify']['id']);
        $this->assertSame('0123456789ABCDEFGHIJKL', Listen::query()->latest('id')->firstOrFail()->track_metadata['additional_info']['catalogs']['spotify']['id']);
    }

    public function test_musicbrainz_editor_is_seeded_from_catalog_metadata_for_review(): void
    {
        config(['musicbrainz.enabled' => false]);
        $this->actingAs(User::factory()->create());
        $this->import('2024-01-01T12:00:00Z');
        $listen = Listen::query()->sole();
        $this->post(route('listens.catalog', $listen->id), [
            ...$this->appleMatch(), 'catalog_isrc' => 'USABC2412345',
        ])->assertSessionHasNoErrors();
        $this->get(route('listens.show', $listen->id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('catalogs.apple.id', '987')
            ->where('musicBrainzSeed', function ($url) {
                $parts = parse_url($url);
                parse_str($parts['query'], $query);

                return $parts['host'] === 'musicbrainz.org'
                    && $parts['path'] === '/recording/create'
                    && ! isset($query['edit-recording_name'])
                    && ! isset($query['edit-recording_artist_credit_names_0_name'])
                    && $query['edit-recording_length'] === '3:01'
                    && str_contains($query['edit-recording_edit_note'], 'https://music.apple.com/us/song/987')
                    && str_contains($query['edit-recording_edit_note'], "Track: Catalog track\nArtist: Catalog artist\nAlbum: Catalog album\nISRC: USABC2412345")
                    && str_contains($query['edit-recording_edit_note'], 'https://open.spotify.com/track/0123456789ABCDEFGHIJKL');
            }));
    }

    public function test_musicbrainz_editor_leaves_ambiguous_titles_and_artist_credits_for_manual_review(): void
    {
        config(['musicbrainz.enabled' => false]);
        $this->actingAs(User::factory()->create());
        $this->import('2024-01-01T12:00:00Z');
        $listen = Listen::query()->sole();
        $this->post(route('listens.catalog', $listen->id), [
            ...$this->appleMatch(),
            'catalog_track' => 'SONG (feat. Guest) - Live at Oslo',
            'catalog_artist' => 'Artist & Guest',
            'catalog_duration' => 181999,
        ])->assertSessionHasNoErrors();

        $this->get(route('listens.show', $listen->id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('musicBrainzSeed', function ($url) {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);

                return ! isset($query['edit-recording_name'])
                    && ! isset($query['edit-recording_artist_credit_names_0_name'])
                    && ! isset($query['edit-recording_comment'])
                    && $query['edit-recording_length'] === '3:02'
                    && str_contains($query['edit-recording_edit_note'], 'Track: SONG (feat. Guest) - Live at Oslo')
                    && str_contains($query['edit-recording_edit_note'], 'Artist: Artist & Guest')
                    && str_contains($query['edit-recording_edit_note'], 'Duration (ms): 181999')
                    && str_contains($query['edit-recording_edit_note'], 'metadata may include user edits');
            }));
    }

    public function test_musicbrainz_editor_prefills_name_and_preferred_artist_when_catalog_titles_match(): void
    {
        config(['musicbrainz.enabled' => false]);
        $this->actingAs(User::factory()->create());
        $this->import('2024-01-01T12:00:00Z');
        $listen = Listen::query()->sole();
        $this->post(route('listens.catalog', $listen->id), [
            ...$this->appleMatch(), 'catalog_track' => 'Track',
        ])->assertSessionHasNoErrors();

        $this->get(route('listens.show', $listen->id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('musicBrainzSeed', function ($url) {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);

                return $query['edit-recording_name'] === 'Track'
                    && $query['edit-recording_artist_credit_names_0_name'] === 'Catalog artist'
                    && $query['edit-recording_artist_credit_names_0_artist_name'] === 'Catalog artist'
                    && str_contains($query['edit-recording_edit_note'], 'Artist: Artist');
            }));
    }

    public function test_musicbrainz_editor_prefills_from_a_single_catalog(): void
    {
        config(['musicbrainz.enabled' => false]);
        $this->actingAs(User::factory()->create());
        $this->import('2024-01-01T12:00:00Z');
        $listen = Listen::query()->sole();

        $this->get(route('listens.show', $listen->id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('musicBrainzSeed', function ($url) {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);

                return $query['edit-recording_name'] === 'Track'
                    && $query['edit-recording_artist_credit_names_0_name'] === 'Artist'
                    && $query['edit-recording_artist_credit_names_0_artist_name'] === 'Artist';
            }));
    }

    public function test_musicbrainz_editor_uses_known_artist_when_preferred_catalog_artist_is_unknown(): void
    {
        config(['musicbrainz.enabled' => false]);
        $this->actingAs(User::factory()->create());
        $this->import('2024-01-01T12:00:00Z');
        $listen = Listen::query()->sole();
        $this->post(route('listens.catalog', $listen->id), [
            ...$this->appleMatch(), 'catalog_track' => 'Track', 'catalog_artist' => 'Unknown artist',
        ])->assertSessionHasNoErrors();

        $this->get(route('listens.show', $listen->id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('musicBrainzSeed', function ($url) {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);

                return $query['edit-recording_name'] === 'Track'
                    && $query['edit-recording_artist_credit_names_0_name'] === 'Artist';
            }));
    }

    public function test_musicbrainz_editor_does_not_prefill_when_catalog_titles_differ_in_case(): void
    {
        config(['musicbrainz.enabled' => false]);
        $this->actingAs(User::factory()->create());
        $this->import('2024-01-01T12:00:00Z');
        $listen = Listen::query()->sole();
        $this->post(route('listens.catalog', $listen->id), [
            ...$this->appleMatch(), 'catalog_track' => 'track',
        ])->assertSessionHasNoErrors();

        $this->get(route('listens.show', $listen->id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('musicBrainzSeed', function ($url) {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);

                return ! isset($query['edit-recording_name'])
                    && ! isset($query['edit-recording_artist_credit_names_0_name']);
            }));
    }

    public function test_musicbrainz_editor_does_not_prefill_a_blank_catalog_title(): void
    {
        config(['musicbrainz.enabled' => false]);
        $this->actingAs(User::factory()->create());
        $this->import('2024-01-01T12:00:00Z');
        $listen = Listen::query()->sole();
        $metadata = $listen->track_metadata;
        $metadata['additional_info']['catalogs']['spotify']['id'] = 'ABCDEFGHIJKLMNOPQRSTUV';
        $metadata['additional_info']['catalogs']['spotify']['track_name'] = ' ';
        $listen->update(['track_metadata' => $metadata]);

        $this->get(route('listens.show', $listen->id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('musicBrainzSeed', function ($url) {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);

                return ! isset($query['edit-recording_name'])
                    && ! isset($query['edit-recording_artist_credit_names_0_name']);
            }));
    }

    public function test_musicbrainz_editor_omits_unknown_artist_and_unrepresentable_short_duration(): void
    {
        config(['musicbrainz.enabled' => false]);
        $this->actingAs(User::factory()->create());
        $this->import('2024-01-01T12:00:00Z');
        $listen = Listen::query()->sole();
        $metadata = $listen->track_metadata;
        $metadata['additional_info']['catalogs']['spotify'] = [
            'provider' => 'spotify', 'id' => 'ABCDEFGHIJKLMNOPQRSTUV',
            'track_name' => 'Track', 'artist_name' => 'Unknown artist',
            'release_name' => null, 'duration_ms' => 499, 'origin' => 'import', 'isrc' => null,
        ];
        $listen->update(['track_metadata' => $metadata]);

        $this->get(route('listens.show', $listen->id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('musicBrainzSeed', function ($url) {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);

                return $query['edit-recording_name'] === 'Track'
                    && ! isset($query['edit-recording_length'])
                    && ! isset($query['edit-recording_artist_credit_names_0_name'])
                    && ! str_contains($query['edit-recording_edit_note'], 'Artist: Unknown artist')
                    && str_contains($query['edit-recording_edit_note'], 'https://open.spotify.com/track/ABCDEFGHIJKLMNOPQRSTUV');
            }));
    }

    public function test_catalogs_are_inferred_from_unambiguous_imported_identifiers_for_the_same_local_recording(): void
    {
        config(['musicbrainz.enabled' => false]);
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->import('2024-01-01T12:00:00Z');
        $spotify = Listen::query()->sole();
        $csv = "Song Name,Artist Name,Album Name,Event Start Timestamp,Play Duration Milliseconds\n".
            "Track,Artist,Album,2024-01-02T12:00:00Z,60000\n";
        $this->post(route('listens.import'), [
            'source' => 'apple', 'file' => UploadedFile::fake()->createWithContent('history.csv', $csv),
        ])->assertSessionHasNoErrors();
        $apple = Listen::query()->where('source', 'apple')->sole();
        $metadata = $apple->track_metadata;
        $metadata['additional_info']['apple_music'] = ['catalog_id' => '987'];
        $apple->update(['track_metadata' => $metadata, 'recording_mbid' => '22222222-2222-4222-8222-222222222222']);
        $spotify->update(['recording_mbid' => '22222222-2222-4222-8222-222222222222']);

        $this->get(route('listens.show', $spotify->id))->assertInertia(fn (Assert $page) => $page
            ->where('catalogs.apple.id', '987')->where('catalogs.apple.origin', 'local'));
        $this->get(route('listens.show', $apple->id))->assertInertia(fn (Assert $page) => $page
            ->where('catalogs.spotify.id', '0123456789ABCDEFGHIJKL')->where('catalogs.spotify.origin', 'local'));

        $this->post(route('listens.import'), [
            'source' => 'apple', 'file' => UploadedFile::fake()->createWithContent('history.csv', str_replace('2024-01-02', '2024-01-03', $csv)),
        ])->assertSessionHasNoErrors();
        $ambiguous = Listen::query()->where('source', 'apple')->latest('id')->firstOrFail();
        $metadata = $ambiguous->track_metadata;
        $metadata['additional_info']['apple_music'] = ['catalog_id' => '654'];
        $ambiguous->update(['track_metadata' => $metadata, 'recording_mbid' => '22222222-2222-4222-8222-222222222222']);
        $this->get(route('listens.show', $spotify->id))->assertInertia(fn (Assert $page) => $page
            ->missing('catalogs.apple'));
    }

    public function test_catalog_metadata_is_shared_by_identifier_only_within_the_owner_and_manual_values_win(): void
    {
        config(['musicbrainz.enabled' => false, 'inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner);
        $this->import('2024-01-01T12:00:00Z');
        $listen = Listen::query()->sole();
        $this->post(route('listens.catalog', $listen->id), [
            ...$this->appleMatch(), 'catalog_isrc' => 'us-abc-24-12345',
        ])->assertSessionHasNoErrors();
        $this->import('2024-01-02T12:00:00Z', 'Different album');
        $second = Listen::query()->where('release_name', 'Different album')->sole();
        $this->post(route('listens.catalog', $second->id), [
            ...$this->appleMatch(), 'catalog_track' => 'Revised title', 'catalog_isrc' => null,
        ])->assertSessionHasNoErrors();
        $this->get(route('listens.show', $listen->id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('catalogs.apple.track_name', 'Revised title')->where('catalogs.apple.isrc', 'USABC2412345'));

        $this->actingAs($other);
        $this->import('2024-01-03T12:00:00Z');
        $otherListen = Listen::query()->where('user_id', $other->id)->sole();
        $this->post(route('listens.catalog', $otherListen->id), [
            ...$this->appleMatch(), 'catalog_track' => 'Other title',
        ])->assertSessionHasNoErrors();
        $this->get(route('listens.show', $otherListen->id))->assertInertia(fn (Assert $page) => $page
            ->where('catalogs.apple.track_name', 'Other title')->where('catalogs.apple.isrc', null));
        $this->actingAs($owner)->get(route('listens.show', $listen->id))->assertInertia(fn (Assert $page) => $page
            ->where('catalogs.apple.track_name', 'Revised title')->where('catalogs.apple.isrc', 'USABC2412345'));
        Http::assertNothingSent();
    }

    public function test_catalog_isrc_validation_and_local_backfill(): void
    {
        config(['musicbrainz.enabled' => false]);
        $this->actingAs(User::factory()->create());
        $this->import('2024-01-01T12:00:00Z');
        $listen = Listen::query()->sole();
        $this->post(route('listens.catalog', $listen->id), [
            ...$this->appleMatch(), 'catalog_isrc' => 'not-an-isrc',
        ])->assertSessionHasErrors('catalog_isrc');
        $this->post(route('listens.catalog', $listen->id), [
            ...$this->appleMatch(), 'catalog_isrc' => 'USABC2412345',
        ])->assertSessionHasNoErrors();
        DB::table('catalog_track_metadata')->delete();

        $this->artisan('catalogs:sync-metadata')->assertSuccessful();
        $this->artisan('catalogs:sync-metadata')->assertSuccessful();
        $this->get(route('listens.show', $listen->id))->assertInertia(fn (Assert $page) => $page
            ->where('catalogs.apple.track_name', 'Catalog track')->where('catalogs.apple.isrc', 'USABC2412345'));
        $this->assertSame(1, DB::table('catalog_track_metadata')->where('provider', 'apple')->count());
    }

    public function test_imported_isrc_is_saved_for_its_catalog_identifier(): void
    {
        config(['musicbrainz.enabled' => false]);
        $row = [
            'ts' => '2024-01-01T12:00:00Z', 'ms_played' => 60000,
            'master_metadata_track_name' => 'Track', 'master_metadata_album_artist_name' => 'Artist',
            'master_metadata_album_album_name' => 'Album', 'spotify_track_uri' => 'spotify:track:0123456789ABCDEFGHIJKL',
            'external_ids' => ['isrc' => 'US-ABC-24-12345'],
        ];
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'spotify', 'file' => UploadedFile::fake()->createWithContent('history.json', json_encode([$row], JSON_THROW_ON_ERROR)),
        ])->assertSessionHasNoErrors();
        $listen = Listen::query()->sole();
        $this->get(route('listens.show', $listen->id))->assertInertia(fn (Assert $page) => $page
            ->where('catalogs.spotify.isrc', 'USABC2412345')
            ->where('listen.track_metadata.additional_info.isrcs.0', 'USABC2412345'));
    }

    public function test_manual_catalog_metadata_survives_import_and_backfill_and_ids_are_case_sensitive(): void
    {
        config(['musicbrainz.enabled' => false]);
        $this->actingAs(User::factory()->create());
        $this->import('2024-01-01T12:00:00Z');
        $first = Listen::query()->sole();
        $match = [
            ...$this->appleMatch(), 'provider' => 'spotify', 'catalog_track' => 'Corrected title',
            'catalog_url' => 'spotify:track:0123456789ABCDEFGHIJKL',
        ];
        $this->post(route('listens.catalog', $first->id), $match)->assertSessionHasNoErrors();
        $this->import('2024-01-02T12:00:00Z', 'Different album');
        $second = Listen::query()->where('release_name', 'Different album')->sole();
        $this->post(route('listens.catalog', $second->id), [
            ...$match, 'catalog_url' => 'spotify:track:0123456789ABCDEFGHIJKl', 'catalog_track' => 'Other catalog track',
        ])->assertSessionHasNoErrors();
        $this->artisan('catalogs:sync-metadata')->assertSuccessful();

        $this->get(route('listens.show', $first->id))->assertInertia(fn (Assert $page) => $page
            ->where('catalogs.spotify.track_name', 'Corrected title'));
        $this->get(route('listens.show', $second->id))->assertInertia(fn (Assert $page) => $page
            ->where('catalogs.spotify.track_name', 'Other catalog track'));
        $this->assertSame(2, DB::table('catalog_track_metadata')->where('provider', 'spotify')->count());
    }

    public function test_invalid_imported_isrc_rolls_back_the_whole_import(): void
    {
        config(['musicbrainz.enabled' => false]);
        $row = [
            'ts' => '2024-01-01T12:00:00Z', 'ms_played' => 60000,
            'master_metadata_track_name' => 'Track', 'master_metadata_album_artist_name' => 'Artist',
            'master_metadata_album_album_name' => 'Album', 'spotify_track_uri' => 'spotify:track:0123456789ABCDEFGHIJKL',
        ];
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'spotify',
            'file' => UploadedFile::fake()->createWithContent('history.json', json_encode([
                $row, [...$row, 'ts' => '2024-01-02T12:00:00Z', 'isrc' => 'invalid'],
            ], JSON_THROW_ON_ERROR)),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('listens', 0);
        $this->assertDatabaseCount('catalog_track_metadata', 0);
    }

    public function test_spotify_lookup_returns_metadata_without_saving_a_match(): void
    {
        config([
            'musicbrainz.enabled' => false,
            'services.spotify.client_id' => 'client',
            'services.spotify.client_secret' => 'secret',
            'services.spotify.market' => 'NO',
        ]);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
        $this->import('2024-01-01T12:00:00Z');
        $listen = Listen::query()->sole();
        $before = $listen->track_metadata;
        $stored = DB::table('catalog_track_metadata')->get()->toArray();
        Http::fake([
            'accounts.spotify.com/api/token' => Http::response(['access_token' => 'token']),
            'api.spotify.com/v1/tracks/*' => Http::response($this->spotifyTrack()),
        ]);

        $this->postJson(route('listens.spotify-lookup', $listen->id), [
            'catalog_url' => 'https://open.spotify.com/track/0123456789ABCDEFGHIJKL?si=test',
        ])->assertOk()->assertExactJson([
            'track_name' => 'Spotify track',
            'artist_name' => 'First artist, Second artist',
            'release_name' => 'Spotify album',
            'duration_ms' => 181000,
            'isrc' => 'USABC2412345',
            'url' => 'https://open.spotify.com/track/0123456789ABCDEFGHIJKL',
        ]);

        Http::assertSent(fn ($request) => $request->url() === 'https://accounts.spotify.com/api/token'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('client:secret'))
            && $request['grant_type'] === 'client_credentials');
        Http::assertSent(fn ($request) => $request->url() === 'https://api.spotify.com/v1/tracks/0123456789ABCDEFGHIJKL?market=NO'
            && $request->hasHeader('Authorization', 'Bearer token'));
        Http::assertSentCount(2);
        $this->assertSame($before, $listen->fresh()->track_metadata);
        $this->assertEquals($stored, DB::table('catalog_track_metadata')->get()->toArray());
        $this->assertDatabaseCount('catalog_matches', 0);
    }

    public function test_spotify_lookup_checks_authentication_ownership_and_link_before_requesting_spotify(): void
    {
        config(['musicbrainz.enabled' => false, 'services.spotify.client_id' => 'client', 'services.spotify.client_secret' => 'secret']);
        Http::preventStrayRequests();
        $this->postJson(route('listens.spotify-lookup', 1), [])->assertUnauthorized();
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $this->import('2024-01-01T12:00:00Z');
        $listen = Listen::query()->sole();
        $this->actingAs(User::factory()->create())->postJson(route('listens.spotify-lookup', $listen->id), [
            'catalog_url' => 'spotify:track:0123456789ABCDEFGHIJKL',
        ])->assertNotFound();
        $this->actingAs($owner)->postJson(route('listens.spotify-lookup', $listen->id), [
            'catalog_url' => 'https://open.spotify.com.attacker.test/track/0123456789ABCDEFGHIJKL',
        ])->assertUnprocessable()->assertJsonValidationErrors('catalog_url');
        Http::assertNothingSent();
    }

    public function test_spotify_lookup_reports_configuration_and_upstream_errors(): void
    {
        config(['musicbrainz.enabled' => false, 'services.spotify.client_id' => null, 'services.spotify.client_secret' => null]);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
        $this->import('2024-01-01T12:00:00Z');
        $route = route('listens.spotify-lookup', Listen::query()->sole()->id);
        $data = ['catalog_url' => 'spotify:track:0123456789ABCDEFGHIJKL'];
        $this->postJson($route, $data)->assertUnprocessable()->assertJsonFragment([
            'Spotify lookup is not configured. Set SPOTIFY_CLIENT_ID and SPOTIFY_CLIENT_SECRET on the server.',
        ]);
        Http::assertNothingSent();
        config(['services.spotify.client_id' => 'client', 'services.spotify.client_secret' => 'secret', 'services.spotify.market' => 'invalid']);
        $this->postJson($route, $data)->assertUnprocessable()->assertJsonValidationErrors('catalog_url');
        Http::assertNothingSent();

        config(['services.spotify.market' => 'US']);
        $tokenStatus = 200;
        $trackStatus = 200;
        $connectionFailed = false;
        $token = ['access_token' => 'token'];
        Http::fake(function ($request) use (&$tokenStatus, &$trackStatus, &$connectionFailed, &$token) {
            if ($request->url() === 'https://accounts.spotify.com/api/token') {
                return $connectionFailed ? Http::failedConnection() : Http::response($token, $tokenStatus);
            }

            return Http::response([], $trackStatus);
        });
        foreach ([401, 403, 429, 500] as $status) {
            $tokenStatus = $status;
            $this->postJson($route, $data)->assertUnprocessable()->assertJsonValidationErrors('catalog_url');
        }
        $tokenStatus = 200;
        $token = [];
        $this->postJson($route, $data)->assertUnprocessable()->assertJsonFragment(['Spotify returned an invalid authorization response.']);
        $token = ['access_token' => 'token'];
        $connectionFailed = true;
        $this->postJson($route, $data)->assertUnprocessable()->assertJsonFragment(['Could not connect to Spotify. Please try again.']);
        $connectionFailed = false;
        foreach ([404, 429, 500] as $status) {
            $trackStatus = $status;
            $this->postJson($route, $data)->assertUnprocessable()->assertJsonValidationErrors('catalog_url');
        }
    }

    public function test_spotify_lookup_handles_partial_and_invalid_metadata_and_relinked_tracks(): void
    {
        config([
            'musicbrainz.enabled' => false, 'services.spotify.client_id' => 'client',
            'services.spotify.client_secret' => 'secret', 'services.spotify.market' => 'US',
        ]);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
        $this->import('2024-01-01T12:00:00Z');
        $route = route('listens.spotify-lookup', Listen::query()->sole()->id);
        $data = ['catalog_url' => 'spotify:track:0123456789ABCDEFGHIJKL'];
        $partial = $this->spotifyTrack();
        unset($partial['album'], $partial['duration_ms'], $partial['external_ids']);
        $track = $partial;
        Http::fake(function ($request) use (&$track) {
            return $request->url() === 'https://accounts.spotify.com/api/token'
                ? Http::response(['access_token' => 'token']) : Http::response($track);
        });
        $this->postJson($route, $data)->assertOk()->assertJsonPath('release_name', null)
            ->assertJsonPath('duration_ms', null)->assertJsonPath('isrc', null);

        foreach ([
            ['id' => 'ABCDEFGHIJKLMNOPQRSTUV'],
            ['artists' => []],
            ['duration_ms' => 'invalid'],
            ['external_ids' => ['isrc' => 'invalid']],
            ['album' => 'invalid'],
            ['external_ids' => 'invalid'],
            ['is_playable' => false],
        ] as $invalid) {
            $track = [...$this->spotifyTrack(), ...$invalid];
            $this->postJson($route, $data)->assertUnprocessable()->assertJsonValidationErrors('catalog_url');
        }
    }

    private function spotifyTrack(): array
    {
        return [
            'id' => '0123456789ABCDEFGHIJKL',
            'name' => 'Spotify track',
            'artists' => [['name' => 'First artist'], ['name' => 'Second artist']],
            'album' => ['name' => 'Spotify album'],
            'duration_ms' => 181000,
            'external_ids' => ['isrc' => 'US-ABC-24-12345'],
        ];
    }
}
