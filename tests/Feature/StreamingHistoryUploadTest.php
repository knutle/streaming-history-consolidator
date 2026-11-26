<?php

namespace Tests\Feature;

use App\Models\Listen;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;
use ZipArchive;

class StreamingHistoryUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['musicbrainz.enabled' => false]);
    }

    private function history(string $timestamp = '2024-01-01T12:00:00Z'): array
    {
        return [
            'ts' => $timestamp, 'ms_played' => 60000,
            'master_metadata_track_name' => 'Track',
            'master_metadata_album_artist_name' => 'Artist',
            'master_metadata_album_album_name' => 'Album',
            'spotify_track_uri' => '',
        ];
    }

    private function jsonFile(string $name, array $data): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function zip(array $files): UploadedFile
    {
        $temp = tmpfile();
        $this->assertNotFalse($temp);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(stream_get_meta_data($temp)['uri'], ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($files as $name => $contents) {
            $this->assertTrue($zip->addFromString($name, $contents));
        }
        $this->assertTrue($zip->close());
        $contents = file_get_contents(stream_get_meta_data($temp)['uri']);
        fclose($temp);
        $this->assertNotFalse($contents);

        return UploadedFile::fake()->createWithContent('export.zip', $contents);
    }

    #[TestWith([30, 7200])]
    #[TestWith([0, 0])]
    #[TestWith([9000, 9000])]
    public function test_import_increases_short_execution_limits_without_reducing_existing_limits(int $initial, int $expected): void
    {
        $original = ini_get('max_execution_time');
        $this->assertNotFalse($original);
        $this->assertTrue(set_time_limit($initial));

        try {
            $this->actingAs(User::factory()->create())->post(route('listens.import'), ['files' => [
                $this->jsonFile('history.json', [$this->history()]),
            ]])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0, 'errors' => []]);
            $this->assertSame((string) $expected, ini_get('max_execution_time'));
            $this->assertDatabaseCount('listens', 1);
        } finally {
            set_time_limit((int) $original);
        }
    }

    public function test_multiple_files_enrich_and_reimport_without_changing_event_identity(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $uri = 'spotify:track:1234567890123456789012';
        $library = ['tracks' => [['track' => 'Track', 'artist' => 'Artist', 'album' => 'Album', 'uri' => $uri]]];

        $this->actingAs($user)->post(route('listens.import'), ['files' => [
            $this->jsonFile('renamed.json', [$this->history()]),
            $this->jsonFile('YourLibrary.json', $library),
        ]])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0, 'errors' => []]);
        $this->assertSame('1234567890123456789012', Listen::query()->sole()->track_metadata['additional_info']['catalogs']['spotify']['id']);
        $this->assertTrue(Listen::query()->sole()->track_metadata['additional_info']['spotify']['saved_in_library_export']);

        $this->post(route('listens.import'), ['files' => [
            $this->jsonFile('renamed.json', [$this->history()]),
        ]])->assertSessionHas('import_result', ['imported' => 0, 'duplicates' => 1, 'matched' => 0, 'errors' => []]);
        $this->assertDatabaseCount('listens', 1);
        Http::assertNothingSent();
    }

    public function test_conflicting_library_and_playlist_ids_are_not_associated(): void
    {
        $library = ['tracks' => [['track' => 'Track', 'artist' => 'Artist', 'album' => 'Album', 'uri' => 'spotify:track:1234567890123456789012']]];
        $playlist = ['playlists' => [['items' => [['track' => [
            'trackName' => 'Track', 'artistName' => 'Artist', 'albumName' => 'Album', 'trackUri' => 'spotify:track:1234567890123456789013',
        ]]]]]];

        $this->actingAs(User::factory()->create())->post(route('listens.import'), ['files' => [
            $this->jsonFile('Streaming_History_Audio_0.json', [$this->history()]),
            $this->jsonFile('YourLibrary.json', $library),
            $this->jsonFile('Playlist1.json', $playlist),
        ]])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0, 'errors' => []]);
        $this->assertArrayNotHasKey('catalogs', Listen::query()->sole()->track_metadata['additional_info']);
    }

    public function test_zip_history_and_metadata_are_combined_and_unsupported_members_reported(): void
    {
        $uri = 'spotify:track:1234567890123456789012';
        $zip = $this->zip([
            'Spotify Extended Streaming History/Streaming_History_Audio_0.json' => json_encode([$this->history()], JSON_THROW_ON_ERROR),
            'Spotify Account Data/Playlist1.json' => json_encode(['playlists' => [['items' => [['track' => [
                'trackName' => 'Track', 'artistName' => 'Artist', 'albumName' => 'Album', 'trackUri' => $uri,
            ]]]]]], JSON_THROW_ON_ERROR),
            'Spotify Account Data/Identity.json' => '{}',
        ]);

        $response = $this->actingAs(User::factory()->create())->post(route('listens.import'), ['files' => [$zip]]);

        $response->assertSessionHas('import_result', [
            'imported' => 1, 'duplicates' => 0, 'matched' => 0,
            'errors' => [['file' => 'export.zip / Spotify Account Data/Identity.json', 'message' => 'Unsupported archive file; it was not imported.']],
        ]);
        $this->assertSame('1234567890123456789012', Listen::query()->sole()->track_metadata['additional_info']['catalogs']['spotify']['id']);
        $this->assertTrue(Listen::query()->sole()->track_metadata['additional_info']['spotify']['in_playlist_export']);
    }

    public function test_bad_files_are_reported_and_failed_history_rolls_back_without_blocking_other_files(): void
    {
        $badHistory = '['.json_encode($this->history('2024-01-02T12:00:00Z'), JSON_THROW_ON_ERROR).',';
        $apple = "Song Name,Artist Name,Event Start Timestamp,Play Duration Milliseconds\nTrack,Artist,2024-01-03T12:00:00Z,60000\n";

        $response = $this->actingAs(User::factory()->create())->post(route('listens.import'), ['files' => [
            $this->jsonFile('history.json', [$this->history()]),
            UploadedFile::fake()->createWithContent('bad.json', $badHistory),
            UploadedFile::fake()->createWithContent('activity.csv', $apple),
            $this->jsonFile('StreamingHistory_music_0.json', [['endTime' => '2024-01-01 12:00', 'msPlayed' => 60000]]),
            UploadedFile::fake()->createWithContent('unknown.txt', 'unsupported'),
        ]]);

        $response->assertSessionHas('import_result', function ($result) {
            return $result['imported'] === 2 && count($result['errors']) === 3
                && $result['errors'][0]['file'] === 'StreamingHistory_music_0.json'
                && str_contains($result['errors'][0]['message'], 'minute-precision')
                && $result['errors'][1]['file'] === 'unknown.txt'
                && $result['errors'][2]['file'] === 'bad.json';
        });
        $this->assertDatabaseCount('listens', 2);
        $this->assertDatabaseCount('import_batches', 2);
    }

    public function test_reimport_enriches_playback_details_without_storing_private_fields(): void
    {
        $row = $this->history();
        $this->actingAs(User::factory()->create())->post(route('listens.import'), ['files' => [
            $this->jsonFile('history.json', [$row]),
        ]])->assertSessionHasNoErrors();
        $row += ['reason_start' => 'trackdone', 'reason_end' => 'endplay', 'shuffle' => false, 'skipped' => true, 'offline' => false, 'ip_addr' => 'private'];

        $this->post(route('listens.import'), ['files' => [$this->jsonFile('history.json', [$row])]])
            ->assertSessionHas('import_result', ['imported' => 0, 'duplicates' => 1, 'matched' => 0, 'errors' => []]);

        $metadata = Listen::query()->sole()->track_metadata;
        $this->assertSame([
            'reason_start' => 'trackdone', 'reason_end' => 'endplay',
            'shuffle' => false, 'skipped' => true, 'offline' => false,
        ], $metadata['additional_info']['spotify']);
        $this->assertStringNotContainsString('private', json_encode($metadata, JSON_THROW_ON_ERROR));
    }

    public function test_invalid_metadata_does_not_contribute_partial_matches(): void
    {
        $playlist = ['playlists' => [
            ['items' => [['track' => [
                'trackName' => 'Track', 'artistName' => 'Artist', 'albumName' => 'Album', 'trackUri' => 'spotify:track:1234567890123456789012',
            ]]]],
            ['items' => 'invalid'],
        ]];

        $response = $this->actingAs(User::factory()->create())->post(route('listens.import'), ['files' => [
            $this->jsonFile('history.json', [$this->history()]),
            $this->jsonFile('Playlist1.json', $playlist),
        ]]);

        $response->assertSessionHas('import_result', [
            'imported' => 1, 'duplicates' => 0, 'matched' => 0,
            'errors' => [['file' => 'Playlist1.json', 'message' => 'A Spotify playlist has an invalid items array.']],
        ]);
        $this->assertArrayNotHasKey('catalogs', Listen::query()->sole()->track_metadata['additional_info']);
    }

    public function test_existing_spotify_uri_uses_exact_id_for_export_presence(): void
    {
        $row = $this->history();
        $row['spotify_track_uri'] = 'spotify:track:1234567890123456789012';
        $library = ['tracks' => [
            ['track' => '', 'artist' => '', 'album' => '', 'uri' => $row['spotify_track_uri']],
        ]];

        $this->actingAs(User::factory()->create())->post(route('listens.import'), ['files' => [
            $this->jsonFile('history.json', [$row]), $this->jsonFile('YourLibrary.json', $library),
        ]])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0, 'errors' => []]);

        $this->assertTrue(Listen::query()->sole()->track_metadata['additional_info']['spotify']['saved_in_library_export']);
    }

    public function test_catalog_uri_and_url_for_the_same_track_are_not_treated_as_ambiguous(): void
    {
        $library = ['tracks' => [['track' => 'Track', 'artist' => 'Artist', 'album' => 'Album', 'uri' => 'spotify:track:1234567890123456789012']]];
        $playlist = ['playlists' => [['items' => [['track' => [
            'trackName' => 'Track', 'artistName' => 'Artist', 'albumName' => 'Album', 'trackUri' => 'https://open.spotify.com/track/1234567890123456789012',
        ]]]]]];

        $this->actingAs(User::factory()->create())->post(route('listens.import'), ['files' => [
            $this->jsonFile('history.json', [$this->history()]),
            $this->jsonFile('YourLibrary.json', $library),
            $this->jsonFile('Playlist1.json', $playlist),
        ]])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0, 'errors' => []]);

        $info = Listen::query()->sole()->track_metadata['additional_info'];
        $this->assertSame('1234567890123456789012', $info['catalogs']['spotify']['id']);
        $this->assertTrue($info['spotify']['saved_in_library_export']);
        $this->assertTrue($info['spotify']['in_playlist_export']);
    }

    public function test_wrong_library_track_structure_is_reported(): void
    {
        $this->actingAs(User::factory()->create())->post(route('listens.import'), ['files' => [
            $this->jsonFile('YourLibrary.json', ['tracks' => [['unexpected' => 'value']]]),
        ]])->assertSessionHas('import_result', [
            'imported' => 0, 'duplicates' => 0, 'matched' => 0,
            'errors' => [['file' => 'YourLibrary.json', 'message' => 'A Spotify metadata track has an invalid structure.']],
        ]);
        $this->assertDatabaseCount('listens', 0);
    }

    public function test_apple_zip_and_invalid_zip_are_detected_independently(): void
    {
        $apple = "Song Name,Artist Name,Event Start Timestamp,Play Duration Milliseconds\nTrack,Artist,2024-01-03T12:00:00Z,60000\n";
        $zip = $this->zip(['Apple Music/Apple Music Play Activity.csv' => $apple]);

        $this->actingAs(User::factory()->create())->post(route('listens.import'), ['files' => [
            $zip, UploadedFile::fake()->createWithContent('invalid.zip', 'invalid'),
        ]])->assertSessionHas('import_result', [
            'imported' => 1, 'duplicates' => 0, 'matched' => 0,
            'errors' => [['file' => 'invalid.zip', 'message' => 'The file is not a valid ZIP archive.']],
        ]);
        $this->assertSame('apple', Listen::query()->sole()->source);
    }
}
