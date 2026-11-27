<?php

namespace App\Jobs;

use App\Models\Listen;
use App\Models\User;
use App\Services\ArtistAliases;
use App\Services\CatalogRecordingGroups;
use App\Services\ManualListenMatcher;
use App\Services\ManualMusicEntityMatcher;
use App\Services\MatchedMusicMetadata;
use App\Services\MusicBrainzApi;
use App\Services\MusicBrainzMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ScanArtistRecordings implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $scanId)
    {
        $this->onConnection('imports')->onQueue('imports');
    }

    public function handle(ManualMusicEntityMatcher $manual, ManualListenMatcher $recordingMatches, ArtistAliases $aliases, MusicBrainzApi $api, MatchedMusicMetadata $metadata, CatalogRecordingGroups $groups, MusicBrainzMatcher $matcher): void
    {
        $scan = DB::table('artist_recording_scans')->where('id', $this->scanId)->first();
        if ($scan === null || $scan->status !== 'queued') {
            return;
        }
        if (DB::table('artist_recording_scans')->where('id', $scan->id)->where('status', 'queued')
            ->update(['status' => 'running', 'updated_at' => now()]) !== 1) {
            return;
        }
        $user = User::query()->whereKey($scan->user_id)->firstOrFail();
        $item = Listen::query()->where('user_id', $user->id)->whereKey($scan->listen_id)->firstOrFail();
        if ($manual->key('artist', $item) !== $scan->entity_key) {
            throw new RuntimeException('The imported artist credit changed after this scan was queued.');
        }
        $snapshots = [];
        if ($scan->rematch_existing) {
            $values = json_decode($scan->recording_snapshot, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($values) || ! array_is_list($values)) {
                throw new \UnexpectedValueException('Invalid artist recording scan snapshot.');
            }
            $snapshots = array_column($values, null, 'id');
        }
        $cache = [];
        $manual->related('artist', $item)->when(! $scan->rematch_existing, fn ($query) => $query->whereNull('recording_mbid'))
            ->chunkById(200, function ($listens) use ($scan, $snapshots, $user, $item, $manual, $recordingMatches, $aliases, $api, $metadata, $groups, $matcher, &$cache) {
                $recordingMbids = [];
                $running = true;
                foreach ($listens as $listen) {
                    if ($scan->rematch_existing && ! isset($snapshots[$listen->id])) {
                        continue;
                    }
                    if (! DB::table('artist_recording_scans')->where('id', $scan->id)->where('status', 'running')->exists()) {
                        $running = false;
                        break;
                    }
                    $key = json_encode([$listen->track_name, $listen->release_name], JSON_THROW_ON_ERROR);
                    if (! array_key_exists($key, $cache)) {
                        $album = $listen->release_name ?? '';
                        $ambiguous = false;
                        $match = $scan->rematch_existing
                            ? $matcher->match($listen->track_name, '', $album, $ambiguous, $scan->artist_mbid)
                            : $aliases->match($listen->track_name, $listen->artist_name, $album, $scan->artist_mbid, $ambiguous);
                        $cache[$key] = $match ?? (! $ambiguous && $scan->source === 'api'
                            ? $api->matchArtistRecording($listen->track_name, $scan->artist_mbid, $album) : null);
                    }
                    $match = $cache[$key];
                    DB::transaction(function () use ($scan, $snapshots, $user, $item, $listen, $match, $manual, $recordingMatches, $metadata, $groups, &$recordingMbids): void {
                        DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                        if (! DB::table('artist_recording_scans')->where('id', $scan->id)->where('status', 'running')->exists()) {
                            return;
                        }
                        $saved = DB::table('music_entity_matches')->where([
                            'user_id' => $user->id, 'type' => 'artist', 'entity_key' => $manual->key('artist', $item),
                        ])->value('mbid');
                        if ($saved !== $scan->artist_mbid) {
                            throw new RuntimeException('The saved artist association changed during the scan.');
                        }
                        $current = Listen::query()->where('user_id', $user->id)->whereKey($listen->id)
                            ->when(! $scan->rematch_existing, fn ($query) => $query->whereNull('recording_mbid'))->first();
                        if ($scan->rematch_existing) {
                            $snapshot = $snapshots[$listen->id];
                            $conflicts = 0;
                            $unresolved = 0;
                            $updated = 0;
                            if ($current === null || $current->track_name !== $snapshot['track_name']
                                || $current->release_name !== $snapshot['release_name'] || $current->artist_name !== $item->artist_name
                                || ($current->recording_mbid !== $snapshot['recording_mbid']
                                    && ($match === null || $current->recording_mbid !== $match['recording_mbid']))) {
                                $conflicts = 1;
                            } elseif ($match === null) {
                                $unresolved = 1;
                            } elseif ($current->recording_mbid !== $match['recording_mbid']) {
                                $replacement = array_replace($match, ['release_mbid' => null]);
                                $checkCandidate = static function (Listen $candidate) use ($snapshots, $match): void {
                                    $expected = $snapshots[$candidate->id] ?? null;
                                    if ($expected === null || $candidate->track_name !== $expected['track_name']
                                        || $candidate->artist_name !== $expected['artist_name'] || $candidate->release_name !== $expected['release_name']
                                        || ! in_array($candidate->recording_mbid, [$expected['recording_mbid'], $match['recording_mbid']], true)) {
                                        throw ValidationException::withMessages(['recording_mbid' => 'An affected recording changed after the artist scan was queued.']);
                                    }
                                };
                                try {
                                    $updated = $current->recording_mbid === null
                                        ? $recordingMatches->remember($user, $current, $replacement, $current->track_name, artistCredit: $item->artist_name, checkCandidate: $checkCandidate)
                                        : $recordingMatches->replace($user, $current, $replacement, $current->recording_mbid, $current->track_name, artistCredit: $item->artist_name, checkCandidate: $checkCandidate);
                                    $recordingMbids[$match['recording_mbid']] = true;
                                } catch (ValidationException $exception) {
                                    if (! isset($exception->errors()['recording_mbid'])) {
                                        throw $exception;
                                    }
                                    $conflicts = 1;
                                }
                            }
                            DB::table('artist_recording_scans')->where('id', $scan->id)->incrementEach([
                                'processed' => 1, 'matched' => $updated, 'unresolved' => $unresolved, 'conflicts' => $conflicts,
                            ], ['updated_at' => now()]);

                            return;
                        }
                        $updated = 0;
                        if ($current !== null && $match !== null) {
                            $savedRecording = $recordingMatches->remembered($user, $current->track_name, $current->artist_name);
                            $groupId = $groups->groupId($current);
                            $savedGroup = $groupId === null ? null : DB::table('catalog_recording_groups')
                                ->where('user_id', $user->id)->where('id', $groupId)->value('recording_match');
                            $groupMatch = $savedGroup === null ? null : json_decode($savedGroup, true, 512, JSON_THROW_ON_ERROR);
                            if (($savedRecording !== null && $savedRecording['recording_mbid'] !== $match['recording_mbid'])
                                || ($groupMatch !== null && $groupMatch['recording_mbid'] !== $match['recording_mbid'])) {
                                DB::table('artist_recording_scans')->where('id', $scan->id)->increment('processed', 1, ['updated_at' => now()]);

                                return;
                            }
                            $metadata->rememberArtistNames($match['artist_names'] ?? []);
                            $trackMetadata = $current->track_metadata;
                            $trackMetadata['additional_info']['recording_mbid'] = $match['recording_mbid'];
                            $trackMetadata['additional_info']['artist_mbids'] = $match['artist_mbids'];
                            if ($match['release_mbid'] !== null) {
                                $trackMetadata['additional_info']['release_mbid'] = $match['release_mbid'];
                            }
                            $current->update(['recording_mbid' => $match['recording_mbid'], 'track_metadata' => $trackMetadata]);
                            $metadata->sync($current);
                            $updated = 1 + $groups->recordMatch($user, $current, [
                                'recording_mbid' => $match['recording_mbid'], 'artist_name' => $match['artist_name'],
                                'artist_mbids' => $match['artist_mbids'], 'release_mbid' => null,
                            ]);
                            $recordingMbids[$match['recording_mbid']] = true;
                        }
                        DB::table('artist_recording_scans')->where('id', $scan->id)->incrementEach([
                            'processed' => 1, 'matched' => $updated,
                        ], ['updated_at' => now()]);
                    });
                }
                $linked = $scan->rematch_existing ? ['matched' => 0] : $groups->linkMatching($user, $recordingMatches, array_keys($recordingMbids));
                if ($linked['matched'] > 0) {
                    DB::table('artist_recording_scans')->where('id', $scan->id)->increment('matched', $linked['matched'], ['updated_at' => now()]);
                }

                return $running;
            });
        DB::table('artist_recording_scans')->where('id', $scan->id)->where('status', 'running')
            ->update(['status' => 'completed', 'updated_at' => now()]);
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('artist_recording_scans')->where('id', $this->scanId)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed', 'error' => 'The scan failed or the artist association changed. Earlier matches were retained; see the application logs.',
            'updated_at' => now(),
        ]);
    }
}
