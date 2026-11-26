<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportListensRequest;
use App\Jobs\ProcessStreamingHistoryImport;
use App\Models\ImportRun;
use App\Services\ImportControl;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class ImportController extends Controller
{
    public function index(Request $request, ImportControl $control): Response
    {
        $runs = ImportRun::query()->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(25)
            ->through(fn (ImportRun $run) => [
                'id' => $run->id,
                'status' => in_array($run->status, ['queued', 'running'], true) && $control->isAborting($run->id) ? 'aborting' : $run->status,
                'created_at' => $run->created_at,
                'updated_at' => $run->updated_at,
                'result' => $run->result,
                'error' => $run->error,
                'progress' => $run->status === 'running' ? Cache::store('file')->get('import-progress:'.$run->id) : null,
                'has_files' => $run->files !== [],
                'can_abort' => $run->status === 'running' && $control->isAbortable($run->id),
            ]);

        return Inertia::render('imports/index', [
            'imports' => $runs,
            'musicbrainzEnabled' => (bool) config('musicbrainz.enabled'),
            'canImport' => ! ($request->user() instanceof MustVerifyEmail) || $request->user()->hasVerifiedEmail(),
            'started' => $request->query('started') === '1',
        ]);
    }

    public function store(ImportListensRequest $request): RedirectResponse
    {
        $files = $request->hasFile('files') ? $request->file('files') : [$request->file('file')];
        if (! is_array($files)) {
            throw new \LogicException('Validated import files must be an array.');
        }
        $id = (string) Str::uuid7();
        $stored = [];
        try {
            foreach ($files as $index => $file) {
                if ($file === null) {
                    throw new \LogicException('A validated import file is missing.');
                }
                $path = $file->storeAs('imports/'.$id, (string) $index, 'local');
                if ($path === false) {
                    throw ValidationException::withMessages(['files' => 'An uploaded file could not be stored for processing.']);
                }
                $stored[] = ['name' => $file->getClientOriginalName(), 'path' => $path];
            }
            DB::transaction(function () use ($request, $id, $stored) {
                DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
                ImportRun::query()->create(['id' => $id, 'user_id' => $request->user()->id, 'status' => 'queued', 'files' => $stored]);
                ProcessStreamingHistoryImport::dispatch($id);
            });
        } catch (Throwable $exception) {
            if (! Storage::disk('local')->deleteDirectory('imports/'.$id)) {
                report(new RuntimeException('Could not remove temporary import files for import '.$id));
            }
            throw $exception;
        }

        request()->attributes->set('import_queued', true);

        return to_route('imports.index', ['started' => 1]);
    }

    public function abort(Request $request, string $import, ImportControl $control): RedirectResponse
    {
        $control->synchronized($import, function () use ($request, $import, $control): void {
            $run = ImportRun::query()->where('user_id', $request->user()->id)->findOrFail($import);
            abort_unless($run->status === 'running' && $control->isAbortable($import), 409, 'This import cannot be stopped by its current worker.');
            $control->requestAbort($import);
        });
        $request->attributes->set('import_queued', true);

        return to_route('imports.index');
    }

    public function retry(Request $request, string $import, ImportControl $control): RedirectResponse
    {
        $control->synchronized($import, function () use ($request, $import, $control): void {
            DB::transaction(function () use ($request, $import, $control): void {
                DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
                $run = ImportRun::query()->where('user_id', $request->user()->id)->findOrFail($import);
                abort_unless(in_array($run->status, ['completed', 'completed_with_errors', 'failed', 'aborted'], true), 409, 'This import is still active.');
                if ($run->files === [] || collect($run->files)->contains(fn ($file) => ! Storage::disk('local')->exists($file['path']))) {
                    throw ValidationException::withMessages(['import' => 'The original files are unavailable. Upload them again to start a new import.']);
                }
                $control->clearAbort($import);
                $run->update(['status' => 'queued', 'result' => null, 'error' => null]);
                ProcessStreamingHistoryImport::dispatch($import);
            });
        });
        $request->attributes->set('import_queued', true);

        return to_route('imports.index', ['started' => 1]);
    }

    public function deleteFiles(Request $request, string $import, ImportControl $control): RedirectResponse
    {
        $control->synchronized($import, function () use ($request, $import): void {
            $run = ImportRun::query()->where('user_id', $request->user()->id)->findOrFail($import);
            abort_unless(! in_array($run->status, ['queued', 'running'], true), 409, 'Wait for the import to stop before deleting its files.');
            if (! Storage::disk('local')->deleteDirectory('imports/'.$run->id)) {
                throw new RuntimeException('Could not remove the import files.');
            }
            $run->update(['files' => []]);
        });

        return to_route('imports.index');
    }

    public function abortAll(Request $request, ImportControl $control): RedirectResponse
    {
        $ids = ImportRun::query()->where('user_id', $request->user()->id)
            ->whereIn('status', ['queued', 'running'])->pluck('id');
        $requested = 0;
        $unavailable = 0;

        foreach ($ids as $id) {
            try {
                $outcome = $control->synchronized($id, function () use ($request, $control, $id): string {
                    $run = ImportRun::query()->where('user_id', $request->user()->id)->whereKey($id)->first();
                    if ($run === null || ! in_array($run->status, ['queued', 'running'], true)) {
                        return 'finished';
                    }
                    if ($run->status === 'running' && ! $control->canDeleteOnAbort($id)) {
                        return 'unavailable';
                    }
                    $control->requestAbort($id, delete: true);

                    return 'requested';
                });
                $requested += (int) ($outcome === 'requested');
                $unavailable += (int) ($outcome === 'unavailable');
            } catch (LockTimeoutException) {
                $unavailable++;
            }
        }

        $request->attributes->set('import_queued', true);

        return to_route('listens.create', ['cancel_requested' => $requested, 'cancel_unavailable' => $unavailable]);
    }

    public function destroyFinished(Request $request, ImportControl $control): RedirectResponse
    {
        $statuses = ['completed', 'completed_with_errors', 'failed', 'aborted'];
        $ids = ImportRun::query()->where('user_id', $request->user()->id)->whereIn('status', $statuses)->pluck('id');
        $deleted = 0;
        foreach ($ids as $id) {
            try {
                $deleted += (int) $control->synchronized($id, function () use ($request, $control, $id, $statuses): bool {
                    $run = ImportRun::query()->where('user_id', $request->user()->id)->whereKey($id)->first();
                    if ($run === null || ! in_array($run->status, $statuses, true)) {
                        return false;
                    }
                    if (! Storage::disk('local')->deleteDirectory('imports/'.$run->id)) {
                        throw ValidationException::withMessages(['imports' => 'Some import files could not be deleted. Earlier imports may already have been deleted; please try again.']);
                    }
                    $run->delete();
                    $control->clearAbort($id);
                    $control->clearAbortable($id);
                    Cache::store('file')->forget('import-progress:'.$id);

                    return true;
                });
            } catch (LockTimeoutException) {
                throw ValidationException::withMessages(['imports' => 'Some imports are being updated. Earlier imports may already have been deleted; please try again.']);
            }
        }

        return to_route('listens.create', ['deleted_imports' => $deleted]);
    }
}
