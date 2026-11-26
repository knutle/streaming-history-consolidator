<?php

namespace Tests\Feature;

use App\Models\Listen;
use App\Models\User;
use App\Services\MusicBrainzMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MusicBrainzSetupTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/musicbrainz-'.bin2hex(random_bytes(8)));
        $this->csv = storage_path('framework/testing/canonical-'.bin2hex(random_bytes(8)).'.csv');
        config(['musicbrainz.setup_path' => $this->root]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            File::deleteDirectory($this->root);
        }
        if (is_dir($this->root.'-imports')) {
            File::deleteDirectory($this->root.'-imports');
        }
        if (is_file($this->csv)) {
            unlink($this->csv);
        }
        parent::tearDown();
    }

    public function test_setup_initializes_once_and_reuses_an_existing_database(): void
    {
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            if (is_array($command) && ($command[0] ?? null) === 'git') {
                File::ensureDirectoryExists($this->root.'/docker/default');
            }
            if (is_array($command) && in_array('psql', $command, true) && in_array('streaming_history', $command, true)) {
                return Process::result(output: "t\n");
            }

            return Process::result();
        });

        $this->artisan('musicbrainz:setup', ['--yes' => true])
            ->expectsQuestion('Are you planning to use the MetaBrainz data for commercial or business purposes? Enter y or n', 'n')
            ->expectsConfirmation('Continue after reviewing the MetaBrainz data-use information?', 'yes')
            ->assertSuccessful();
        $connection = json_decode(File::get($this->root.'/connection.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('streaming_history', $connection['username']);
        $this->assertSame('55432', $connection['port']);
        $this->assertSame(48, strlen($connection['password']));
        $this->assertSame(0600, fileperms($this->root.'/connection.json') & 0777);
        Process::assertRan(fn (PendingProcess $process) => is_array($process->command)
            && in_array('createdb.sh', $process->command, true)
            && in_array('-fetch', $process->command, true)
            && $process->input === "n\nx");

        $this->artisan('musicbrainz:setup', ['--yes' => true])->assertSuccessful();
        Process::assertRanTimes(fn (PendingProcess $process) => is_array($process->command)
            && in_array('createdb.sh', $process->command, true), 1);
    }

    public function test_setup_refuses_to_replace_an_existing_directory(): void
    {
        File::ensureDirectoryExists($this->root);
        File::put($this->root.'/existing', 'keep');
        Process::preventStrayProcesses();
        Process::fake(['*' => Process::result()]);
        $this->artisan('musicbrainz:setup', ['--yes' => true])->assertFailed();
        $this->assertSame('keep', File::get($this->root.'/existing'));
        Process::assertNotRan(fn (PendingProcess $process) => is_array($process->command)
            && in_array('createdb.sh', $process->command, true));
    }

    public function test_setup_refuses_to_replace_existing_database_or_dump_volumes(): void
    {
        Process::preventStrayProcesses();
        $project = 'shc-mb-'.substr(hash('sha256', base_path()), 0, 12);

        foreach (['pgdata', 'dbdump'] as $volume) {
            Process::fake(function (PendingProcess $process) use ($project, $volume) {
                if (is_array($process->command) && array_slice($process->command, 0, 3) === ['docker', 'volume', 'ls']) {
                    return Process::result(output: "{$project}_{$volume}\n");
                }

                return Process::result();
            });

            $this->artisan('musicbrainz:setup', ['--yes' => true])
                ->expectsOutputToContain('An existing MusicBrainz volume')
                ->assertFailed();
            $this->assertDirectoryDoesNotExist($this->root);
            Process::assertNotRan(fn (PendingProcess $process) => is_array($process->command)
                && in_array('createdb.sh', $process->command, true));
        }
    }

    public function test_failed_import_does_not_mark_the_database_ready(): void
    {
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            if (is_array($command) && ($command[0] ?? null) === 'git') {
                File::ensureDirectoryExists($this->root.'/docker/default');
            }
            if (is_array($command) && in_array('createdb.sh', $command, true)) {
                return Process::result(errorOutput: 'Download failed', exitCode: 1);
            }

            return Process::result();
        });
        $this->artisan('musicbrainz:setup', ['--yes' => true])
            ->expectsQuestion('Are you planning to use the MetaBrainz data for commercial or business purposes? Enter y or n', 'y')
            ->expectsConfirmation('Continue after reviewing the MetaBrainz data-use information?', 'yes')
            ->assertFailed();
        $this->assertFileDoesNotExist($this->root.'/connection.json');
        Process::assertRan(fn (PendingProcess $process) => is_array($process->command)
            && in_array('createdb.sh', $process->command, true)
            && $process->input === "y\nx");
    }

    public function test_setup_requires_an_explicit_answer_before_downloading(): void
    {
        Process::preventStrayProcesses();
        Process::fake(['*' => Process::result()]);

        $this->artisan('musicbrainz:setup', ['--yes' => true])
            ->expectsQuestion('Are you planning to use the MetaBrainz data for commercial or business purposes? Enter y or n', '')
            ->assertFailed();
        $this->assertDirectoryDoesNotExist($this->root);
        Process::assertNotRan(fn (PendingProcess $process) => is_array($process->command)
            && ($process->command[0] ?? null) === 'git');
    }

    public function test_upload_validation_accepts_one_gigabyte_but_not_more(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post(route('listens.import'), [
            'source' => 'spotify', 'file' => UploadedFile::fake()->createWithContent('history.json', '[]')->size(1048576),
        ])->assertSessionHasNoErrors();
        $this->post(route('listens.import'), [
            'source' => 'spotify', 'file' => UploadedFile::fake()->createWithContent('history.json', '[]')->size(1048577),
        ])->assertSessionHasErrors('file');
    }

    public function test_canonical_csv_populates_a_database_used_for_listen_matching(): void
    {
        $this->canonicalCsv([
            ['11111111-1111-4111-8111-111111111111,aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Artist & Guest', '33333333-3333-4333-8333-333333333333', 'Album, Deluxe', '22222222-2222-4222-8222-222222222222', 'Track'],
            ['11111111-1111-4111-8111-111111111111,aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Artist & Guest', '44444444-4444-4444-8444-444444444444', 'Album, Deluxe', '22222222-2222-4222-8222-222222222222', 'Track'],
            ['11111111-1111-4111-8111-111111111111', 'Artist & Guest', '55555555-5555-4555-8555-555555555555', 'Other album', '66666666-6666-4666-8666-666666666666', 'Track'],
            ['11111111-1111-4111-8111-111111111111', 'Artist & Guest', '55555555-5555-4555-8555-555555555555', 'Other album', '77777777-7777-4777-8777-777777777777', 'Other track'],
            ['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'Ärtist', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'Single album', 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'Single'],
        ]);
        Process::preventStrayProcesses();
        $this->artisan('musicbrainz:setup', ['--canonical-csv' => $this->csv])->assertSuccessful();
        $connection = json_decode(File::get($this->root.'/connection.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('canonical', $connection['format']);
        $this->assertSame(0600, fileperms($connection['database']) & 0777);
        $this->artisan('musicbrainz:setup')->assertSuccessful();

        config(['musicbrainz.enabled' => true, 'musicbrainz.format' => 'canonical', 'database.connections.musicbrainz' => [
            'driver' => 'sqlite', 'database' => $connection['database'], 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('musicbrainz');
        $row = [
            'ts' => '2024-01-01T12:00:00Z', 'ms_played' => 60000,
            'master_metadata_track_name' => 'Track', 'master_metadata_album_artist_name' => 'artist & guest',
            'master_metadata_album_album_name' => 'Album, Deluxe',
        ];
        $this->actingAs(User::factory()->create())->post(route('listens.import'), [
            'source' => 'spotify', 'file' => UploadedFile::fake()->createWithContent('history.json', json_encode([$row], JSON_THROW_ON_ERROR)),
        ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 1]);
        $metadata = Listen::query()->sole()->track_metadata;
        $this->assertSame('artist & guest', $metadata['artist_name']);
        $this->assertSame('22222222-2222-4222-8222-222222222222', $metadata['additional_info']['recording_mbid']);
        $this->assertSame(['11111111-1111-4111-8111-111111111111', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'], $metadata['additional_info']['artist_mbids']);
        $this->assertArrayNotHasKey('release_mbid', $metadata['additional_info']);
        $this->assertNull(app(MusicBrainzMatcher::class)->match('Track', 'Artist & Guest', ''));
        $this->assertNull(app(MusicBrainzMatcher::class)->match('Track', 'Artist & Guest', 'Wrong album'));
        $this->assertSame('55555555-5555-4555-8555-555555555555', app(MusicBrainzMatcher::class)->match('Other track', '', 'Other album')['release_mbid']);

        $single = [...$row, 'ts' => '2024-01-03T12:00:00Z', 'master_metadata_track_name' => 'Single',
            'master_metadata_album_artist_name' => 'ärtIST', 'master_metadata_album_album_name' => 'Single album'];
        $this->post(route('listens.import'), [
            'source' => 'spotify', 'file' => UploadedFile::fake()->createWithContent('history.json', json_encode([$single], JSON_THROW_ON_ERROR)),
        ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 1]);
        $this->assertDatabaseHas('music_artists', [
            'mbid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'name' => 'Ärtist', 'name_source' => 'canonical_credit',
        ]);
        $this->assertDatabaseHas('music_releases', ['mbid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'name' => 'Single album']);
        $this->get(route('artists.show', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('entity.name', 'Ärtist')->where('entity.nameSource', 'canonical_credit'));

        $unmatched = [...$row, 'ts' => '2024-01-02T12:00:00Z', 'master_metadata_album_album_name' => 'Wrong album'];
        $this->post(route('listens.import'), [
            'source' => 'spotify', 'file' => UploadedFile::fake()->createWithContent('history.json', json_encode([$unmatched], JSON_THROW_ON_ERROR)),
        ])->assertSessionHas('import_result', ['imported' => 1, 'duplicates' => 0, 'matched' => 0]);
        $listen = Listen::query()->whereNull('recording_mbid')->sole();
        $this->get(route('listens.show', ['listen' => $listen->id, 'q' => 'Tra']))->assertOk();
        $this->post(route('listens.match', $listen->id), [
            'recording_mbid' => '22222222-2222-4222-8222-222222222222', 'track_name' => 'Track', 'artist_name' => 'Artist & Guest',
        ])->assertSessionHas('match_result', 1);
        $this->assertSame('22222222-2222-4222-8222-222222222222', $listen->fresh()->recording_mbid);
    }

    public function test_canonical_connection_is_discovered_and_external_settings_take_precedence(): void
    {
        $this->canonicalCsv([
            ['11111111-1111-4111-8111-111111111111', 'Artist', '33333333-3333-4333-8333-333333333333', 'Album', '22222222-2222-4222-8222-222222222222', 'Track'],
        ]);
        $this->artisan('musicbrainz:setup', ['--canonical-csv' => $this->csv])->assertSuccessful();

        $command = [PHP_BINARY, 'artisan', 'tinker', '--execute=echo config("database.connections.musicbrainz.driver")."|".(int) config("musicbrainz.enabled")."|".config("database.connections.musicbrainz.database");'];
        $env = ['APP_ENV' => 'testing', 'MUSICBRAINZ_SETUP_PATH' => $this->root];
        $local = Process::env($env)->run($command);
        $this->assertTrue($local->successful(), $local->errorOutput());
        $this->assertSame('sqlite|1|'.realpath($this->root).'/canonical.sqlite', trim($local->output()));

        $external = Process::env([...$env, 'MUSICBRAINZ_DB_HOST' => 'external.example'])->run($command);
        $this->assertTrue($external->successful(), $external->errorOutput());
        $this->assertSame('pgsql|1|musicbrainz_db', trim($external->output()));
    }

    public function test_canonical_database_is_accessible_when_setup_was_created_at_another_path(): void
    {
        $this->canonicalCsv([
            ['11111111-1111-4111-8111-111111111111', 'Artist', '33333333-3333-4333-8333-333333333333', 'Album', '22222222-2222-4222-8222-222222222222', 'Track'],
        ]);
        $this->artisan('musicbrainz:setup', ['--canonical-csv' => $this->csv])->assertSuccessful();
        File::put($this->root.'/connection.json', json_encode([
            'format' => 'canonical',
            'database' => '/another-machine/canonical.sqlite',
        ], JSON_THROW_ON_ERROR));

        $command = [PHP_BINARY, 'artisan', 'tinker', '--execute=echo DB::connection("musicbrainz")->table("canonical_recordings")->count();'];
        $result = Process::env(['APP_ENV' => 'testing', 'MUSICBRAINZ_SETUP_PATH' => $this->root])->run($command);

        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertSame('1', trim($result->output()));
        $this->artisan('musicbrainz:setup')->assertSuccessful();
    }

    public function test_redirect_csv_is_rejected_without_creating_a_setup_directory(): void
    {
        File::put($this->csv, "recording_mbid,canonical_recording_mbid,canonical_release_mbid\n");

        $this->artisan('musicbrainz:setup', ['--canonical-csv' => $this->csv])
            ->expectsOutputToContain('redirect CSVs cannot be used')
            ->assertFailed();
        $this->assertDirectoryDoesNotExist($this->root);
    }

    public function test_canonical_option_requires_a_csv_path(): void
    {
        Process::preventStrayProcesses();

        $this->artisan('musicbrainz:setup', ['--canonical-csv' => ''])
            ->expectsOutputToContain('Provide the path')
            ->assertFailed();
        $this->assertDirectoryDoesNotExist($this->root);
    }

    public function test_latest_canonical_dump_is_downloaded_verified_and_imported(): void
    {
        $this->canonicalCsv([
            ['11111111-1111-4111-8111-111111111111', 'Artist', '33333333-3333-4333-8333-333333333333', 'Album', '22222222-2222-4222-8222-222222222222', 'Track'],
        ]);
        $base = 'https://data.metabrainz.org/pub/musicbrainz/canonical_data/';
        $name = 'musicbrainz-canonical-dump-20261117-080003';
        $url = $base.$name.'/'.$name.'.tar.zst';
        Http::preventStrayRequests();
        Http::fake([
            $base => Http::response('<a href="'.$name.'/">latest</a><a href="musicbrainz-canonical-dump-20261103-080003/">older</a>'),
            $url.'.sha256' => Http::response(hash('sha256', 'archive')),
            $url => Http::response('archive'),
        ]);
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            if (is_array($process->command) && in_array('extract-canonical', $process->command, true)) {
                File::copy($this->csv, $process->command[8]);
            }

            return Process::result();
        });

        $this->artisan('musicbrainz:setup', ['--download-canonical' => true, '--yes' => true])
            ->expectsQuestion('Are you planning to use the MetaBrainz data for commercial or business purposes? Enter y or n', 'n')
            ->expectsConfirmation('Continue after reviewing the MetaBrainz data-use information?', 'yes')
            ->assertSuccessful();

        $this->assertSame(File::get($this->csv), File::get($this->root.'-imports/canonical_musicbrainz_data.csv'));
        $this->assertFileExists($this->root.'/connection.json');
        Http::assertSent(fn ($request) => $request->url() === $url);
        Process::assertRan(fn (PendingProcess $process) => is_array($process->command)
            && in_array($name.'/canonical/canonical_musicbrainz_data.csv', $process->command, true));
    }

    public function test_canonical_download_rejects_bad_checksum_without_saving_a_csv(): void
    {
        $base = 'https://data.metabrainz.org/pub/musicbrainz/canonical_data/';
        $name = 'musicbrainz-canonical-dump-20261117-080003';
        $url = $base.$name.'/'.$name.'.tar.zst';
        Http::preventStrayRequests();
        Http::fake([
            $base => Http::response('<a href="'.$name.'/">latest</a>'),
            $url.'.sha256' => Http::response(hash('sha256', 'different')),
            $url => Http::response('archive'),
        ]);
        Process::preventStrayProcesses();
        Process::fake(['*' => Process::result()]);

        $this->artisan('musicbrainz:setup', ['--download-canonical' => true, '--yes' => true])
            ->expectsQuestion('Are you planning to use the MetaBrainz data for commercial or business purposes? Enter y or n', 'n')
            ->expectsConfirmation('Continue after reviewing the MetaBrainz data-use information?', 'yes')
            ->expectsOutputToContain('failed SHA-256 verification')
            ->assertFailed();
        $this->assertFileDoesNotExist($this->root.'-imports/canonical_musicbrainz_data.csv');
        $this->assertSame([], File::files($this->root.'-imports'));
        $this->assertDirectoryDoesNotExist($this->root);
        Process::assertNotRan(fn (PendingProcess $process) => is_array($process->command)
            && in_array('extract-canonical', $process->command, true));
    }

    public function test_canonical_download_does_not_replace_an_existing_csv(): void
    {
        File::ensureDirectoryExists($this->root.'-imports');
        File::put($this->root.'-imports/canonical_musicbrainz_data.csv', 'keep');
        Http::preventStrayRequests();
        Process::preventStrayProcesses();

        $this->artisan('musicbrainz:setup', ['--download-canonical' => true, '--yes' => true])
            ->expectsQuestion('Are you planning to use the MetaBrainz data for commercial or business purposes? Enter y or n', 'n')
            ->expectsConfirmation('Continue after reviewing the MetaBrainz data-use information?', 'yes')
            ->assertFailed();
        $this->assertSame('keep', File::get($this->root.'-imports/canonical_musicbrainz_data.csv'));
        Http::assertNothingSent();
    }

    public function test_canonical_download_reports_http_failures_without_importing(): void
    {
        $base = 'https://data.metabrainz.org/pub/musicbrainz/canonical_data/';
        Http::preventStrayRequests();
        Http::fake([$base => Http::response('Unavailable', 503)]);
        Process::preventStrayProcesses();
        Process::fake(['*' => Process::result()]);

        $this->artisan('musicbrainz:setup', ['--download-canonical' => true, '--yes' => true])
            ->expectsQuestion('Are you planning to use the MetaBrainz data for commercial or business purposes? Enter y or n', 'n')
            ->expectsConfirmation('Continue after reviewing the MetaBrainz data-use information?', 'yes')
            ->assertFailed();
        $this->assertFileDoesNotExist($this->root.'-imports/canonical_musicbrainz_data.csv');
        $this->assertDirectoryDoesNotExist($this->root);
        Http::assertSent(fn ($request) => $request->url() === $base);
    }

    public function test_canonical_download_reports_extraction_failures_without_saving_a_csv(): void
    {
        $base = 'https://data.metabrainz.org/pub/musicbrainz/canonical_data/';
        $name = 'musicbrainz-canonical-dump-20261117-080003';
        $url = $base.$name.'/'.$name.'.tar.zst';
        Http::preventStrayRequests();
        Http::fake([
            $base => Http::response('<a href="'.$name.'/">latest</a>'),
            $url.'.sha256' => Http::response(hash('sha256', 'archive')),
            $url => Http::response('archive'),
        ]);
        Process::preventStrayProcesses();
        Process::fake(fn (PendingProcess $process) => is_array($process->command) && in_array('extract-canonical', $process->command, true)
            ? Process::result(errorOutput: 'Missing CSV', exitCode: 1)
            : Process::result());

        $this->artisan('musicbrainz:setup', ['--download-canonical' => true, '--yes' => true])
            ->expectsQuestion('Are you planning to use the MetaBrainz data for commercial or business purposes? Enter y or n', 'n')
            ->expectsConfirmation('Continue after reviewing the MetaBrainz data-use information?', 'yes')
            ->expectsOutputToContain('Could not extract canonical metadata')
            ->assertFailed();
        $this->assertSame([], File::files($this->root.'-imports'));
        $this->assertDirectoryDoesNotExist($this->root);
        Process::assertRan(fn (PendingProcess $process) => is_array($process->command)
            && in_array('extract-canonical', $process->command, true));
    }

    public function test_canonical_download_rejects_conflicting_options(): void
    {
        Http::preventStrayRequests();
        Process::preventStrayProcesses();

        $this->artisan('musicbrainz:setup', ['--download-canonical' => true, '--canonical-csv' => $this->csv])
            ->expectsOutputToContain('Choose either')
            ->assertFailed();
        $this->assertDirectoryDoesNotExist($this->root);
        Http::assertNothingSent();
    }

    public function test_invalid_canonical_csv_does_not_mark_setup_complete(): void
    {
        $this->canonicalCsv([
            ['11111111-1111-4111-8111-111111111111', 'Artist', 'invalid', 'Album', '22222222-2222-4222-8222-222222222222', 'Track'],
        ]);
        $this->artisan('musicbrainz:setup', ['--canonical-csv' => $this->csv])->assertFailed();
        $this->assertFileDoesNotExist($this->root.'/connection.json');
        $this->artisan('musicbrainz:setup', ['--canonical-csv' => $this->csv])->assertFailed();
    }

    private function canonicalCsv(array $rows): void
    {
        $file = fopen($this->csv, 'wb');
        fputcsv($file, ['artist_mbids', 'artist_credit_name', 'release_mbid', 'release_name', 'recording_mbid', 'recording_name'], escape: '');
        foreach ($rows as $row) {
            fputcsv($file, $row, escape: '');
        }
        fclose($file);
    }
}
