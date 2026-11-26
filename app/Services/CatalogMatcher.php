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

    /** @var array<string, array<string, mixed>> */
    private array $metadataCache = [];

    /**
     * @return array{provider: string, id: string, track_name: string, artist_name: string, release_name: ?string, duration_ms: ?int, origin: string, isrc: ?string}
     */
    public function match(string $provider, string $id, string $track, string $artist, ?string $album, ?int $duration, string $origin, ?string $isrc = null): array
    {
        return [
            'provider' => $provider,
            'id' => $id,
            'track_name' => $track,
            'artist_name' => $artist,
            'release_name' => $album,
            'duration_ms' => $duration,
            'origin' => $origin,
            'isrc' => $isrc,
        ];
    }

    /**
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $extra
     */
    public function storeMetadata(int $userId, array $match, array $extra = [], bool $overwriteManual = true): void
    {
        $provider = $match['provider'] ?? null;
        $id = $match['id'] ?? null;
        $origin = $match['origin'] ?? null;
        if (! is_string($provider) || ! in_array($provider, ['spotify', 'apple'], true) || ! is_string($id)
            || $this->identifier($provider, $this->url($provider, $id)) !== $id
            || ! in_array($origin, ['import', 'manual', 'local'], true)) {
            throw new \UnexpectedValueException('Invalid catalog match metadata.');
        }
        if ($origin === 'local') {
            return;
        }
        $key = ['user_id' => $userId, 'catalog_key' => hash('sha256', $provider.':'.$id)];
        $row = DB::table('catalog_track_metadata')->where($key)->first();
        $stored = $row === null ? ['values' => [], 'origins' => []] : json_decode($row->metadata, true, 512, JSON_THROW_ON_ERROR);
        foreach (['track_name', 'artist_name', 'release_name', 'duration_ms', 'isrc', 'album_artist', 'genre', 'release_date', 'track_number', 'disc_number'] as $field) {
            $value = $match[$field] ?? $extra[$field] ?? null;
            if ($value === null || $value === '' || ($field === 'artist_name' && $value === 'Unknown artist')) {
                continue;
            }
            if ($field === 'isrc') {
                $value = Isrc::normalize($value);
                if ($value === null) {
                    throw new \UnexpectedValueException('Invalid ISRC in catalog metadata.');
                }
            }
            if (! isset($stored['values'][$field])
                || ($origin === 'manual' && ($overwriteManual || ($stored['origins'][$field] ?? null) !== 'manual'))) {
                $stored['values'][$field] = $value;
                $stored['origins'][$field] = $origin;
            }
        }
        $encoded = json_encode($stored, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if ($row === null) {
            DB::table('catalog_track_metadata')->insert($key + [
                'provider' => $provider, 'catalog_id' => $id,
                'metadata' => $encoded, 'created_at' => now(), 'updated_at' => now(),
            ]);
            unset($this->metadataCache[$userId.':'.$key['catalog_key']]);
        } elseif ($row->metadata !== $encoded) {
            DB::table('catalog_track_metadata')->where($key)->update(['metadata' => $encoded, 'updated_at' => now()]);
            unset($this->metadataCache[$userId.':'.$key['catalog_key']]);
        }
    }

    /** @param array<string, mixed> $match
     * @return array<string, mixed>
     */
    private function withMetadata(int $userId, array $match): array
    {
        $key = hash('sha256', $match['provider'].':'.$match['id']);
        $cacheKey = $userId.':'.$key;
        if (! array_key_exists($cacheKey, $this->metadataCache)) {
            $row = DB::table('catalog_track_metadata')->where(['user_id' => $userId, 'catalog_key' => $key])->value('metadata');
            $this->metadataCache[$cacheKey] = $row === null ? [] : json_decode($row, true, 512, JSON_THROW_ON_ERROR)['values'];
        }

        return array_replace($match + ['isrc' => null], $this->metadataCache[$cacheKey]);
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

    public function entityIdentifier(string $type, string $provider, string $value): ?string
    {
        $value = trim($value);
        if ($provider === 'spotify' && preg_match('/^spotify:'.preg_quote($type, '/').':([a-zA-Z0-9]{22})$/D', $value, $match)) {
            return $match[1];
        }
        $url = parse_url($value);
        if ($url === false || ($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass']) || isset($url['port'])) {
            return null;
        }
        $host = strtolower($url['host'] ?? '');
        $path = $url['path'] ?? '';
        if ($provider === 'spotify' && $host === 'open.spotify.com'
            && preg_match('~^/(?:intl-[a-z]{2}/)?'.preg_quote($type, '~').'/([a-zA-Z0-9]{22})/?$~D', $path, $match)) {
            return $match[1];
        }
        if ($provider === 'apple' && $host === 'music.apple.com'
            && preg_match('~^/(?:[a-z]{2}/)?'.preg_quote($type, '~').'/(?:[^/]+/)?([1-9][0-9]{0,19})/?$~Di', $path, $match)) {
            parse_str($url['query'] ?? '', $query);
            if ($type !== 'album' || ! array_key_exists('i', $query)) {
                return $match[1];
            }
        }

        return null;
    }

    public function url(string $provider, string $id): string
    {
        return $provider === 'spotify' ? 'https://open.spotify.com/track/'.$id : 'https://music.apple.com/us/song/'.$id;
    }

    public function key(string $track, string $artist, ?string $album): ?string
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
    public function imported(User $user, string $source, string $uri, array $appleMusic, string $track, string $artist, ?string $album, ?int $duration, ?string $isrc = null): array
    {
        $catalogs = [];
        $id = $source === 'spotify' ? $this->identifier('spotify', $uri) : ($appleMusic['catalog_id'] ?? null);
        if ($source === 'apple' && (! is_string($id) || ! preg_match('/^[1-9][0-9]{0,19}$/D', $id))) {
            $id = null;
        }
        if (is_string($id)) {
            $catalogArtist = $source === 'apple' && is_string($appleMusic['track_artist'] ?? null)
                ? $appleMusic['track_artist'] : $artist;
            $catalogs[$source] = $this->match($source, $id, $track, $catalogArtist, $album, $duration, 'import', $isrc);
            $this->storeMetadata($user->id, $catalogs[$source], $appleMusic);
        }

        foreach ($catalogs + $this->remembered($user, $track, $artist, $album) as $provider => $match) {
            $catalogs[$provider] = $this->withMetadata($user->id, $match);
        }

        return $catalogs;
    }

    /** @return array<string, array<string, mixed>> */
    public function forListen(Listen $listen, bool $includeRelated = false, bool $includeStored = true): array
    {
        $catalogs = $listen->track_metadata['additional_info']['catalogs'] ?? [];
        $apple = $listen->track_metadata['additional_info']['apple_music'] ?? [];
        if (! isset($catalogs['apple']) && is_string($apple['catalog_id'] ?? null)
            && preg_match('/^[1-9][0-9]{0,19}$/D', $apple['catalog_id'])) {
            $duration = $listen->track_metadata['additional_info']['duration_ms'] ?? null;
            $artist = is_string($apple['track_artist'] ?? null) ? $apple['track_artist'] : $listen->artist_name;
            $catalogs['apple'] = $this->match('apple', $apple['catalog_id'], $listen->track_name,
                $artist, $listen->release_name, is_int($duration) ? $duration : null, 'import',
                is_string($apple['isrc'] ?? null) ? $apple['isrc'] : null);
        }
        if ($includeStored) {
            foreach ($catalogs as $provider => $match) {
                $catalogs[$provider] = $this->withMetadata($listen->user_id, $match);
            }
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
        $params = [];
        $title = $match['track_name'] ?? null;
        $titlesAgree = is_string($title) && trim($title) !== '';
        foreach ($catalogs as $catalog) {
            if (($catalog['track_name'] ?? null) !== $title) {
                $titlesAgree = false;
                break;
            }
        }
        if ($titlesAgree) {
            $params['edit-recording.name'] = $title;
            foreach ([$match, ...array_values($catalogs)] as $catalog) {
                $artist = $catalog['artist_name'] ?? null;
                if (is_string($artist) && trim($artist) !== '' && $artist !== 'Unknown artist') {
                    $params['edit-recording.artist_credit.names.0.name'] = $artist;
                    $params['edit-recording.artist_credit.names.0.artist.name'] = $artist;
                    break;
                }
            }
        }
        if (is_int($match['duration_ms'] ?? null) && $match['duration_ms'] > 0) {
            $seconds = intdiv($match['duration_ms'], 1000) + ($match['duration_ms'] % 1000 >= 500 ? 1 : 0);
            if ($seconds > 0) {
                $params['edit-recording.length'] = sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
            }
        }
        $sources = [];
        foreach (['apple', 'spotify'] as $provider) {
            if (isset($catalogs[$provider]['id']) && is_string($catalogs[$provider]['id'])) {
                $catalog = $catalogs[$provider];
                $name = $provider === 'apple' ? 'Apple Music' : 'Spotify';
                $source = [$name.': '.$this->url($provider, $catalog['id'])];
                foreach (['track_name' => 'Track', 'artist_name' => 'Artist', 'release_name' => 'Album', 'isrc' => 'ISRC'] as $field => $label) {
                    if (is_string($catalog[$field] ?? null) && trim($catalog[$field]) !== ''
                        && ! ($field === 'artist_name' && $catalog[$field] === 'Unknown artist')) {
                        $source[] = $label.': '.$catalog[$field];
                    }
                }
                if (is_int($catalog['duration_ms'] ?? null) && $catalog['duration_ms'] > 0) {
                    $source[] = 'Duration (ms): '.$catalog['duration_ms'];
                }
                $sources[] = implode("\n", $source);
            }
        }
        $params['edit-recording.edit_note'] = "Catalog matches to verify (metadata may include user edits; confirm each source refers to this recording):\n\n"
            .implode("\n\n", $sources);

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
            $this->storeMetadata($user->id, $match);
            $key = $this->key($current->track_name, $current->artist_name, $current->release_name);
            if ($key !== null) {
                DB::table('catalog_matches')->updateOrInsert(
                    ['user_id' => $user->id, 'song_key' => $key, 'provider' => $match['provider']],
                    ['match' => json_encode($match + [
                        'source_track' => $current->track_name,
                        'source_artist' => $current->artist_name,
                        'source_album' => $current->release_name,
                    ], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()],
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
