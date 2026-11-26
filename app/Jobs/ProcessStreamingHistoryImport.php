<?php

namespace App\Jobs;

use App\Exceptions\ImportAborted;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\ImportControl;
use App\Services\StreamingHistoryUpload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProcessStreamingHistoryImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public string $importId)
    {
        $this->onConnection('imports')->onQueue('imports');
    }

    public function handle(StreamingHistoryUpload $upload, ImportControl $control): void
    {
        try {
            $run = $control->synchronized($this->importId, function () use ($control) {
                $run = ImportRun::query()->findOrFail($this->importId);
                if ($run->status !== 'queued') {
                    return null;
                }
                $control->checkAbort($this->importId);
                $control->markAbortable($this->importId);
                $run->update(['status' => 'running']);

                return $run;
            });
            if ($run === null) {
                return;
            }
            Log::info('Import started.', ['import_id' => $run->id, 'user_id' => $run->user_id, 'files' => count($run->files)]);

            $user = User::query()->whereKey($run->user_id)->firstOrFail();
            $files = [];
            foreach ($run->files as $file) {
                $control->checkAbort($this->importId);
                $path = Storage::disk('local')->path($file['path']);
                if (! is_file($path)) {
                    throw new RuntimeException('An uploaded import file is missing.');
                }
                $files[] = new UploadedFile($path, $file['name'], null, null, true);
            }

            $result = $upload->import($user, $files, function (array $progress) use ($control): void {
                $control->checkAbort($this->importId);
                Log::info('Import progress.', ['import_id' => $this->importId, ...$progress]);
                if (! Cache::store('file')->put('import-progress:'.$this->importId, $progress, now()->addDay())) {
                    throw new RuntimeException('Could not write import progress.');
                }
            }, fn () => $control->checkAbort($this->importId));

            $control->synchronized($this->importId, function () use ($control, $run, $result): void {
                $control->checkAbort($this->importId);
                $run->update(['status' => $result['errors'] === [] ? 'completed' : 'completed_with_errors', 'result' => $result]);
                Log::info('Import completed.', [
                    'import_id' => $run->id, 'status' => $run->status, 'imported' => $result['imported'],
                    'duplicates' => $result['duplicates'], 'matched' => $result['matched'], 'errors' => count($result['errors']),
                ]);
                $control->clearAbortable($this->importId);
                Cache::store('file')->forget('import-progress:'.$this->importId);
            });
        } catch (ImportAborted) {
            Log::info('Import aborted.', ['import_id' => $this->importId]);
            $control->synchronized($this->importId, function () use ($control): void {
                if ($control->shouldDelete($this->importId)) {
                    if (! Storage::disk('local')->deleteDirectory('imports/'.$this->importId)) {
                        throw new RuntimeException('Could not remove the cancelled import files.');
                    }
                    ImportRun::query()->whereKey($this->importId)->delete();
                } else {
                    ImportRun::query()->whereKey($this->importId)->update([
                        'status' => 'aborted',
                        'error' => 'The import was stopped. Completed files remain imported; an in-progress file is rolled back.',
                    ]);
                }
                $control->clearAbort($this->importId);
                $control->clearAbortable($this->importId);
                Cache::store('file')->forget('import-progress:'.$this->importId);
            });
        }
    }

    public function failed(?Throwable $exception): void
    {
        $control = app(ImportControl::class);
        $control->synchronized($this->importId, function () use ($control): void {
            $run = ImportRun::query()->find($this->importId);
            if ($run !== null && in_array($run->status, ['queued', 'running'], true)) {
                if ($control->shouldDelete($this->importId)) {
                    if (Storage::disk('local')->deleteDirectory('imports/'.$this->importId)) {
                        $run->delete();
                    } else {
                        $run->update([
                            'status' => 'failed',
                            'error' => 'The import stopped, but its uploaded files could not be deleted. Try deleting the finished import again.',
                        ]);
                        report(new RuntimeException('Could not remove import files for import '.$this->importId));
                    }
                } else {
                    $run->update([
                        'status' => 'failed',
                        'error' => 'The import failed. Some earlier files may have been imported; see the application logs.',
                    ]);
                }
                $control->clearAbort($this->importId);
                $control->clearAbortable($this->importId);
                Cache::store('file')->forget('import-progress:'.$this->importId);
            } elseif ($run === null && ! Storage::disk('local')->deleteDirectory('imports/'.$this->importId)) {
                report(new RuntimeException('Could not remove import files for import '.$this->importId));
            }
        });
    }
}
