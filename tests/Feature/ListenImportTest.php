<?php

namespace Tests\Feature;

use App\Models\Listen;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ListenImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['musicbrainz.enabled' => false, 'musicbrainz.format' => 'full']);
    }

    private function spotify(array $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('history.json', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private function row(string $timestamp, string $track = 'Track', string $artist = 'Artist', string $album = 'Album'): array
    {
        return [
            'ts' => $timestamp,
            'ms_played' => 60000,
            'master_metadata_track_name' => $track,
            'master_metadata_album_artist_name' => $artist,
            'master_metadata_album_album_name' => $album,
            'spotify_track_uri' => 'spotify:track:123',
        ];
    }

    private function musicbrainz(): void
    {
        config(['musicbrainz.enabled' => true, 'database.connections.musicbrainz' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('musicbrainz');
        $schema = Schema::connection('musicbrainz');
        $schema->create('artist_credit', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('name');
        });
        $schema->create('artist', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('gid');
            $table->string('name');
        });
        $schema->create('artist_alias', function (Blueprint $table) {
            $table->integer('artist');
            $table->string('name');
        });
        $schema->create('artist_credit_name', function (Blueprint $table) {
            $table->integer('artist_credit');
            $table->integer('artist');
            $table->integer('position');
        });
        $schema->create('recording', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('gid');
            $table->string('name');
            $table->integer('artist_credit');
        });
        $schema->create('isrc', function (Blueprint $table) {
            $table->integer('recording');
            $table->string('isrc');
        });
        $schema->create('release', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('gid');
            $table->string('name');
        });
        $schema->create('medium', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('release');
        });
        $schema->create('track', function (Blueprint $table) {
            $table->integer('recording');
            $table->integer('medium');
        });
        $db = DB::connection('musicbrainz');
        $db->table('artist_credit')->insert(['id' => 1, 'name' => 'Artist']);
        $db->table('artist')->insert(['id' => 1, 'gid' => '11111111-1111-4111-8111-111111111111', 'name' => 'Artist']);
        $db->table('artist_credit_name')->insert(['artist_credit' => 1, 'artist' => 1, 'position' => 0]);
        $db->table('recording')->insert(['id' => 1, 'gid' => '22222222-2222-4222-8222-222222222222', 'name' => 'Track', 'artist_credit' => 1]);
        $db->table('release')->insert(['id' => 1, 'gid' => '33333333-3333-4333-8333-333333333333', 'name' => 'Album']);
        $db->table('medium')->insert(['id' => 1, 'release' => 1]);
        $db->table('track')->insert(['recording' => 1, 'medium' => 1]);
    }

    public function test_guests_cannot_import_or_browse(): void
    {
        $this->get(route('listens.index'))->assertRedirect(route('login'));
        $this->get(route('listens.create'))->assertRedirect(route('login'));
        $this->post(route('listens.import'))->assertRedirect(route('login'));
        $this->delete(route('listens.destroy'))->assertRedirect(route('login'));
        $this->get(route('listens.show', 1))->assertRedirect(route('login'));
        $this->post(route('listens.match', 1))->assertRedirect(route('login'));
    }

    public function test_deleting_listening_history_only_removes_the_current_users_listens_and_allows_reimport(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $row = $this->row('2024-01-01T12:00:00Z');
        $apple = "Song Name,Artist Name,Event Start Timestamp,Play Duration Milliseconds\n".
            "Track,Artist,2024-01-02T12:00:00Z,60000\n";
        $this->actingAs($user)->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$row]),
        ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0]);
        $this->post(route('listens.import'), [
            'source' => 'apple', 'file' => UploadedFile::fake()->createWithContent('activity.csv', $apple),
        ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0]);
        $this->actingAs($other)->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$row]),
        ])->assertSessionHasNoErrors();
        $otherListen = Listen::query()->where('user_id', $other->id)->sole();
        $this->assertSame(2, Listen::query()->where('user_id', $user->id)->count());

        $this->actingAs($user)->delete(route('listens.destroy'), ['user_id' => $other->id])
            ->assertSessionHasNoErrors()->assertRedirect(route('listens.create'));

        $this->assertDatabaseMissing('listens', ['user_id' => $user->id]);
        $this->assertModelExists($otherListen);
        $this->assertAuthenticatedAs($user);
        $this->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$row]),
        ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0]);
    }

    public function test_unverified_users_can_delete_their_listening_history(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$this->row('2024-01-01T12:00:00Z')]),
        ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0]);
        $user->forceFill(['email_verified_at' => null])->save();

        $this->delete(route('listens.destroy'))->assertRedirect(route('listens.create'));

        $this->assertDatabaseMissing('listens', ['user_id' => $user->id]);
    }

    public function test_import_validation_errors_return_to_import_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('listens.create'))
            ->post(route('listens.import'), ['source' => 'spotify'])
            ->assertRedirect(route('listens.create'))
            ->assertSessionHasErrors('file');
    }

    public function test_unverified_users_can_view_the_import_page_but_cannot_import(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('listens.create'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('settings/import-history')->where('canImport', false));
        $this->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$this->row('2024-01-01T12:00:00Z')]),
        ])->assertRedirect(route('verification.notice'));
        $this->assertDatabaseCount('listens', 0);
    }

    public function test_manual_matching_updates_only_own_unmatched_listens_and_is_remembered_for_future_imports(): void
    {
        $this->musicbrainz();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $first = $this->row('2024-01-01T12:00:00Z', album: 'Unknown album');
        $second = $this->row('2024-01-02T12:00:00Z', album: 'Another album');
        $this->actingAs($user)->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$first, $second]),
        ])->assertSessionHas('import_result', ['imported' => 2, 'duplicates' => 0, 'matched' => 0]);
        $listen = Listen::query()->where('user_id', $user->id)->firstOrFail();
        $this->actingAs($other)->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$first]),
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->get(route('listens.show', ['listen' => $listen->id, 'q' => 'Tra']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('listens/show')
            ->where('suggestions.0.recording_mbid', '22222222-2222-4222-8222-222222222222')
            ->has('related', 1));
        $this->post(route('listens.match', $listen->id), [
            'recording_mbid' => '22222222-2222-4222-8222-222222222222', 'track_name' => 'Track', 'artist_name' => 'Artist',
        ])->assertRedirect(route('listens.show', $listen->id))->assertSessionHas('match_result', 2);

        $this->assertSame(2, Listen::query()->where('user_id', $user->id)->where('recording_mbid', '22222222-2222-4222-8222-222222222222')->count());
        $this->assertNull(Listen::query()->where('user_id', $other->id)->sole()->recording_mbid);
        $this->assertSame(['11111111-1111-4111-8111-111111111111'], $listen->fresh()->track_metadata['additional_info']['artist_mbids']);

        config(['musicbrainz.enabled' => false]);
        $this->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$this->row('2024-01-03T12:00:00Z', album: 'Third album')]),
        ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 1]);
        $this->assertSame(3, Listen::query()->where('user_id', $user->id)->whereNotNull('recording_mbid')->count());
        $this->actingAs($other)->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$this->row('2024-01-03T12:00:00Z', album: 'Third album')]),
        ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0]);
        $this->actingAs($user)->delete(route('listens.destroy'))->assertRedirect(route('listens.create'));
        $this->assertDatabaseMissing('import_batches', ['user_id' => $user->id]);
        $this->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$first]),
        ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 1]);
    }

    public function test_import_matches_artist_casing_and_aliases_without_changing_imported_names(): void
    {
        $this->musicbrainz();
        DB::connection('musicbrainz')->table('artist_alias')->insert(['artist' => 1, 'name' => 'Älias']);
        DB::connection('musicbrainz')->table('isrc')->insert(['recording' => 1, 'isrc' => 'USABC2412345']);
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([
                $this->row('2024-01-01T12:00:00Z', artist: 'aRTIST'),
                $this->row('2024-01-02T12:00:00Z', artist: 'äLIAS'),
            ]),
        ])->assertSessionHas('import_result', ['imported' => 2, 'duplicates' => 0, 'matched' => 2]);

        $listens = Listen::query()->orderBy('id')->get();
        $this->assertSame('aRTIST', $listens[0]->artist_name);
        $this->assertSame('äLIAS', $listens[1]->artist_name);
        $this->assertSame('22222222-2222-4222-8222-222222222222', $listens[0]->recording_mbid);
        $this->assertSame($listens[0]->recording_mbid, $listens[1]->recording_mbid);
        $this->assertSame(['USABC2412345'], $listens[0]->track_metadata['additional_info']['isrcs']);
    }

    public function test_ambiguous_artist_aliases_do_not_identify_a_recording(): void
    {
        $this->musicbrainz();
        $db = DB::connection('musicbrainz');
        $db->table('artist')->insert(['id' => 2, 'gid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'Another artist']);
        $db->table('artist_credit')->insert(['id' => 2, 'name' => 'Another artist']);
        $db->table('artist_credit_name')->insert(['artist_credit' => 2, 'artist' => 2, 'position' => 0]);
        $db->table('artist_alias')->insert([['artist' => 1, 'name' => 'Shared alias'], ['artist' => 2, 'name' => 'Shared alias']]);
        $db->table('recording')->insert(['id' => 2, 'gid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'name' => 'Track', 'artist_credit' => 2]);
        $db->table('track')->insert(['recording' => 2, 'medium' => 1]);
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$this->row('2024-01-01T12:00:00Z', artist: 'shared alias')]),
        ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0]);
        $this->assertNull(Listen::query()->sole()->recording_mbid);
    }

    public function test_direct_artist_credits_take_precedence_and_aliases_do_not_match_collaborations(): void
    {
        $this->musicbrainz();
        $db = DB::connection('musicbrainz');
        $db->table('artist_alias')->insert(['artist' => 1, 'name' => 'Alias']);
        $db->table('artist')->insert(['id' => 2, 'gid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'Alias']);
        $db->table('artist_credit')->insert([
            ['id' => 2, 'name' => 'Alias'],
            ['id' => 3, 'name' => 'Artist & Alias'],
        ]);
        $db->table('artist_credit_name')->insert([
            ['artist_credit' => 2, 'artist' => 2, 'position' => 0],
            ['artist_credit' => 3, 'artist' => 1, 'position' => 0],
            ['artist_credit' => 3, 'artist' => 2, 'position' => 1],
        ]);
        $db->table('recording')->insert([
            ['id' => 2, 'gid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'name' => 'Track', 'artist_credit' => 2],
            ['id' => 3, 'gid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'name' => 'Collaboration', 'artist_credit' => 3],
        ]);
        $db->table('track')->insert([['recording' => 2, 'medium' => 1], ['recording' => 3, 'medium' => 1]]);

        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([
                $this->row('2024-01-01T12:00:00Z', artist: 'alias'),
                $this->row('2024-01-02T12:00:00Z', track: 'Collaboration', artist: 'Alias'),
            ]),
        ])->assertSessionHas('import_result', ['imported' => 2, 'duplicates' => 0, 'matched' => 1]);
        $this->assertSame('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', Listen::query()->where('track_name', 'Track')->sole()->recording_mbid);
        $this->assertNull(Listen::query()->where('track_name', 'Collaboration')->sole()->recording_mbid);
    }

    public function test_matched_artist_and_album_details_only_show_the_owners_listens(): void
    {
        $this->musicbrainz();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner)->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$this->row('2024-01-01T12:00:00Z', artist: 'aRTIST')]),
        ])->assertSessionHasNoErrors();
        $item = Listen::query()->where('user_id', $owner->id)->sole();
        $this->assertDatabaseHas('music_artists', ['mbid' => '11111111-1111-4111-8111-111111111111', 'name' => 'Artist']);
        $this->assertDatabaseHas('music_releases', ['mbid' => '33333333-3333-4333-8333-333333333333', 'name' => 'Album']);
        $this->get(route('listens.show', $item->id))->assertInertia(fn (Assert $page) => $page
            ->where('matchedArtists.0.name', 'Artist')->where('matchedAlbums.0.name', 'Album')
            ->where('listen.artist_name', 'aRTIST'));
        $this->get(route('listens.index'))->assertInertia(fn (Assert $page) => $page
            ->where('listens.data.0.matched_artist_mbids.0', '11111111-1111-4111-8111-111111111111')
            ->where('listens.data.0.matched_album_mbids.0', '33333333-3333-4333-8333-333333333333'));
        $this->get(route('artists.show', '11111111-1111-4111-8111-111111111111'))
            ->assertInertia(fn (Assert $page) => $page->component('music-entities/show')
                ->where('entity.name', 'Artist')->has('tracks', 1)->where('listens.total', 1));
        $this->get(route('albums.show', '33333333-3333-4333-8333-333333333333'))
            ->assertInertia(fn (Assert $page) => $page->where('entity.name', 'Album')
                ->has('tracks', 1)->where('listens.total', 1));
        $this->actingAs($other)->get(route('artists.show', '11111111-1111-4111-8111-111111111111'))->assertNotFound();
        $this->get(route('albums.show', '33333333-3333-4333-8333-333333333333'))->assertNotFound();
        $this->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$this->row('2024-01-02T12:00:00Z')]),
        ])->assertSessionHasNoErrors();
        $this->actingAs($owner)->get(route('artists.show', '11111111-1111-4111-8111-111111111111'))
            ->assertInertia(fn (Assert $page) => $page->where('listens.total', 1)->where('tracks.0.listen_count', 1));
    }

    public function test_manual_matches_and_existing_matched_listens_store_local_metadata(): void
    {
        $this->musicbrainz();
        DB::connection('musicbrainz')->table('isrc')->insert([
            ['recording' => 1, 'isrc' => 'USABC2412345'],
            ['recording' => 1, 'isrc' => 'GBXYZ2412345'],
        ]);
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$this->row('2024-01-01T12:00:00Z', artist: 'Different artist')]),
        ])->assertSessionHasNoErrors();
        $listen = Listen::query()->sole();
        $this->post(route('listens.match', $listen->id), [
            'recording_mbid' => '22222222-2222-4222-8222-222222222222', 'track_name' => 'Track', 'artist_name' => 'Artist',
        ])->assertSessionHas('match_result', 1);
        $this->assertSame('Different artist', $listen->fresh()->artist_name);
        $this->assertSame('33333333-3333-4333-8333-333333333333', $listen->fresh()->track_metadata['additional_info']['release_mbid']);
        $this->assertSame(['GBXYZ2412345', 'USABC2412345'], $listen->fresh()->track_metadata['additional_info']['isrcs']);
        $this->assertDatabaseHas('listen_artists', ['listen_id' => $listen->id]);
        $this->assertDatabaseHas('listen_releases', ['listen_id' => $listen->id]);
        DB::table('listen_artists')->where('listen_id', $listen->id)->delete();
        DB::table('listen_releases')->where('listen_id', $listen->id)->delete();
        $this->artisan('listens:sync-metadata')->assertSuccessful();
        $this->artisan('listens:sync-metadata')->assertSuccessful();
        $this->assertDatabaseHas('listen_artists', ['listen_id' => $listen->id]);
        $this->assertDatabaseHas('listen_releases', ['listen_id' => $listen->id]);
        $this->assertSame(1, DB::table('listen_artists')->where('listen_id', $listen->id)->count());
        $this->assertSame(1, DB::table('listen_releases')->where('listen_id', $listen->id)->count());
    }

    public function test_reimport_identifies_a_previously_unmatched_listen(): void
    {
        $user = User::factory()->create();
        $row = $this->row('2024-01-01T12:00:00Z', artist: 'aRTIST');
        $this->actingAs($user)->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$row]),
        ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0]);
        $this->musicbrainz();
        $this->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$row]),
        ])->assertSessionHas('import_result', ['imported' => 0, 'duplicates' => 1, 'matched' => 0]);
        $listen = Listen::query()->sole();
        $this->assertSame('aRTIST', $listen->artist_name);
        $this->assertSame('22222222-2222-4222-8222-222222222222', $listen->recording_mbid);
        $this->assertDatabaseHas('listen_artists', ['listen_id' => $listen->id]);
        $this->assertDatabaseHas('listen_releases', ['listen_id' => $listen->id]);
    }

    public function test_recording_search_supports_artist_filter_and_pagination(): void
    {
        $this->musicbrainz();
        $db = DB::connection('musicbrainz');
        $db->table('artist_credit')->insert(['id' => 2, 'name' => 'Other artist']);
        for ($i = 1; $i <= 31; $i++) {
            $db->table('recording')->insert([
                'id' => $i + 1, 'gid' => sprintf('00000000-0000-4000-8000-%012d', $i),
                'name' => 'Track variation', 'artist_credit' => 2,
            ]);
        }
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$this->row('2024-01-01T12:00:00Z', album: 'Unknown')]),
        ])->assertSessionHasNoErrors();
        $listen = Listen::query()->sole();

        $this->get(route('listens.show', ['listen' => $listen->id, 'q' => 'Tra', 'artist' => 'other']))
            ->assertInertia(fn (Assert $page) => $page->has('suggestions', 30)->where('hasMore', true));
        $this->get(route('listens.show', ['listen' => $listen->id, 'q' => 'Tra', 'artist' => 'other', 'page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('suggestions', 1)->where('hasMore', false));
    }

    public function test_listen_details_and_manual_matches_are_scoped_to_the_owner_and_validate_recordings(): void
    {
        $this->musicbrainz();
        $owner = User::factory()->create();
        $this->actingAs($owner)->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$this->row('2024-01-01T12:00:00Z', album: 'Unknown')]),
        ])->assertSessionHasNoErrors();
        $listen = Listen::query()->sole();
        $this->actingAs(User::factory()->create())->get(route('listens.show', $listen->id))->assertNotFound();
        $this->post(route('listens.match', $listen->id), [
            'recording_mbid' => '22222222-2222-4222-8222-222222222222', 'track_name' => 'Track', 'artist_name' => 'Artist',
        ])->assertNotFound();
        $this->actingAs($owner)->from(route('listens.show', $listen->id))->post(route('listens.match', $listen->id), [
            'recording_mbid' => '22222222-2222-4222-8222-222222222222', 'track_name' => 'Wrong track', 'artist_name' => 'Artist',
        ])->assertSessionHasErrors('recording_mbid');
        $this->assertNull($listen->fresh()->recording_mbid);
    }

    public function test_dashboard_displays_only_the_current_users_listens_and_recent_imports(): void
    {
        $this->travelTo(now()->startOfDay());
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$this->row(now()->toIso8601String())]),
        ])->assertSessionHasNoErrors();
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$this->row(now()->toIso8601String())]),
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')->where('stats.total', 1)->where('stats.playedMs', 60000)
            ->where('stats.unmatched', 1)->where('stats.last30Days', 1)->has('imports', 1)
            ->where('imports.0.imported', 1));
    }

    public function test_spotify_import_matches_recording_and_deduplicates_per_user(): void
    {
        $this->musicbrainz();
        $user = User::factory()->create();
        $row = $this->row('2024-01-01T12:00:00Z');
        $podcast = ['ts' => '2024-01-01T12:01:00Z', 'ms_played' => 60000, 'master_metadata_track_name' => null];
        $this->actingAs($user)->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$row, $row, $podcast]),
        ])->assertRedirect(route('listens.create'))->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 1, 'matched' => 1]);

        $this->get(route('listens.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/import-history')
            ->where('musicbrainzEnabled', true)
            ->where('canImport', true)
            ->where('importResult', ['imported' => 1, 'duplicates' => 1, 'matched' => 1]));

        $listen = Listen::query()->sole();
        $this->assertSame(1704110340, $listen->listened_at);
        $this->assertSame('22222222-2222-4222-8222-222222222222', $listen->recording_mbid);
        $this->assertSame([
            'artist_name' => 'Artist',
            'track_name' => 'Track',
            'additional_info' => [
                'music_service' => 'spotify.com',
                'recording_mbid' => '22222222-2222-4222-8222-222222222222',
                'artist_mbids' => ['11111111-1111-4111-8111-111111111111'],
                'release_mbid' => '33333333-3333-4333-8333-333333333333',
            ],
            'release_name' => 'Album',
        ], $listen->track_metadata);

        $this->post(route('listens.import'), ['source' => 'spotify', 'file' => $this->spotify([$row])])
            ->assertSessionHas('import_result', ['imported' => 0, 'duplicates' => 1, 'matched' => 0]);
        $this->actingAs(User::factory()->create())->post(route('listens.import'), ['source' => 'spotify', 'file' => $this->spotify([$row])])
            ->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 1]);
        $this->assertDatabaseCount('listens', 2);
    }

    public function test_apple_play_activity_imports_events_and_resolves_missing_artist_only_when_unambiguous(): void
    {
        $this->musicbrainz();
        $csv = "\xEF\xBB\xBFSong Name,Artist Name,Album Name,Event Start Timestamp,Event End Timestamp,Event Type,Play Duration Milliseconds,Media Duration In Milliseconds\n".
            "Track,,Album,2024-01-01T12:00:00Z,2024-01-01T12:01:00Z,PLAY_END,60000,180000\n".
            "Track,,Album,2024-01-01T12:00:00Z,2024-01-01T12:01:00Z,LYRIC_DISPLAY,60000,180000\n".
            "Unknown,,Album,2024-01-01T13:00:00Z,2024-01-01T13:01:00Z,PLAY_END,40000,200000\n";
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'apple', 'file' => UploadedFile::fake()->createWithContent('activity.csv', $csv),
        ])->assertSessionHas('import_result', ['imported' => 2, 'duplicates' => 0, 'matched' => 1]);
        $matched = Listen::query()->where('track_name', 'Track')->sole();
        $this->assertSame('Artist', $matched->artist_name);
        $this->assertSame(1704110400, $matched->listened_at);
        $this->assertSame(180000, $matched->track_metadata['additional_info']['duration_ms']);
        $this->assertSame('Unknown artist', Listen::query()->where('track_name', 'Unknown')->sole()->artist_name);
    }

    public function test_ambiguous_musicbrainz_recordings_remain_unmatched(): void
    {
        $this->musicbrainz();
        $db = DB::connection('musicbrainz');
        $db->table('recording')->insert(['id' => 2, 'gid' => '44444444-4444-4444-8444-444444444444', 'name' => 'Track', 'artist_credit' => 1]);
        $db->table('track')->insert(['recording' => 2, 'medium' => 1]);
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$this->row('2024-01-01T12:00:00Z')]),
        ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0]);
        $this->assertNull(Listen::query()->sole()->recording_mbid);
    }

    public function test_apple_import_prefers_album_artist_for_matching_and_falls_back_to_track_artist(): void
    {
        $this->musicbrainz();
        $csv = "Song Name,Artist Name,Album Artist Name,Album Artist,Album Name,Event Start Timestamp,Play Duration Milliseconds,Event ID\n".
            "Track,Featured artist, Artist ,Different artist,Album,2024-01-01T12:00:00Z,60000,one\n".
            "Track,Featured artist,,Artist,Album,2024-01-01T12:01:00Z,60000,two\n".
            "Unknown,Track artist, , ,Album,2024-01-01T12:02:00Z,60000,three\n".
            "Track,,Artist,,Album,2024-01-01T12:03:00Z,60000,four\n";

        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'apple', 'file' => UploadedFile::fake()->createWithContent('activity.csv', $csv),
        ])->assertSessionHas('import_result', ['imported' => 4, 'duplicates' => 0, 'matched' => 3]);

        $matched = Listen::query()->where('track_name', 'Track')->get();
        $this->assertCount(3, $matched);
        foreach ($matched as $listen) {
            $this->assertSame('Artist', $listen->artist_name);
            $this->assertSame('Artist', $listen->track_metadata['artist_name']);
            $this->assertSame('22222222-2222-4222-8222-222222222222', $listen->recording_mbid);
        }
        $this->assertSame('Track artist', Listen::query()->where('track_name', 'Unknown')->sole()->artist_name);
    }

    public function test_apple_album_artist_does_not_duplicate_previously_imported_events_without_ids(): void
    {
        $withoutAlbumArtist = "Song Name,Artist Name,Album Name,Event Start Timestamp,Play Duration Milliseconds\n".
            "Track,Featured artist,Album,2024-01-01T12:00:00Z,60000\n";
        $withAlbumArtist = "Song Name,Artist Name,Album Artist Name,Album Name,Event Start Timestamp,Play Duration Milliseconds\n".
            "Track,Featured artist,Album artist,Album,2024-01-01T12:00:00Z,60000\n";

        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'apple', 'file' => UploadedFile::fake()->createWithContent('old.csv', $withoutAlbumArtist),
        ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0]);
        $this->post(route('listens.import'), [
            'source' => 'apple', 'file' => UploadedFile::fake()->createWithContent('new.csv', $withAlbumArtist),
        ])->assertSessionHas('import_result', ['imported' => 0, 'duplicates' => 1, 'matched' => 0]);

        $this->assertDatabaseCount('listens', 1);
        $this->assertSame('Featured artist', Listen::query()->sole()->artist_name);
    }

    public function test_invalid_or_incomplete_upload_rolls_back_the_entire_import(): void
    {
        $valid = json_encode($this->row('2024-01-01T12:00:00Z'), JSON_THROW_ON_ERROR);
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'spotify', 'file' => UploadedFile::fake()->createWithContent('bad.json', '['.$valid.','),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('listens', 0);
        $this->post(route('listens.import'), [
            'source' => 'apple', 'file' => UploadedFile::fake()->createWithContent('bad.csv', "not,a,history\n1,2,3"),
        ])->assertSessionHasErrors('file');
        $this->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$this->row('2024-02-30T12:00:00Z')]),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('listens', 0);
    }

    public function test_musicbrainz_query_failure_reports_error_and_rolls_back(): void
    {
        config(['musicbrainz.enabled' => true, 'database.connections.musicbrainz' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('musicbrainz');
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$this->row('2024-01-01T12:00:00Z')]),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('listens', 0);
    }

    public function test_browse_filters_sorts_and_never_exposes_another_users_listens(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('listens.import'), [
            'source' => 'spotify',
            'file' => $this->spotify([
                $this->row('2024-01-01T12:00:00Z', 'Zebra'),
                $this->row('2024-01-02T12:00:00Z', 'Apple'),
                $this->row('2024-01-03T12:00:00Z', 'Other'),
            ]),
        ]);
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$this->row('2024-01-02T12:00:00Z', 'Private')]),
        ]);
        $this->actingAs($user)->get(route('listens.index', [
            'source' => 'spotify', 'match' => 'unmatched', 'from' => '2024-01-01', 'to' => '2024-01-02',
            'sort' => 'track_name', 'direction' => 'asc',
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('listens/index')->where('listens.total', 2)
            ->where('listens.data.0.track_name', 'Apple')->where('listens.data.1.track_name', 'Zebra'));
        $this->get(route('listens.index', ['search' => 'apple']))->assertInertia(fn (Assert $page) => $page
            ->where('listens.total', 1)->where('listens.data.0.track_name', 'Apple'));
        $this->get(route('listens.index', ['search' => '%']))->assertInertia(fn (Assert $page) => $page->where('listens.total', 0));
        $this->get(route('listens.index', ['to' => '2024-01-02']))->assertOk()->assertInertia(fn (Assert $page) => $page->where('listens.total', 2));
        $this->get(route('listens.index', ['sort' => 'user_id']))->assertSessionHasErrors('sort');
    }

    public function test_apple_event_ids_distinguish_simultaneous_events_and_scrubs_are_skipped(): void
    {
        $csv = "Song Name,Artist Name,Event Start Timestamp,Event Type,End Reason Type,Play Duration Milliseconds,Event ID\n".
            "Track,Artist,2024-01-01T12:00:00Z,PLAY_END,NATURAL_END_OF_TRACK,60000,one\n".
            "Track,Artist,2024-01-01T12:00:00Z,PLAY_END,NATURAL_END_OF_TRACK,60000,two\n".
            "Track,Artist,2024-01-01T12:00:00Z,PLAY_END,SCRUB_END,60000,three\n";
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'apple', 'file' => UploadedFile::fake()->createWithContent('activity.csv', $csv),
        ])->assertSessionHas('import_result', ['imported' => 2, 'duplicates' => 0, 'matched' => 0]);
        $this->assertDatabaseCount('listens', 2);
        $changed = str_replace('Track,Artist,2024', 'Updated title,Artist,2024', $csv);
        $this->post(route('listens.import'), [
            'source' => 'apple', 'file' => UploadedFile::fake()->createWithContent('activity.csv', $changed),
        ])->assertSessionHas('import_result', ['imported' => 0, 'duplicates' => 2, 'matched' => 0]);
        $this->assertDatabaseCount('listens', 2);
    }
}
