<?php

namespace App\Services;

use App\Models\Listen;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualListenMatcher
{
    public function __construct(private MatchedMusicMetadata $matchedMetadata, private CatalogRecordingGroups $catalogGroups) {}

    public function key(string $track, string $artist): string
    {
        return hash('sha256', json_encode([
            mb_strtolower(trim($track)),
            mb_strtolower(trim($artist !== '' ? $artist : 'Unknown artist')),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return array{recording_mbid: string, artist_name: string, artist_mbids: list<string>, release_mbid: null}|null */
    public function remembered(User $user, string $track, string $artist): ?array
    {
        $value = DB::table('listen_matches')->where('user_id', $user->id)
            ->where('song_key', $this->key($track, $artist))->value('match');

        return $value === null ? null : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array{recording_mbid: string, artist_name: string, artist_mbids: list<string>, artist_names?: array<string, string>, release_mbid: null} $match */
    public function replace(User $user, Listen $listen, array $match, string $previousRecordingMbid, ?string $recordingName = null): int
    {
        return DB::transaction(function () use ($user, $listen, $match, $previousRecordingMbid, $recordingName): int {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $current = Listen::query()->where('user_id', $user->id)->find($listen->id);
            if ($current === null || $current->recording_mbid !== $previousRecordingMbid
                || $current->track_name !== $listen->track_name || $current->artist_name !== $listen->artist_name
                || $current->release_name !== $listen->release_name) {
                throw ValidationException::withMessages(['recording_mbid' => 'This listen has changed. Reload it before re-matching.']);
            }
            if ($match['recording_mbid'] === $previousRecordingMbid) {
                throw ValidationException::withMessages(['recording_mbid' => 'Choose a different recording to replace the current match.']);
            }

            $songKey = $this->key($current->track_name, $current->artist_name);
            $catalogKeys = [];
            Listen::query()->where('user_id', $user->id)->chunkById(500, function ($listens) use ($songKey, &$catalogKeys): void {
                foreach ($listens as $candidate) {
                    if ($this->key($candidate->track_name, $candidate->artist_name) === $songKey) {
                        $catalogKey = $this->catalogGroups->nativeKey($candidate);
                        if ($catalogKey !== null) {
                            $catalogKeys[$catalogKey] = true;
                        }
                    }
                }
            });
            $catalogKeys = array_fill_keys($this->catalogGroups->replaceRemembered($user, array_keys($catalogKeys), $match, $previousRecordingMbid), true);
            $saved = DB::table('listen_matches')->where('user_id', $user->id)->pluck('match', 'song_key');
            $remembered = [];
            $updated = 0;
            $this->matchedMetadata->rememberArtistNames($match['artist_names'] ?? []);
            Listen::query()->where('user_id', $user->id)->chunkById(500, function ($listens) use ($user, $songKey, $catalogKeys, $saved, $match, $previousRecordingMbid, $recordingName, &$remembered, &$updated): void {
                foreach ($listens as $candidate) {
                    $key = $this->key($candidate->track_name, $candidate->artist_name);
                    if ($key !== $songKey && ! isset($catalogKeys[$this->catalogGroups->nativeKey($candidate)])) {
                        continue;
                    }
                    if (! in_array($candidate->recording_mbid, [null, $previousRecordingMbid, $match['recording_mbid']], true)) {
                        throw ValidationException::withMessages(['recording_mbid' => 'An affected listen has a conflicting recording. Resolve or unlink it before re-matching. No matches were changed.']);
                    }
                    if (! isset($remembered[$key])) {
                        if (isset($saved[$key])) {
                            $choice = json_decode($saved[$key], true, 512, JSON_THROW_ON_ERROR);
                            if (! in_array($choice['recording_mbid'], [$previousRecordingMbid, $match['recording_mbid']], true)) {
                                throw ValidationException::withMessages(['recording_mbid' => 'An affected track has a conflicting saved recording. Resolve it before re-matching. No matches were changed.']);
                            }
                        }
                        $this->saveChoice($user, $candidate, $match, $recordingName);
                        $remembered[$key] = true;
                    }
                    if ($candidate->recording_mbid !== $match['recording_mbid']) {
                        $this->matchedMetadata->replaceRecording($candidate, $match, $recordingName);
                        DB::table('pending_musicbrainz_matches')->where('user_id', $user->id)->where('listen_id', $candidate->id)->delete();
                        $updated++;
                    }
                }
            });
            $updated += $this->catalogGroups->linkMatching($user, $this, [$match['recording_mbid']])['matched'];

            return $updated;
        });
    }

    /** @param array{recording_mbid: string, artist_name: string, artist_mbids: list<string>, artist_names?: array<string, string>, release_mbid: null} $match */
    private function saveChoice(User $user, Listen $listen, array $match, ?string $recordingName): void
    {
        DB::table('listen_matches')->updateOrInsert(
            ['user_id' => $user->id, 'song_key' => $this->key($listen->track_name, $listen->artist_name)],
            ['match' => json_encode($match + [
                'source_track' => $listen->track_name,
                'source_artist' => $listen->artist_name,
                'recording_name' => $recordingName,
            ], JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /** @param array{recording_mbid: string, artist_name: string, artist_mbids: list<string>, artist_names?: array<string, string>, release_mbid: null} $match */
    public function remember(User $user, Listen $listen, array $match, ?string $recordingName = null): int
    {
        return DB::transaction(function () use ($user, $listen, $match, $recordingName) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            if (! Listen::query()->where('user_id', $user->id)->whereKey($listen->id)->whereNull('recording_mbid')->exists()) {
                throw ValidationException::withMessages(['recording_mbid' => 'This listen has already been identified or deleted.']);
            }
            $this->matchedMetadata->rememberArtistNames($match['artist_names'] ?? []);
            $key = $this->key($listen->track_name, $listen->artist_name);
            $this->saveChoice($user, $listen, $match, $recordingName);

            $updated = 0;
            $groups = [];
            $catalogGroups = [];
            Listen::query()->where('user_id', $user->id)->whereNull('recording_mbid')
                ->chunkById(500, function ($listens) use ($key, $match, $recordingName, &$updated, &$groups, &$catalogGroups) {
                    foreach ($listens as $candidate) {
                        if ($this->key($candidate->track_name, $candidate->artist_name) !== $key) {
                            continue;
                        }
                        $metadata = $candidate->track_metadata;
                        $metadata['additional_info']['recording_mbid'] = $match['recording_mbid'];
                        if ($match['artist_mbids'] !== []) {
                            $metadata['additional_info']['artist_mbids'] = $match['artist_mbids'];
                        }
                        $candidate->update(['recording_mbid' => $match['recording_mbid'], 'track_metadata' => $metadata]);
                        $this->matchedMetadata->sync($candidate, $recordingName);
                        $updated++;
                        $catalog = $this->catalogGroups->native($candidate);
                        if ($catalog !== null) {
                            $catalogKey = $candidate->source.':'.$catalog['id'];
                            if (! array_key_exists($catalogKey, $catalogGroups)) {
                                $catalogGroups[$catalogKey] = $this->catalogGroups->groupId($candidate);
                            }
                            $group = $catalogGroups[$catalogKey];
                            if ($group !== null) {
                                $groups[$group] = $candidate;
                            }
                        }
                    }
                });

            foreach ($groups as $candidate) {
                $updated += $this->catalogGroups->recordMatch($user, $candidate, $match);
            }
            $updated += $this->catalogGroups->linkMatching($user, $this, [$match['recording_mbid']])['matched'];

            return $updated;
        });
    }
}
