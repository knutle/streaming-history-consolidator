<?php

namespace Tests\Feature;

use App\Models\Listen;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class AppleMusicArchiveImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['musicbrainz.enabled' => false]);
    }

    private function zip(array $files): string
    {
        $temp = tmpfile();
        $this->assertNotFalse($temp);
        $path = stream_get_meta_data($temp)['uri'];
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($files as $name => $contents) {
            $this->assertTrue($archive->addFromString($name, $contents));
        }
        $this->assertTrue($archive->close());
        $data = file_get_contents($path);
        fclose($temp);
        $this->assertNotFalse($data);

        return $data;
    }

    private function playCsv(): string
    {
        return "Song Name,Album Name,Event Start Timestamp,Event Type,End Reason Type,Play Duration Milliseconds,Media Duration In Milliseconds,Start Position In Milliseconds,End Position In Milliseconds,Event ID,Client IP Address\n".
            "Track,Album,2024-01-01T12:00:00Z,PLAY_END,NATURAL_END_OF_TRACK,60000,180000,0,60000,event-1,private-ip\n";
    }

    private function dailyCsv(string $extra = ''): string
    {
        return "Date Played,Track Identifier,Track Description\n".
            "20240101,123456,Singer - Track\n".$extra;
    }

    private function library(array $overrides = []): string
    {
        return json_encode([[
            'Title' => 'Track',
            'Album' => 'Album',
            'Artist' => 'Singer',
            'Album Artist' => 'Album artist',
            'Apple Music Track Identifier' => 123456,
            'Track Duration' => 180000,
            'Genre' => 'Rock',
            'Release Date' => '2020-01-01T00:00:00Z',
            'Track Number On Album' => 3,
            'Disc Number Of Album' => 1,
            ...$overrides,
        ]], JSON_THROW_ON_ERROR);
    }

    private function export(string $daily, string $library): UploadedFile
    {
        $libraryZip = $this->zip(['Apple Music Library Tracks.json' => $library]);
        $mediaZip = $this->zip([
            'Apple Music Play Activity.csv' => $this->playCsv(),
            'Apple Music - Play History Daily Tracks.csv' => $daily,
            'Apple Music Library Tracks.json.zip' => $libraryZip,
            'unrelated.csv' => 'private account data',
        ]);

        return UploadedFile::fake()->createWithContent('export.zip', $this->zip(['Apple_Media_Services.zip' => $mediaZip]));
    }

    public function test_nested_apple_export_enriches_existing_listens_without_creating_duplicates(): void
    {
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'apple', 'file' => UploadedFile::fake()->createWithContent('play.csv', $this->playCsv()),
        ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0]);

        $this->post(route('listens.import'), [
            'source' => 'apple_zip', 'file' => $this->export($this->dailyCsv(), $this->library()),
        ])->assertSessionHas('import_result', ['imported' => 0, 'duplicates' => 1, 'matched' => 0]);

        $listen = Listen::query()->sole();
        $this->assertSame('apple', $listen->source);
        $this->assertSame('Album artist', $listen->artist_name);
        $this->assertSame('123456', $listen->track_metadata['additional_info']['catalogs']['apple']['id']);
        $this->assertSame('Singer', $listen->track_metadata['additional_info']['catalogs']['apple']['artist_name']);
        $this->assertSame([
            'end_reason' => 'NATURAL_END_OF_TRACK',
            'start_position_ms' => 0,
            'end_position_ms' => 60000,
            'event_id' => 'event-1',
            'catalog_id' => '123456',
            'track_artist' => 'Singer',
            'album_artist' => 'Album artist',
            'genre' => 'Rock',
            'release_date' => '2020-01-01T00:00:00Z',
            'track_number' => 3,
            'disc_number' => 1,
            'duration_ms' => 180000,
        ], $listen->track_metadata['additional_info']['apple_music']);
        $this->assertStringNotContainsString('private-ip', json_encode($listen->track_metadata, JSON_THROW_ON_ERROR));

        $this->post(route('listens.import'), [
            'source' => 'apple_zip', 'file' => $this->export($this->dailyCsv(), $this->library()),
        ])->assertSessionHas('import_result', ['imported' => 0, 'duplicates' => 1, 'matched' => 0]);
        $this->assertDatabaseCount('listens', 1);
    }

    public function test_daily_catalog_conflict_does_not_attach_unconfirmed_library_metadata(): void
    {
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'apple_zip', 'file' => $this->export($this->dailyCsv(), $this->library(['Apple Music Track Identifier' => 999999])),
        ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0]);

        $listen = Listen::query()->sole();
        $this->assertSame('Singer', $listen->artist_name);
        $this->assertSame('123456', $listen->track_metadata['additional_info']['apple_music']['catalog_id']);
        $this->assertArrayNotHasKey('genre', $listen->track_metadata['additional_info']['apple_music']);
    }

    public function test_library_metadata_and_isrc_are_stored_only_for_the_matching_catalog_identifier(): void
    {
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'apple_zip', 'file' => $this->export($this->dailyCsv(), $this->library(['ISRC' => 'US-ABC-24-12345'])),
        ])->assertSessionHasNoErrors();
        $listen = Listen::query()->sole();
        $this->get(route('listens.show', $listen->id))->assertInertia(fn (Assert $page) => $page
            ->where('catalogs.apple.id', '123456')->where('catalogs.apple.genre', 'Rock')
            ->where('catalogs.apple.isrc', 'USABC2412345')
            ->where('listen.track_metadata.additional_info.isrcs.0', 'USABC2412345'));
        $this->post(route('listens.catalog', $listen->id), [
            'provider' => 'apple', 'catalog_url' => 'https://music.apple.com/us/song/987',
            'catalog_track' => 'Different track', 'catalog_artist' => 'Different artist',
        ])->assertSessionHasNoErrors();
        DB::table('catalog_track_metadata')->delete();
        $this->artisan('catalogs:sync-metadata')->assertSuccessful();
        $this->assertDatabaseHas('catalog_track_metadata', ['provider' => 'apple', 'catalog_id' => '123456']);
        $this->get(route('listens.show', $listen->id))->assertInertia(fn (Assert $page) => $page
            ->where('catalogs.apple.id', '987')->where('catalogs.apple.isrc', null)
            ->missing('catalogs.apple.genre'));
    }

    public function test_conflicting_library_isrc_does_not_enrich_the_catalog_match(): void
    {
        $play = str_replace(
            ['Client IP Address', 'private-ip'],
            ['ISRC,Client IP Address', 'GBXYZ2412345,private-ip'],
            $this->playCsv(),
        );
        $zip = $this->zip([
            'Apple Music Play Activity.csv' => $play,
            'Apple Music - Play History Daily Tracks.csv' => $this->dailyCsv(),
            'Apple Music Library Tracks.json' => $this->library(['ISRC' => 'USABC2412345']),
        ]);
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'apple_zip', 'file' => UploadedFile::fake()->createWithContent('export.zip', $zip),
        ])->assertSessionHasNoErrors();
        $listen = Listen::query()->sole();
        $this->get(route('listens.show', $listen->id))->assertInertia(fn (Assert $page) => $page
            ->where('catalogs.apple.isrc', 'GBXYZ2412345')->missing('catalogs.apple.genre'));
    }

    public function test_ambiguous_daily_and_library_metadata_do_not_guess_an_artist(): void
    {
        $library = json_encode([
            json_decode($this->library(), true, 512, JSON_THROW_ON_ERROR)[0],
            json_decode($this->library(['Artist' => 'Other singer', 'Album Artist' => 'Other artist', 'Apple Music Track Identifier' => 654321]), true, 512, JSON_THROW_ON_ERROR)[0],
        ], JSON_THROW_ON_ERROR);
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'apple_zip', 'file' => $this->export($this->dailyCsv("20240101,654321,Other singer - Track\n"), $library),
        ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0]);

        $listen = Listen::query()->sole();
        $this->assertSame('Unknown artist', $listen->artist_name);
        $this->assertArrayNotHasKey('catalog_id', $listen->track_metadata['additional_info']['apple_music']);
        $this->assertArrayNotHasKey('genre', $listen->track_metadata['additional_info']['apple_music']);
    }

    public function test_ambiguous_daily_history_does_not_choose_the_only_library_candidate(): void
    {
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'apple_zip', 'file' => $this->export($this->dailyCsv("20240101,654321,Other singer - Track\n"), $this->library()),
        ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0]);

        $listen = Listen::query()->sole();
        $this->assertSame('Unknown artist', $listen->artist_name);
        $this->assertArrayNotHasKey('genre', $listen->track_metadata['additional_info']['apple_music']);
    }

    public function test_invalid_or_ambiguous_archive_rolls_back_without_creating_imports(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('listens.import'), [
            'source' => 'apple_zip', 'file' => UploadedFile::fake()->createWithContent('bad.zip', 'not a zip'),
        ])->assertSessionHasErrors('file');
        $duplicate = $this->zip([
            'one/Apple Music Play Activity.csv' => $this->playCsv(),
            'two/Apple Music Play Activity.csv' => $this->playCsv(),
        ]);
        $this->post(route('listens.import'), [
            'source' => 'apple_zip', 'file' => UploadedFile::fake()->createWithContent('duplicate.zip', $duplicate),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('listens', 0);
        $this->assertDatabaseCount('import_batches', 0);
    }

    public function test_direct_playback_archive_without_optional_files_imports_multiline_csv(): void
    {
        $csv = str_replace('private-ip', "\"private\nvalue\"", $this->playCsv());
        $archive = $this->zip(['Apple Music Play Activity.csv' => $csv]);

        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'apple_zip', 'file' => UploadedFile::fake()->createWithContent('media.zip', $archive),
        ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0]);

        $this->assertSame('Unknown artist', Listen::query()->sole()->artist_name);
    }

    public function test_library_duration_mismatch_does_not_attach_library_metadata(): void
    {
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'apple_zip', 'file' => $this->export($this->dailyCsv(), $this->library(['Track Duration' => 190000])),
        ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0]);

        $listen = Listen::query()->sole();
        $this->assertSame('Singer', $listen->artist_name);
        $this->assertArrayNotHasKey('genre', $listen->track_metadata['additional_info']['apple_music']);
    }

    public function test_unique_library_entry_provides_catalog_id_without_daily_history(): void
    {
        $archive = $this->zip([
            'Apple Music Play Activity.csv' => $this->playCsv(),
            'Apple Music Library Tracks.json' => $this->library(),
        ]);
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'apple_zip', 'file' => UploadedFile::fake()->createWithContent('media.zip', $archive),
        ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0]);

        $listen = Listen::query()->sole();
        $this->assertSame('Album artist', $listen->artist_name);
        $this->assertSame('123456', $listen->track_metadata['additional_info']['apple_music']['catalog_id']);
    }

    public function test_duplicate_enrichment_preserves_existing_match_and_other_users_listens(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        foreach ([$first, $second] as $user) {
            $this->actingAs($user)->post(route('listens.import'), [
                'source' => 'apple', 'file' => UploadedFile::fake()->createWithContent('play.csv', $this->playCsv()),
            ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0]);
        }
        $listen = Listen::query()->where('user_id', $first->id)->sole();
        $metadata = $listen->track_metadata;
        $metadata['additional_info']['recording_mbid'] = 'f7beeb57-d895-4bd0-9d8b-476af8dc9c7d';
        $listen->update([
            'artist_name' => 'Manually chosen artist',
            'recording_mbid' => 'f7beeb57-d895-4bd0-9d8b-476af8dc9c7d',
            'track_metadata' => $metadata,
        ]);
        $other = Listen::query()->where('user_id', $second->id)->sole();
        $otherMetadata = $other->track_metadata;

        $this->actingAs($first)->post(route('listens.import'), [
            'source' => 'apple_zip', 'file' => $this->export($this->dailyCsv(), $this->library()),
        ])->assertSessionHas('import_result', ['imported' => 0, 'duplicates' => 1, 'matched' => 0]);

        $listen->refresh();
        $this->assertSame('Manually chosen artist', $listen->artist_name);
        $this->assertSame('f7beeb57-d895-4bd0-9d8b-476af8dc9c7d', $listen->recording_mbid);
        $this->assertSame('Rock', $listen->track_metadata['additional_info']['apple_music']['genre']);
        $this->assertSame($otherMetadata, $other->fresh()->track_metadata);
    }

    public function test_invalid_library_and_incomplete_playback_roll_back_import(): void
    {
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'apple_zip', 'file' => $this->export($this->dailyCsv(), '[{"Title":'),
        ])->assertSessionHasErrors('file');

        $archive = $this->zip(['Apple Music Play Activity.csv' => $this->playCsv().'"incomplete']);
        $this->post(route('listens.import'), [
            'source' => 'apple_zip', 'file' => UploadedFile::fake()->createWithContent('media.zip', $archive),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('listens', 0);
        $this->assertDatabaseCount('import_batches', 0);
    }

    public function test_oversized_quoted_playback_record_is_rejected(): void
    {
        $csv = $this->playCsv().'"'.bin2hex(random_bytes(512 * 1024)).'"';
        $archive = $this->zip(['Apple Music Play Activity.csv' => $csv]);

        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'apple_zip', 'file' => UploadedFile::fake()->createWithContent('media.zip', $archive),
        ])->assertSessionHasErrors(['file' => 'An Apple CSV record is too large.']);

        $this->assertDatabaseCount('listens', 0);
    }
}
