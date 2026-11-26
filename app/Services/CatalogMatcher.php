<?php

namespace App\Services;

use App\Models\Listen;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogMatcher
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private array $remembered = [];

    /**
     * @return array{provider: string, id: string, track_name: string, artist_name: string, release_name: ?string, duration_ms: ?int, origin: string}
     */
    public function match(string $provider, string $id, string $track, string $artist, ?string $album, ?int $duration, string $origin): array
    {
        return [
            'provider' => $provider,
            'id' => $id,
            'track_name' => $track,
            'artist_name' => $artist,
            'release_name' => $album,
            'duration_ms' => $duration,
            'origin' => $origin,
        ];
    }

    public function identifier(string $provider, string $value): ?string
    {
        $value = trim($value);
        if ($provider === 'spotify' && preg_match('/^spotify:track:([a-zA-Z0-9]{22})$/D', $value, $match)) {
            return $match[1];
        }
        $url = parse_url($value);
        if ($url === false || ($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass']) || isset($url['port'])) {
            return null;
        }
        $host = strtolower($url['host'] ?? '');
        $path = $url['path'] ?? '';
        if ($provider === 'spotify' && $host === 'open.spotify.com'
            && preg_match('~^/(?:intl-[a-z]{2}/)?track/([a-zA-Z0-9]{22})/?$~D', $path, $match)) {
            return $match[1];
        }
        if ($provider === 'apple' && $host === 'music.apple.com') {
            parse_str($url['query'] ?? '', $query);
            if (preg_match('~^/(?:[a-z]{2}/)?(?:album|song)/~i', $path)
                && is_string($query['i'] ?? null) && preg_match('/^[1-9][0-9]{0,19}$/D', $query['i'])) {
                return $query['i'];
            }
            if (preg_match('~^/(?:[a-z]{2}/)?song/(?:[^/]+/)?([1-9][0-9]{0,19})/?$~Di', $path, $match)) {
                return $match[1];
            }
        }

        return null;
    }

    public function url(string $provider, string $id): string
    {
        return $provider === 'spotify' ? 'https://open.spotify.com/track/'.$id : 'https://music.apple.com/us/song/'.$id;
    }

    private function key(string $track, string $artist, ?string $album): ?string
    {
        if (trim($track) === '' || trim($artist) === '' || $artist === 'Unknown artist' || trim($album ?? '') === '') {
            return null;
        }

        return hash('sha256', json_encode(array_map(
            fn ($value) => mb_strtolower(trim($value)),
            [$track, $artist, $album],
        ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, array<string, mixed>> */
    public function remembered(User $user, string $track, string $artist, ?string $album): array
    {
        $key = $this->key($track, $artist, $album);
        if ($key === null) {
            return [];
        }
        $cacheKey = $user->id.':'.$key;
        if (array_key_exists($cacheKey, $this->remembered)) {
            return $this->remembered[$cacheKey];
        }
        $matches = [];
        foreach (DB::table('catalog_matches')->where('user_id', $user->id)->where('song_key', $key)->get() as $row) {
            $matches[$row->provider] = json_decode($row->match, true, 512, JSON_THROW_ON_ERROR);
        }

        return $this->remembered[$cacheKey] = $matches;
    }

    /**
     * @param  array<string, mixed>  $appleMusic
     * @return array<string, array<string, mixed>>
     */
    public function imported(User $user, string $source, string $uri, array $appleMusic, string $track, string $artist, ?string $album, ?int $duration): array
    {
        $catalogs = [];
        $id = $source === 'spotify' ? $this->identifier('spotify', $uri) : ($appleMusic['catalog_id'] ?? null);
        if ($source === 'apple' && (! is_string($id) || ! preg_match('/^[1-9][0-9]{0,19}$/D', $id))) {
            $id = null;
        }
        if (is_string($id)) {
            $catalogArtist = $source === 'apple' && is_string($appleMusic['track_artist'] ?? null)
                ? $appleMusic['track_artist'] : $artist;
            $catalogs[$source] = $this->match($source, $id, $track, $catalogArtist, $album, $duration, 'import');
        }

        return $catalogs + $this->remembered($user, $track, $artist, $album);
    }

    /** @return array<string, array<string, mixed>> */
    public function forListen(Listen $listen, bool $includeRelated = false): array
    {
        $catalogs = $listen->track_metadata['additional_info']['catalogs'] ?? [];
        $apple = $listen->track_metadata['additional_info']['apple_music'] ?? [];
        if (! isset($catalogs['apple']) && is_string($apple['catalog_id'] ?? null)
            && preg_match('/^[1-9][0-9]{0,19}$/D', $apple['catalog_id'])) {
            $duration = $listen->track_metadata['additional_info']['duration_ms'] ?? null;
            $artist = is_string($apple['track_artist'] ?? null) ? $apple['track_artist'] : $listen->artist_name;
            $catalogs['apple'] = $this->match('apple', $apple['catalog_id'], $listen->track_name,
                $artist, $listen->release_name, is_int($duration) ? $duration : null, 'import');
        }
        if ($includeRelated && $listen->recording_mbid !== null && (! isset($catalogs['apple']) || ! isset($catalogs['spotify']))) {
            $candidates = ['apple' => [], 'spotify' => []];
            Listen::query()->where('user_id', $listen->user_id)->where('recording_mbid', $listen->recording_mbid)
                ->whereKeyNot($listen->id)
                ->chunkById(500, function ($related) use (&$candidates) {
                    foreach ($related as $item) {
                        $provider = $item->source;
                        $match = $this->forListen($item)[$provider] ?? null;
                        if (isset($candidates[$provider]) && is_array($match) && ($match['origin'] ?? null) === 'import'
                            && is_string($match['id'] ?? null)) {
                            $candidates[$provider][$match['id']] = $match;
                        }
                    }
                });
            foreach ($candidates as $provider => $matches) {
                if (! isset($catalogs[$provider]) && count($matches) === 1) {
                    $match = reset($matches);
                    $match['origin'] = 'local';
                    $catalogs[$provider] = $match;
                }
            }
        }

        return $catalogs;
    }

    public function musicBrainzSeed(Listen $listen): ?string
    {
        $catalogs = $this->forListen($listen, includeRelated: true);
        if ($catalogs === []) {
            return null;
        }
        $match = $catalogs['apple'] ?? $catalogs['spotify'] ?? null;
        if (! is_array($match)) {
            return null;
        }
        $params = [
            'edit-recording.name' => $match['track_name'],
            'edit-recording.artist_credit.names.0.name' => $match['artist_name'],
        ];
        if (is_int($match['duration_ms'] ?? null) && $match['duration_ms'] > 0) {
            $seconds = intdiv($match['duration_ms'], 1000);
            $params['edit-recording.length'] = sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
        }
        $sources = [];
        foreach (['apple', 'spotify'] as $provider) {
            if (isset($catalogs[$provider]['id']) && is_string($catalogs[$provider]['id'])) {
                $sources[] = $this->url($provider, $catalogs[$provider]['id']);
            }
        }
        $params['edit-recording.edit_note'] = "Sources to review:\n".implode("\n", $sources);

        return 'https://musicbrainz.org/recording/create?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** @param array<string, mixed> $match */
    public function remember(User $user, Listen $listen, array $match): int
    {
        return DB::transaction(function () use ($user, $listen, $match) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $current = Listen::query()->where('user_id', $user->id)->find($listen->id);
            if ($current === null) {
                throw ValidationException::withMessages(['catalog_url' => 'This listen has been deleted.']);
            }
            $key = $this->key($current->track_name, $current->artist_name, $current->release_name);
            if ($key !== null) {
                DB::table('catalog_matches')->updateOrInsert(
                    ['user_id' => $user->id, 'song_key' => $key, 'provider' => $match['provider']],
                    ['match' => json_encode($match, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()],
                );
                unset($this->remembered[$user->id.':'.$key]);
            }
            $updated = 0;
            Listen::query()->where('user_id', $user->id)
                ->where('track_name', $current->track_name)
                ->where('artist_name', $current->artist_name)
                ->when($current->release_name === null, fn ($query) => $query->whereNull('release_name'),
                    fn ($query) => $query->where('release_name', $current->release_name))
                ->chunkById(500, function ($listens) use ($current, $key, $match, &$updated) {
                    foreach ($listens as $candidate) {
                        if ($candidate->id !== $current->id && ($key === null || $this->key($candidate->track_name, $candidate->artist_name, $candidate->release_name) !== $key)) {
                            continue;
                        }
                        $metadata = $candidate->track_metadata;
                        $catalogs = $this->forListen($candidate);
                        if ($candidate->id !== $current->id && isset($catalogs[$match['provider']])
                            && ($catalogs[$match['provider']]['origin'] ?? null) !== 'manual') {
                            continue;
                        }
                        $catalogs[$match['provider']] = $match;
                        $metadata['additional_info']['catalogs'] = $catalogs;
                        $candidate->update(['track_metadata' => $metadata]);
                        $updated++;
                    }
                });

            return $updated;
        });
    }
}
