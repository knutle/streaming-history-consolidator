<?php

namespace App\Services;

use App\Models\Listen;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StreamingHistoryImporter
{
    public function __construct(
        private StreamingHistoryReader $reader,
        private MusicBrainzMatcher $matcher,
        private ManualListenMatcher $manualMatcher,
    ) {}

    /** @return array{imported: int, duplicates: int, matched: int} */
    public function import(User $user, string $source, string $path): array
    {
        return DB::transaction(function () use ($user, $source, $path) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $counts = ['imported' => 0, 'duplicates' => 0, 'matched' => 0];
            $matches = [];

            foreach ($this->reader->read($path, $source) as $entry) {
                $identityValues = $source === 'apple' && $entry['uri'] !== ''
                    ? ['event_id', $entry['uri']]
                    : [
                        $entry['timestamp'], $entry['played_ms'], $entry['start_time'],
                        $entry['uri'] !== '' ? $entry['uri'] : [$entry['track'], $entry['identity_artist'] ?? $entry['artist'], $entry['album']],
                    ];
                $fingerprint = hash('sha256', json_encode($identityValues, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $identity = ['user_id' => $user->id, 'source' => $source, 'fingerprint' => $fingerprint];
                if (Listen::query()->where($identity)->exists()) {
                    $counts['duplicates']++;

                    continue;
                }

                $key = json_encode([$entry['track'], $entry['artist'], $entry['album']], JSON_THROW_ON_ERROR);
                if (! array_key_exists($key, $matches)) {
                    try {
                        $matches[$key] = $this->manualMatcher->remembered($user, $entry['track'], $entry['artist'])
                            ?? $this->matcher->match($entry['track'], $entry['artist'], $entry['album']);
                    } catch (QueryException $exception) {
                        report($exception);

                        throw ValidationException::withMessages([
                            'file' => 'The local MusicBrainz database could not be queried. Check its connection and schema; nothing was imported.',
                        ]);
                    }
                }
                $match = $matches[$key];
                $artist = $entry['artist'] !== '' ? $entry['artist'] : ($match['artist_name'] ?? 'Unknown artist');
                $timestamp = CarbonImmutable::parse($entry['timestamp']);
                if (! $entry['start_time']) {
                    $timestamp = $timestamp->subMilliseconds($entry['played_ms']);
                }

                $additionalInfo = ['music_service' => $source === 'spotify' ? 'spotify.com' : 'music.apple.com'];
                if ($entry['duration_ms'] !== null && $entry['duration_ms'] > 0) {
                    $additionalInfo['duration_ms'] = $entry['duration_ms'];
                }
                if ($match !== null) {
                    $additionalInfo['recording_mbid'] = $match['recording_mbid'];
                    if ($match['artist_mbids'] !== []) {
                        $additionalInfo['artist_mbids'] = $match['artist_mbids'];
                    }
                    if ($match['release_mbid'] !== null) {
                        $additionalInfo['release_mbid'] = $match['release_mbid'];
                    }
                }
                $metadata = [
                    'artist_name' => $artist,
                    'track_name' => $entry['track'],
                    'additional_info' => $additionalInfo,
                ];
                if ($entry['album'] !== '') {
                    $metadata['release_name'] = $entry['album'];
                }

                $listen = Listen::query()->firstOrCreate($identity, [
                    'listened_at' => $timestamp->getTimestamp(),
                    'track_name' => $entry['track'],
                    'artist_name' => $artist,
                    'release_name' => $entry['album'] !== '' ? $entry['album'] : null,
                    'played_ms' => $entry['played_ms'],
                    'recording_mbid' => $match['recording_mbid'] ?? null,
                    'track_metadata' => $metadata,
                ]);
                if ($listen->wasRecentlyCreated) {
                    $counts['imported']++;
                    $counts['matched'] += (int) ($match !== null);
                } else {
                    $counts['duplicates']++;
                }
            }

            DB::table('import_batches')->insert([
                'user_id' => $user->id,
                'source' => $source,
                ...$counts,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $counts;
        });
    }
}
