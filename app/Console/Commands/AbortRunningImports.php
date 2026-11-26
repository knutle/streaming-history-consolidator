<?php

namespace App\Console\Commands;

use App\Jobs\ProcessStreamingHistoryImport;
use App\Models\ImportRun;
use App\Services\ImportControl;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use UnexpectedValueException;

#[Signature('imports:abort-running {--workers-stopped : Confirm all import workers and automatic restarts are stopped on every host}')]
#[Description('Force running or stranded imports to abort after all import workers have stopped')]
class AbortRunningImports extends Command
{
    public function handle(ImportControl $control): int
    {
        if (! $this->option('workers-stopped')) {
            $this->error('Stop all imports workers and disable automatic restarts on every host, then pass --workers-stopped.');

            return self::FAILURE;
        }

        $connection = config('queue.connections.imports.connection');
        $table = config('queue.connections.imports.table');
        $queue = config('queue.connections.imports.queue');
        if (config('queue.connections.imports.driver') !== 'database'
            || ($connection !== null && $connection !== DB::getDefaultConnection())
            || ! is_string($table) || $table === '' || ! is_string($queue) || $queue === '') {
            $this->error('Force abort requires the imports database queue on the application database.');

            return self::FAILURE;
        }

        $count = 0;
        $ids = ImportRun::query()->where('status', 'running')->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            $aborted = $control->synchronized($id, function () use ($id, $table, $queue, $control): bool {
                $wasPendingDeletion = $control->shouldDelete($id);
                $aborted = DB::transaction(function () use ($id, $table, $queue): bool {
                    $run = ImportRun::query()->whereKey($id)->where('status', 'running')->first();
                    if ($run === null) {
                        return false;
                    }

                    $jobIds = [];
                    foreach (DB::table($table)->where('queue', $queue)->get(['id', 'payload']) as $row) {
                        $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
                        if (! is_array($payload)) {
                            throw new UnexpectedValueException('An imports queue payload is invalid.');
                        }
                        $displayName = $payload['displayName'] ?? null;
                        $commandName = $payload['data']['commandName'] ?? null;
                        if ($displayName !== ProcessStreamingHistoryImport::class && $commandName !== ProcessStreamingHistoryImport::class) {
                            continue;
                        }
                        if ($displayName !== ProcessStreamingHistoryImport::class || $commandName !== ProcessStreamingHistoryImport::class) {
                            throw new UnexpectedValueException('An import job payload has inconsistent job identifiers.');
                        }
                        $command = $payload['data']['command'] ?? null;
                        if (! is_string($command)) {
                            throw new UnexpectedValueException('An import job payload cannot be identified.');
                        }
                        $job = (array) unserialize($command, ['allowed_classes' => false]);
                        if (($job['__PHP_Incomplete_Class_Name'] ?? null) !== ProcessStreamingHistoryImport::class
                            || ! is_string($job['importId'] ?? null)) {
                            throw new UnexpectedValueException('An import job payload cannot be identified.');
                        }
                        if ($job['importId'] === $id) {
                            $jobIds[] = $row->id;
                        }
                    }
                    DB::table($table)->where('queue', $queue)->whereIn('id', $jobIds)->delete();
                    $run->update([
                        'status' => 'aborted',
                        'error' => 'The import was stopped. Completed files remain imported; an in-progress file is rolled back.',
                    ]);

                    return true;
                });
                if (! $aborted) {
                    return false;
                }

                $cache = Cache::store('file');
                foreach (['import-abort:', 'import-delete:', 'import-abortable:', 'import-progress:'] as $prefix) {
                    $key = $prefix.$id;
                    if ($cache->has($key) && ! $cache->forget($key)) {
                        throw new RuntimeException("Could not clear import state for {$id}.");
                    }
                }
                if ($wasPendingDeletion) {
                    $this->warn("Import {$id} had a pending deletion request; its files and record were retained.");
                }

                return true;
            });
            if ($aborted) {
                $count++;
                $this->line("Aborted import {$id}.");
            }
        }

        $this->info("Aborted {$count} running imports. Queued imports, retained files, and completed listens were not deleted.");

        return self::SUCCESS;
    }
}
