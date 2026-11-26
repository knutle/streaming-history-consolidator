<?php

namespace App\Console\Commands;

use App\Services\CanonicalMusicBrainzDownloader;
use App\Services\CanonicalMusicBrainzImporter;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

class SetupMusicBrainz extends Command
{
    protected $signature = 'musicbrainz:setup
        {--yes : Confirm the disk space requirement}
        {--download-canonical : Download and import the latest canonical metadata CSV}
        {--canonical-csv= : Import an extracted canonical_musicbrainz_data.csv instead of the full mirror}';

    protected $description = 'Download and initialize an isolated local MusicBrainz database using Docker';

    protected $help = <<<'HELP'
By default, uses MusicBrainz's official database-only Docker mirror. Requires Docker Compose,
Git, an interactive terminal, and approximately 100 GB of free Docker storage.
You must answer the upstream data-use questions before the download begins.
The import may take hours and uses an isolated Docker project and a loopback-only
PostgreSQL port (55432). The app receives a read-only database account.

Run this command again to start and verify an existing completed database.
An interrupted setup is never overwritten automatically. Inspect the setup
directory and Docker volumes before deciding how to recover.

Set MUSICBRAINZ_SETUP_PATH to change where setup files are stored. Explicit
MUSICBRAINZ_* connection settings override the generated local connection.
Restart the app after setup. Data is a snapshot; replication is not enabled.

Alternatively, download and extract canonical_musicbrainz_data.csv from
https://metabrainz.org/datasets/derived-dumps#canonical, then run:
  php artisan musicbrainz:setup --canonical-csv=/path/to/canonical_musicbrainz_data.csv
Or download and import the latest dump automatically:
  php artisan musicbrainz:setup --download-canonical
This requires bash, tar and zstd. The extracted CSV is retained in
storage/app/private/musicbrainz-imports/ (or MUSICBRAINZ_SETUP_PATH with
"-imports" appended); existing CSVs are never overwritten.
This builds a separate SQLite database without Docker. Allow substantial disk
space and time for the extracted CSV, database and indexes. Only canonical
metadata is included; matching remains exact and may miss non-canonical releases.
The metadata is CC0; MetaBrainz asks commercial users to provide financial
support: https://metabrainz.org/supporters/account-type.
HELP;

    private const VERSION = 'v-2026-09-21.0';

    private const PORT = '55432';

    public function handle(CanonicalMusicBrainzImporter $canonicalImporter, CanonicalMusicBrainzDownloader $canonicalDownloader): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('This command is only available in the local environment.');

            return self::FAILURE;
        }

        $root = config('musicbrainz.setup_path');
        $repo = $root.'/docker';
        $connectionPath = $root.'/connection.json';
        $project = 'shc-mb-'.substr(hash('sha256', base_path()), 0, 12);
        $compose = [
            'docker', 'compose', '--project-name', $project,
            '--project-directory', $repo,
            '-f', $repo.'/docker-compose.alt.db-only-mirror.yml',
            '-f', $root.'/app-compose.yml',
        ];

        try {
            $csv = $this->option('canonical-csv');
            if ($this->input->hasParameterOption('--canonical-csv') && ($csv === null || $csv === '')) {
                throw new RuntimeException('Provide the path to the extracted canonical metadata CSV with --canonical-csv.');
            }
            if ($this->option('download-canonical') && $csv !== null) {
                throw new RuntimeException('Choose either --download-canonical or --canonical-csv, not both.');
            }
            if ($this->option('download-canonical')) {
                if (file_exists($root)) {
                    throw new RuntimeException("Setup directory already exists: {$root}. No existing files were modified.");
                }
                $this->warn('The canonical dump is several gigabytes; its CSV, SQLite database and indexes need substantial additional free space.');
                if (! $this->option('yes') && ! $this->confirm('Continue with the canonical download?', false)) {
                    $this->info('Setup cancelled.');

                    return self::SUCCESS;
                }
                if ($this->confirmDataUse() === null) {
                    return self::SUCCESS;
                }
                $this->info('Downloading and verifying the canonical dump; this may take a long time.');
                $csv = $canonicalDownloader->download($root.'-imports');
                $this->info("Canonical CSV saved to {$csv}.");
            }
            if ($csv !== null) {
                if (file_exists($root)) {
                    throw new RuntimeException("Setup directory already exists: {$root}. No existing files were modified.");
                }
                $this->info('Importing canonical metadata; this may take a long time.');
                $count = $canonicalImporter->import($csv, $root, fn (int $rows) => $this->info(number_format($rows).' rows read (indexing follows).'));
                if (app()->configurationIsCached()) {
                    $this->callSilent('config:clear');
                }
                $this->info("Imported {$count} canonical MusicBrainz rows. Restart the application to use the local database.");
                $this->warn('Explicit MUSICBRAINZ_* connection settings override the new local connection.');

                return self::SUCCESS;
            }

            if (is_file($connectionPath)) {
                $connection = json_decode(File::get($connectionPath), true, 512, JSON_THROW_ON_ERROR);
                if (($connection['format'] ?? null) === 'canonical') {
                    $canonicalImporter->verify($connection['database']);
                    $this->info('The existing local canonical MusicBrainz database is accessible.');

                    return self::SUCCESS;
                }
            }
            $this->runProcess(['docker', 'info', '--format', '{{.ServerVersion}}']);
            $this->runProcess(['docker', 'compose', 'version']);

            if (is_file($connectionPath)) {
                $connection = json_decode(File::get($connectionPath), true, 512, JSON_THROW_ON_ERROR);
                $this->runProcess([...$compose, 'up', '-d', 'db']);
                $this->verifyConnection($compose, $connection['password']);
                $this->info('The existing local MusicBrainz database is running and accessible.');

                return self::SUCCESS;
            }

            if (file_exists($root)) {
                throw new RuntimeException("Setup directory already exists without a completed configuration: {$root}. No existing files or volumes were modified.");
            }
            $volumes = $this->runProcess(['docker', 'volume', 'ls', '--format', '{{.Name}}'])->output();
            $volumeNames = explode("\n", str_replace("\r", '', trim($volumes)));
            if (in_array($project.'_pgdata', $volumeNames, true) || in_array($project.'_dbdump', $volumeNames, true)) {
                throw new RuntimeException('An existing MusicBrainz volume belongs to this project. Refusing to initialize over it.');
            }
            $this->warn('The full MusicBrainz mirror requires approximately 100 GB of free Docker storage and may take hours.');
            $this->warn('MusicBrainz requires you to answer its data-use questions. Run this command in an interactive terminal.');
            if (! $this->option('yes') && ! $this->confirm('Continue with the full download?', false)) {
                $this->info('Setup cancelled.');

                return self::SUCCESS;
            }

            $usage = $this->confirmDataUse();
            if ($usage === null) {
                return self::SUCCESS;
            }

            File::ensureDirectoryExists($root, 0700, true);
            $this->runProcess(['git', 'clone', '--depth', '1', '--branch', self::VERSION, 'https://github.com/metabrainz/musicbrainz-docker.git', $repo]);

            $adminPassword = bin2hex(random_bytes(24));
            $appPassword = bin2hex(random_bytes(24));
            $this->writePrivate($repo.'/default/postgres.env', "POSTGRES_USER=musicbrainz\nPOSTGRES_PASSWORD={$adminPassword}\n");
            $this->writePrivate($root.'/app-compose.yml', "services:\n  db:\n    ports:\n      - \"127.0.0.1:".self::PORT.":5432\"\n");

            $this->runProcess([...$compose, 'build', 'db', 'musicbrainz']);
            $this->runProcess([...$compose, 'up', '-d', 'db']);
            $this->runProcess([...$compose, 'run', '-T', '--rm', 'musicbrainz', 'createdb.sh', '-fetch'], input: $usage."\nx");

            $sql = "CREATE ROLE streaming_history LOGIN PASSWORD '{$appPassword}';\n".
                "GRANT CONNECT ON DATABASE musicbrainz_db TO streaming_history;\n".
                "GRANT USAGE ON SCHEMA musicbrainz TO streaming_history;\n".
                "GRANT SELECT ON musicbrainz.recording, musicbrainz.artist_credit, musicbrainz.artist_credit_name, musicbrainz.artist, musicbrainz.track, musicbrainz.medium, musicbrainz.release TO streaming_history;\n";
            $this->runProcess([...$compose, 'exec', '-T', 'db', 'psql', '-U', 'musicbrainz', '-d', 'musicbrainz_db', '-v', 'ON_ERROR_STOP=1'], input: $sql);
            $this->verifyConnection($compose, $appPassword);

            $connection = [
                'host' => '127.0.0.1',
                'port' => self::PORT,
                'database' => 'musicbrainz_db',
                'username' => 'streaming_history',
                'password' => $appPassword,
            ];
            $this->writePrivate($connectionPath, json_encode($connection, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
            if (app()->configurationIsCached()) {
                $this->callSilent('config:clear');
            }
            $this->info('MusicBrainz is ready. Restart the application to use the local database.');
            $this->warn('Explicit MUSICBRAINZ_* settings in your environment override the new local connection.');

            return self::SUCCESS;
        } catch (RuntimeException|JsonException|ConnectionException|RequestException $exception) {
            report($exception);
            $this->error($exception->getMessage());
            $this->error('Setup was not completed. No existing database was removed; inspect the setup directory and its Docker volumes before retrying.');

            return self::FAILURE;
        }
    }

    private function confirmDataUse(): ?string
    {
        $usage = strtolower(trim((string) $this->ask('Are you planning to use the MetaBrainz data for commercial or business purposes? Enter y or n')));
        if (! in_array($usage, ['y', 'n'], true)) {
            throw new RuntimeException('An explicit y or n answer is required to download MusicBrainz data.');
        }
        if ($usage === 'y') {
            $this->warn('The MetaBrainz Foundation requires commercial users to provide financial support. Sign up at https://metabrainz.org/supporters/account-type.');
        } else {
            $this->info('MetaBrainz asks non-commercial users to sign up at https://metabrainz.org/supporters/account-type and encourages donations at https://metabrainz.org/donate.');
        }
        if (! $this->confirm('Continue after reviewing the MetaBrainz data-use information?', false)) {
            $this->info('Setup cancelled.');

            return null;
        }

        return $usage;
    }

    /** @param list<string> $compose */
    private function verifyConnection(array $compose, string $password): void
    {
        $result = $this->runProcess(
            [...$compose, 'exec', '-T', '-e', 'PGPASSWORD', 'db', 'psql', '-h', '127.0.0.1', '-U', 'streaming_history', '-d', 'musicbrainz_db', '-tA', '-v', 'ON_ERROR_STOP=1'],
            input: "SELECT EXISTS (SELECT 1 FROM musicbrainz.recording LIMIT 1);\n",
            environment: ['PGPASSWORD' => $password],
        );
        if (trim($result->output()) !== 't') {
            throw new RuntimeException('MusicBrainz did not return any recordings; setup was not marked complete.');
        }
    }

    /**
     * @param  list<string>  $command
     * @param  resource|string|null  $input
     * @param  array<string, string>  $environment
     */
    private function runProcess(array $command, mixed $input = null, array $environment = []): ProcessResult
    {
        $process = Process::forever()->env([
            'COMPOSE_FILE' => false,
            'COMPOSE_PROJECT_NAME' => false,
            'MUSICBRAINZ_BASE_FTP_URL' => false,
            'MUSICBRAINZ_BASE_DOWNLOAD_URL' => 'https://data.metabrainz.org/pub/musicbrainz',
            'POSTGRES_VERSION' => false,
            ...$environment,
        ]);
        if ($input !== null) {
            $process->input($input);
        }
        $result = $process->run($command, fn (string $type, string $output) => $this->output->write($output));
        if ($result->failed()) {
            throw new RuntimeException('Command failed: '.implode(' ', array_slice($command, 0, 3))."\n".trim($result->errorOutput()));
        }

        return $result;
    }

    private function writePrivate(string $path, string $content): void
    {
        if (File::put($path, $content) === false || ! chmod($path, 0600)) {
            throw new RuntimeException("Could not write private setup file: {$path}");
        }
    }
}
