<?php

namespace App\Jobs;

use App\Exceptions\MusicBrainzTemporaryException;
use App\Models\Listen;
use App\Models\User;
use App\Services\BulkMusicBrainzMatcher;
use App\Services\MusicBrainzApi;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcessBulkMusicBrainzMatch implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 40;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public bool $failOnTimeout = false;

    public function __construct(public int $runId)
    {
        $this->onConnection('imports')->onQueue('imports');
    }

    public function handle(MusicBrainzApi $api, BulkMusicBrainzMatcher $matcher): void
    {
        $lock = Cache::lock('bulk-musicbrainz-run:'.$this->runId, 180);
        if (! $lock->get()) {
            $this->release(5);

            return;
        }
        try {
            $run = DB::table('bulk_musicbrainz_runs')->where('id', $this->runId)->first();
            if ($run === null || ! in_array($run->status, ['queued', 'running', 'waiting'], true)) {
                return;
            }
            if ($run->status === 'waiting' && $run->retry_at !== null && CarbonImmutable::parse($run->retry_at)->isFuture()) {
                $this->release(max(1, (int) ceil(now()->diffInSeconds(CarbonImmutable::parse($run->retry_at), false))));

                return;
            }
            $item = DB::table('bulk_musicbrainz_run_items')->where('run_id', $run->id)
                ->where('status', 'pending')->orderBy('id')->first();
            if ($item === null) {
                DB::table('bulk_musicbrainz_runs')->where('id', $run->id)
                    ->whereIn('status', ['queued', 'running', 'waiting'])
                    ->update(['status' => 'completed', 'retry_at' => null, 'updated_at' => now()]);

                return;
            }
            DB::table('bulk_musicbrainz_runs')->where('id', $run->id)->whereIn('status', ['queued', 'waiting'])
                ->update(['status' => 'running', 'retry_at' => null, 'updated_at' => now()]);
            $listen = Listen::query()->where('user_id', $run->user_id)->whereKey($item->listen_id)->whereNull('recording_mbid')->first();
            $match = null;
            $candidates = [];
            $incomplete = false;
            $key = null;
            if ($listen !== null) {
                $key = hash('sha256', json_encode([$listen->track_name, $listen->artist_name, $listen->release_name], JSON_THROW_ON_ERROR));
                $cached = DB::table('bulk_musicbrainz_run_lookups')->where('run_id', $run->id)->where('lookup_key', $key)->first();
                if ($cached !== null) {
                    $result = $cached->result === null ? null : json_decode($cached->result, true, 512, JSON_THROW_ON_ERROR);
                    if (isset($result['candidates'])) {
                        $match = $result['match'];
                        $candidates = $result['candidates'];
                        $incomplete = $result['incomplete'];
                    } else {
                        $match = $result;
                    }
                } else {
                    if (! DB::table('bulk_musicbrainz_runs')->where('id', $run->id)
                        ->whereIn('status', ['queued', 'running', 'waiting'])->exists()) {
                        return;
                    }
                    try {
                        $result = $api->findListenRecordings(
                            $listen->track_name,
                            $listen->artist_name,
                            $listen->release_name ?? '',
                            fn () => DB::table('bulk_musicbrainz_runs')->where('id', $run->id)
                                ->whereIn('status', ['queued', 'running', 'waiting'])->exists(),
                        );
                        $match = $result['match'];
                        $candidates = $result['candidates'];
                        $incomplete = $result['incomplete'];
                    } catch (MusicBrainzTemporaryException $exception) {
                        $this->retry($run->user_id, $item->id, $item->attempts + 1, $exception);

                        return;
                    }
                }
            }
            DB::transaction(function () use ($run, $item, $listen, $match, $candidates, $incomplete, $key, $matcher): void {
                DB::table('users')->where('id', $run->user_id)->lockForUpdate()->first();
                $currentRun = DB::table('bulk_musicbrainz_runs')->where('id', $run->id)->first();
                if ($currentRun === null || ! in_array($currentRun->status, ['queued', 'running', 'waiting'], true)
                    || ! DB::table('bulk_musicbrainz_run_items')->where('id', $item->id)->where('status', 'pending')->exists()) {
                    return;
                }
                if ($key !== null) {
                    DB::table('bulk_musicbrainz_run_lookups')->insertOrIgnore([
                        'run_id' => $run->id, 'lookup_key' => $key,
                        'result' => json_encode(compact('match', 'candidates', 'incomplete'), JSON_THROW_ON_ERROR),
                    ]);
                }
                $current = $listen === null ? null : Listen::query()->where('user_id', $run->user_id)->whereKey($listen->id)->whereNull('recording_mbid')->first();
                if ($current !== null && ($current->track_name !== $listen->track_name
                    || $current->artist_name !== $listen->artist_name || $current->release_name !== $listen->release_name)) {
                    $current = null;
                }
                $outcome = 'skipped';
                $propagated = 0;
                if ($current !== null && $match === null) {
                    $outcome = 'unmatched';
                    if ($candidates !== []) {
                        DB::table('pending_musicbrainz_matches')->updateOrInsert(
                            ['user_id' => $run->user_id, 'listen_id' => $current->id],
                            ['track_name' => $current->track_name, 'artist_name' => $current->artist_name,
                                'release_name' => $current->release_name, 'candidates' => json_encode($candidates, JSON_THROW_ON_ERROR),
                                'incomplete' => $incomplete, 'created_at' => now(), 'updated_at' => now()],
                        );
                    }
                } elseif ($current !== null) {
                    $updated = $matcher->apply(User::query()->whereKey($run->user_id)->firstOrFail(), $current, $match);
                    if ($updated !== null) {
                        $outcome = 'matched';
                        $propagated = $updated;
                    }
                }
                DB::table('bulk_musicbrainz_run_items')->where('id', $item->id)->update(['status' => $outcome]);
                DB::table('bulk_musicbrainz_runs')->where('id', $run->id)->incrementEach([
                    'processed' => 1, $outcome => 1, 'propagated' => $propagated,
                ], ['status' => 'running', 'error' => null, 'updated_at' => now()]);
                self::dispatch($run->id);
            });
        } catch (ValidationException $exception) {
            $this->fail($exception);
        } finally {
            $lock->release();
        }
    }

    private function retry(int $userId, int $itemId, int $attempts, MusicBrainzTemporaryException $exception): void
    {
        DB::transaction(function () use ($userId, $itemId, $attempts, $exception): void {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            $run = DB::table('bulk_musicbrainz_runs')->where('id', $this->runId)->first();
            if ($run === null || ! in_array($run->status, ['queued', 'running', 'waiting'], true)) {
                return;
            }
            DB::table('bulk_musicbrainz_run_items')->where('id', $itemId)->update(['attempts' => $attempts]);
            $delay = max($exception->retryAfter, min(300, 5 * (2 ** ($attempts - 1))) + random_int(0, 5));
            if ($attempts >= 5 || $delay > 3600) {
                DB::table('bulk_musicbrainz_runs')->where('id', $run->id)->update([
                    'status' => 'paused', 'error' => $exception->getMessage(), 'retry_at' => null, 'updated_at' => now(),
                ]);

                return;
            }
            $retryAt = now()->addSeconds($delay);
            DB::table('bulk_musicbrainz_runs')->where('id', $run->id)->update([
                'status' => 'waiting', 'error' => $exception->getMessage(), 'retry_at' => $retryAt, 'updated_at' => now(),
            ]);
            self::dispatch($run->id)->delay($retryAt);
        });
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('bulk_musicbrainz_runs')->where('id', $this->runId)->whereIn('status', ['queued', 'running', 'waiting'])
            ->update(['status' => 'failed', 'error' => 'The bulk match failed. Earlier matches were retained; see the application logs.', 'retry_at' => null, 'updated_at' => now()]);
    }
}
