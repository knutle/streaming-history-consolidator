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
                    && $query['edit-recording_name'] === 'Catalog track'
                    && $query['edit-recording_artist_credit_names_0_name'] === 'Catalog artist'
                    && $query['edit-recording_length'] === '3:01'
                    && str_contains($query['edit-recording_edit_note'], 'https://music.apple.com/us/song/987')
                    && str_contains($query['edit-recording_edit_note'], 'Apple catalog ISRC: USABC2412345')
                    && str_contains($query['edit-recording_edit_note'], 'https://open.spotify.com/track/0123456789ABCDEFGHIJKL');
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
}
