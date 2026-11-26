<?php

namespace App\Services;

use App\Exceptions\ImportAborted;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ImportControl
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function synchronized(string $id, Closure $callback): mixed
    {
        $store = Cache::store('file')->getStore();
        if (! $store instanceof LockProvider) {
            throw new RuntimeException('The import cache does not support locks.');
        }

        return $store->lock('import-control:'.$id, 7500)->block(5, $callback);
    }

    public function requestAbort(string $id, bool $delete = false): void
    {
        if ($delete && ! Cache::store('file')->forever('import-delete:'.$id, true)) {
            throw new RuntimeException('Could not save the import deletion request.');
        }
        if (! Cache::store('file')->forever('import-abort:'.$id, true)) {
            if ($delete && ! $this->isAborting($id)) {
                Cache::store('file')->forget('import-delete:'.$id);
            }
            throw new RuntimeException('Could not save the import cancellation request.');
        }
    }

    public function markAbortable(string $id): void
    {
        if (! Cache::store('file')->put('import-abortable:'.$id, 'delete', now()->addDay())) {
            throw new RuntimeException('Could not enable import cancellation.');
        }
    }

    public function isAbortable(string $id): bool
    {
        return in_array(Cache::store('file')->get('import-abortable:'.$id), [true, 'delete'], true);
    }

    public function canDeleteOnAbort(string $id): bool
    {
        return Cache::store('file')->get('import-abortable:'.$id) === 'delete';
    }

    public function clearAbortable(string $id): void
    {
        Cache::store('file')->forget('import-abortable:'.$id);
    }

    public function isAborting(string $id): bool
    {
        return Cache::store('file')->get('import-abort:'.$id) === true;
    }

    public function shouldDelete(string $id): bool
    {
        return Cache::store('file')->get('import-delete:'.$id) === true;
    }

    public function checkAbort(string $id): void
    {
        if ($this->isAborting($id)) {
            throw new ImportAborted;
        }
    }

    public function clearAbort(string $id): void
    {
        Cache::store('file')->forget('import-abort:'.$id);
        Cache::store('file')->forget('import-delete:'.$id);
    }
}
