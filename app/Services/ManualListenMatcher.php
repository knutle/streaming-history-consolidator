<?php

namespace App\Services;

use App\Models\Listen;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualListenMatcher
{
    public function __construct(private MatchedMusicMetadata $matchedMetadata) {}

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

    /** @param array{recording_mbid: string, artist_name: string, artist_mbids: list<string>, release_mbid: null} $match */
    public function remember(User $user, Listen $listen, array $match): int
    {
        return DB::transaction(function () use ($user, $listen, $match) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            if (! Listen::query()->where('user_id', $user->id)->whereKey($listen->id)->whereNull('recording_mbid')->exists()) {
                throw ValidationException::withMessages(['recording_mbid' => 'This listen has already been identified or deleted.']);
            }
            $key = $this->key($listen->track_name, $listen->artist_name);
            DB::table('listen_matches')->updateOrInsert(
                ['user_id' => $user->id, 'song_key' => $key],
                ['match' => json_encode($match, JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now()],
            );

            $updated = 0;
            Listen::query()->where('user_id', $user->id)->whereNull('recording_mbid')
                ->chunkById(500, function ($listens) use ($key, $match, &$updated) {
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
                        $this->matchedMetadata->sync($candidate);
                        $updated++;
                    }
                });

            return $updated;
        });
    }
}
