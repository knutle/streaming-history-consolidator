<?php

namespace App\Jobs;

use App\Models\Listen;
use App\Models\User;
use App\Services\BulkMusicBrainzMatcher;
use App\Services\ManualListenMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcessRecordingAssociation implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $runId)
    {
        $this->onConnection('imports')->onQueue('imports');
    }

    /**
     * The caller must hold the user's row lock.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function enqueue(User $user, Listen $listen, array $payload): void
    {
        if (DB::table('recording_association_runs')->where('user_id', $user->id)->where('listen_id', $listen->id)
            ->whereIn('status', ['queued', 'running'])->exists()) {
            throw ValidationException::withMessages(['recording_mbid' => 'An association for this listen is already queued or running.']);
        }
        $run = DB::table('recording_association_runs')->insertGetId([
            'user_id' => $user->id, 'listen_id' => $listen->id, 'status' => 'queued',
            'payload' => json_encode($payload + [
                'track_name' => $listen->track_name, 'artist_name' => $listen->artist_name,
                'release_name' => $listen->release_name,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        self::dispatch($run);
    }

    public function handle(ManualListenMatcher $manual, BulkMusicBrainzMatcher $bulk): void
    {
        $run = DB::table('recording_association_runs')->where('id', $this->runId)->first();
        if ($run === null || $run->status !== 'queued') {
            return;
        }
        if (DB::table('recording_association_runs')->where('id', $run->id)->where('status', 'queued')
            ->update(['status' => 'running', 'updated_at' => now()]) !== 1) {
            return;
        }
        DB::transaction(function () use ($run, $manual, $bulk): void {
            DB::table('users')->where('id', $run->user_id)->lockForUpdate()->first();
            $user = User::query()->whereKey($run->user_id)->firstOrFail();
            $listen = Listen::query()->where('user_id', $user->id)->whereKey($run->listen_id)->first();
            $payload = json_decode($run->payload, true, 512, JSON_THROW_ON_ERROR);
            $expected = $payload['type'] === 'replacement' ? $payload['expected_recording_mbid'] : null;
            if ($payload['type'] === 'replacement' && ! is_string($expected)) {
                throw new \UnexpectedValueException('Missing recording replacement snapshot.');
            }
            if ($listen === null || $listen->recording_mbid !== $expected || $listen->track_name !== $payload['track_name']
                || $listen->artist_name !== $payload['artist_name'] || $listen->release_name !== $payload['release_name']) {
                throw ValidationException::withMessages(['recording_mbid' => 'This listen has changed or has already been identified.']);
            }
            if ($payload['type'] === 'approval') {
                $review = DB::table('pending_musicbrainz_matches')->where('user_id', $user->id)
                    ->where('id', $payload['pending_id'])->where('listen_id', $listen->id)->first();
                if ($review === null || $review->track_name !== $payload['track_name']
                    || $review->artist_name !== $payload['artist_name'] || $review->release_name !== $payload['release_name']) {
                    throw ValidationException::withMessages(['recording_mbid' => 'This review has changed or was dismissed. Search again.']);
                }
                $candidates = json_decode($review->candidates, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($candidates) || ! array_is_list($candidates)) {
                    throw new \UnexpectedValueException('Invalid stored MusicBrainz candidates.');
                }
                $candidate = collect($candidates)->firstWhere('recording_mbid', $payload['match']['recording_mbid']);
                if ($candidate === null || $candidate['artist_name'] !== $payload['match']['artist_name']
                    || $candidate['artist_mbids'] !== $payload['match']['artist_mbids']
                    || $candidate['artist_names'] !== $payload['match']['artist_names']) {
                    throw ValidationException::withMessages(['recording_mbid' => 'This review has changed. Search again.']);
                }
                $propagated = $bulk->apply($user, $listen, $payload['match']);
                if ($propagated === null) {
                    throw ValidationException::withMessages(['recording_mbid' => 'This choice conflicts with a saved or linked match. Resolve the conflict first.']);
                }
                $matched = 1 + $propagated;
                DB::table('pending_musicbrainz_matches')->where('id', $review->id)->delete();
            } elseif ($payload['type'] === 'manual') {
                $matched = $manual->remember($user, $listen, $payload['match'], $payload['recording_name']);
            } elseif ($payload['type'] === 'replacement') {
                $matched = $manual->replace($user, $listen, $payload['match'], $expected, $payload['recording_name']);
            } else {
                throw new \UnexpectedValueException('Invalid recording association type.');
            }
            DB::table('recording_association_runs')->where('id', $run->id)->update([
                'status' => 'completed', 'matched' => $matched, 'updated_at' => now(),
            ]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        $error = $exception instanceof ValidationException
            ? ($exception->errors()['recording_mbid'][0] ?? 'The recording association could not be applied.')
            : 'The recording association failed. See the application logs.';
        DB::table('recording_association_runs')->where('id', $this->runId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'error' => $error, 'updated_at' => now()]);
    }
}
