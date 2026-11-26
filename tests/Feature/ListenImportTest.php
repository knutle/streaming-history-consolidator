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
        $db->table('artist')->insert(['id' => 1, 'gid' => '11111111-1111-4111-8111-111111111111']);
        $db->table('artist_credit_name')->insert(['artist_credit' => 1, 'artist' => 1, 'position' => 0]);
        $db->table('recording')->insert(['id' => 1, 'gid' => '22222222-2222-4222-8222-222222222222', 'name' => 'Track', 'artist_credit' => 1]);
        $db->table('release')->insert(['id' => 1, 'gid' => '33333333-3333-4333-8333-333333333333', 'name' => 'Album']);
        $db->table('medium')->insert(['id' => 1, 'release' => 1]);
        $db->table('track')->insert(['recording' => 1, 'medium' => 1]);
    }

    public function test_guests_cannot_import_or_browse(): void
    {
        $this->get(route('listens.index'))->assertRedirect(route('login'));
        $this->post(route('listens.import'))->assertRedirect(route('login'));
    }

    public function test_spotify_import_matches_recording_and_deduplicates_per_user(): void
    {
        $this->musicbrainz();
        $user = User::factory()->create();
        $row = $this->row('2024-01-01T12:00:00Z');
        $podcast = ['ts' => '2024-01-01T12:01:00Z', 'ms_played' => 60000, 'master_metadata_track_name' => null];
        $this->actingAs($user)->post(route('listens.import'), [
            'source' => 'spotify', 'file' => $this->spotify([$row, $row, $podcast]),
        ])->assertRedirect(route('listens.index'))->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 1, 'matched' => 1]);

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
