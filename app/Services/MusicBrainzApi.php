<?php

namespace App\Services;

use App\Exceptions\MusicBrainzTemporaryException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MusicBrainzApi
{
    /** @return array{recording_mbid: string, artist_name: string, artist_mbids: list<string>, artist_names: array<string, string>, release_mbid: null}|null */
    public function matchListenRecording(string $title, string $artist, string $album): ?array
    {
        return $this->findListenRecordings($title, $artist, $album)['match'];
    }

    /**
     * @return array{match: array{recording_mbid: string, artist_name: string, artist_mbids: list<string>, artist_names: array<string, string>, release_mbid: null}|null, candidates: list<array{recording_mbid: string, track_name: string, artist_name: string, artist_mbids: list<string>, artist_names: array<string, string>, release_mbid: null, releases: list<string>, album_verified: bool}>, incomplete: bool}
     */
    public function findListenRecordings(string $title, string $artist, string $album, ?\Closure $shouldContinue = null): array
    {
        if (trim($title) === '' || trim($artist) === '' || mb_strtolower(trim($artist)) === 'unknown artist') {
            return ['match' => null, 'candidates' => [], 'incomplete' => false];
        }
        $result = $this->searchListenRecordings($title, $artist, $album, trim($album) !== '');
        if (trim($album) === '' || $result['match'] !== null || $result['incomplete']
            || collect($result['candidates'])->contains('album_verified', true)
            || ($shouldContinue !== null && ! $shouldContinue())) {
            return $result;
        }

        return $this->searchListenRecordings($title, $artist, $album, false);
    }

    /**
     * @return array{match: array{recording_mbid: string, artist_name: string, artist_mbids: list<string>, artist_names: array<string, string>, release_mbid: null}|null, candidates: list<array{recording_mbid: string, track_name: string, artist_name: string, artist_mbids: list<string>, artist_names: array<string, string>, release_mbid: null, releases: list<string>, album_verified: bool}>, incomplete: bool}
     */
    private function searchListenRecordings(string $title, string $artist, string $album, bool $requireAlbum): array
    {
        $query = 'recording:"'.$this->escape(trim($title)).'" AND artist:"'.$this->escape(trim($artist)).'"';
        if ($requireAlbum) {
            $query .= ' AND release:"'.$this->escape(trim($album)).'"';
        }
        $data = $this->get('recording', ['query' => $query, 'limit' => 100, 'offset' => 0, 'fmt' => 'json'], 'musicbrainz_api', background: true);
        if (! is_int($data['count'] ?? null) || ! is_array($data['recordings'] ?? null) || ! array_is_list($data['recordings'])) {
            $this->fail('musicbrainz_api', 'MusicBrainz returned an invalid search response.');
        }
        if ($data['count'] < count($data['recordings'])) {
            $this->fail('musicbrainz_api', 'MusicBrainz returned an invalid search response.');
        }
        $incomplete = $data['count'] > count($data['recordings']);
        $matches = [];
        $albumUncertain = false;
        foreach ($data['recordings'] as $recording) {
            $match = $this->parseRecording($recording, 'musicbrainz_api');
            if ($match['track_name'] !== $title || $match['artist_name'] !== $artist) {
                continue;
            }
            $releases = $recording['releases'] ?? [];
            if (! is_array($releases) || ! array_is_list($releases)) {
                $this->fail('musicbrainz_api', 'MusicBrainz returned invalid release metadata.');
            }
            $releaseTitles = [];
            foreach ($releases as $release) {
                if (! is_array($release) || ! is_string($release['title'] ?? null) || trim($release['title']) === '') {
                    $this->fail('musicbrainz_api', 'MusicBrainz returned invalid release metadata.');
                }
                $releaseTitles[] = $release['title'];
            }
            $verified = trim($album) === '' || in_array($album, $releaseTitles, true);
            $albumUncertain = $albumUncertain || ! $verified;
            $matches[$match['recording_mbid']] = $match + [
                'release_mbid' => null, 'releases' => array_values(array_unique($releaseTitles)), 'album_verified' => $verified,
            ];
        }
        $match = null;
        if (! $incomplete && (! $requireAlbum || ! $albumUncertain) && count($matches) === 1) {
            $candidate = reset($matches);
            $match = ['recording_mbid' => $candidate['recording_mbid'], 'artist_name' => $candidate['artist_name'],
                'artist_mbids' => $candidate['artist_mbids'], 'artist_names' => $candidate['artist_names'], 'release_mbid' => null];
        }

        return ['match' => $match, 'candidates' => $match === null ? array_values($matches) : [], 'incomplete' => $incomplete];
    }

    /** @return array{results: list<array{recording_mbid: string, track_name: string, artist_name: string, first_release_year: ?int, releases: list<array{mbid: string, title: string, artist: ?string, group_type: ?string}>}>, hasMore: bool} */
    public function search(string $title, string $artist, int $page): array
    {
        if (trim($title) === '') {
            return ['results' => [], 'hasMore' => false];
        }
        $query = 'recording:"'.$this->escape(trim($title)).'"';
        if (trim($artist) !== '') {
            $query .= ' AND artist:"'.$this->escape(trim($artist)).'"';
        }
        $offset = ($page - 1) * 30;
        $data = $this->get('recording', ['query' => $query, 'limit' => 30, 'offset' => $offset, 'fmt' => 'json'], 'musicbrainz_api');
        if (! is_int($data['count'] ?? null) || ! is_array($data['recordings'] ?? null) || ! array_is_list($data['recordings'])) {
            $this->fail('musicbrainz_api', 'MusicBrainz returned an invalid search response.');
        }
        $results = [];
        foreach ($data['recordings'] as $recording) {
            $match = $this->parseRecording($recording, 'musicbrainz_api');
            $date = $recording['first-release-date'] ?? null;
            if ($date !== null && (! is_string($date) || ($date !== '' && ! preg_match('/^\d{4}(?:-\d{2}(?:-\d{2})?)?$/D', $date)))) {
                $this->fail('musicbrainz_api', 'MusicBrainz returned an invalid first release date.');
            }
            $results[] = [
                'recording_mbid' => $match['recording_mbid'],
                'track_name' => $match['track_name'],
                'artist_name' => $match['artist_name'],
                'first_release_year' => $date === null || $date === '' ? null : (int) substr($date, 0, 4),
                'releases' => $this->searchReleases($recording['releases'] ?? []),
            ];
        }

        return ['results' => $results, 'hasMore' => $offset + count($results) < $data['count']];
    }

    /** @return list<array{mbid: string, title: string, artist: ?string, group_type: ?string}> */
    private function searchReleases(mixed $data): array
    {
        if (! is_array($data) || ! array_is_list($data)) {
            $this->fail('musicbrainz_api', 'MusicBrainz returned invalid release metadata.');
        }
        $releases = [];
        foreach ($data as $release) {
            if (! is_array($release) || ! is_string($release['id'] ?? null) || ! Str::isUuid($release['id'])
                || ! is_string($release['title'] ?? null) || trim($release['title']) === '') {
                $this->fail('musicbrainz_api', 'MusicBrainz returned invalid release metadata.');
            }
            $artist = null;
            if (isset($release['artist-credit'])) {
                if (! is_array($release['artist-credit']) || ! array_is_list($release['artist-credit'])) {
                    $this->fail('musicbrainz_api', 'MusicBrainz returned invalid release artist metadata.');
                }
                $artist = '';
                foreach ($release['artist-credit'] as $credit) {
                    $name = is_array($credit) ? ($credit['name'] ?? $credit['artist']['name'] ?? null) : null;
                    if (! is_string($name) || trim($name) === '' || (isset($credit['joinphrase']) && ! is_string($credit['joinphrase']))) {
                        $this->fail('musicbrainz_api', 'MusicBrainz returned invalid release artist metadata.');
                    }
                    $artist .= $name.($credit['joinphrase'] ?? '');
                }
                $artist = $artist === '' ? null : $artist;
            }
            $group = $release['release-group'] ?? [];
            if (! is_array($group) || (isset($group['primary-type']) && ! is_string($group['primary-type']))
                || (isset($group['secondary-types']) && (! is_array($group['secondary-types']) || ! array_is_list($group['secondary-types'])))) {
                $this->fail('musicbrainz_api', 'MusicBrainz returned invalid release group metadata.');
            }
            $types = [];
            foreach ([$group['primary-type'] ?? null, ...($group['secondary-types'] ?? [])] as $type) {
                if ($type === null) {
                    continue;
                }
                if (! is_string($type) || trim($type) === '') {
                    $this->fail('musicbrainz_api', 'MusicBrainz returned invalid release group metadata.');
                }
                $types[] = $type;
            }
            $releases[$release['id']] = ['mbid' => $release['id'], 'title' => $release['title'], 'artist' => $artist,
                'group_type' => $types === [] ? null : implode(' / ', array_unique($types))];
        }

        return array_values($releases);
    }

    /** @return array{recording_mbid: string, artist_name: string, artist_mbids: list<string>, artist_names: array<string, string>, release_mbid: null} */
    public function recording(string $id, string $title, string $artist): array
    {
        $data = $this->get('recording/'.$id, ['inc' => 'artist-credits', 'fmt' => 'json'], 'recording_mbid');
        $match = $this->parseRecording($data, 'recording_mbid');
        if ($match['recording_mbid'] !== $id || $match['track_name'] !== $title || $match['artist_name'] !== $artist) {
            $this->fail('recording_mbid', 'The selected recording no longer matches MusicBrainz. Search again.');
        }

        return ['recording_mbid' => $id, 'artist_name' => $match['artist_name'],
            'artist_mbids' => $match['artist_mbids'], 'artist_names' => $match['artist_names'], 'release_mbid' => null];
    }

    /** @return array{recording_mbid: string, artist_name: string, artist_mbids: list<string>, artist_names: array<string, string>, release_mbid: null}|null */
    public function matchArtistRecording(string $title, string $artistMbid, string $album): ?array
    {
        $query = 'recording:"'.$this->escape(trim($title)).'" AND arid:'.$artistMbid;
        if (trim($album) !== '') {
            $query .= ' AND release:"'.$this->escape(trim($album)).'"';
        }
        $data = $this->get('recording', ['query' => $query, 'limit' => 100, 'offset' => 0, 'fmt' => 'json'], 'musicbrainz_api');
        if (! is_int($data['count'] ?? null) || ! is_array($data['recordings'] ?? null) || ! array_is_list($data['recordings'])) {
            $this->fail('musicbrainz_api', 'MusicBrainz returned an invalid search response.');
        }
        if ($data['count'] > count($data['recordings'])) {
            return null;
        }
        $matches = [];
        foreach ($data['recordings'] as $recording) {
            $match = $this->parseRecording($recording, 'musicbrainz_api');
            if ($album !== '') {
                if (! is_array($recording['releases'] ?? null) || ! array_is_list($recording['releases'])) {
                    continue;
                }
                $hasAlbum = false;
                foreach ($recording['releases'] as $release) {
                    if (is_array($release) && ($release['title'] ?? null) === $album) {
                        $hasAlbum = true;
                        break;
                    }
                }
                if (! $hasAlbum) {
                    continue;
                }
            }
            if ($match['track_name'] === $title && $match['artist_mbids'] === [$artistMbid]) {
                $matches[$match['recording_mbid']] = $match;
            }
        }
        if (count($matches) !== 1) {
            return null;
        }
        $match = reset($matches);

        return ['recording_mbid' => $match['recording_mbid'], 'artist_name' => $match['artist_name'],
            'artist_mbids' => $match['artist_mbids'], 'artist_names' => $match['artist_names'], 'release_mbid' => null];
    }

    public function artistName(string $id): string
    {
        $data = $this->get('artist/'.$id, ['fmt' => 'json'], 'artist_name');
        $artist = $this->parseEntity('artist', $data, 'artist_name');
        if ($artist['mbid'] !== $id) {
            $this->fail('artist_name', 'MusicBrainz returned a different artist.');
        }

        return $artist['name'];
    }

    /** @return array{results: list<array{mbid: string, name: string, context: ?string}>, hasMore: bool} */
    public function searchEntity(string $type, string $name, int $page): array
    {
        if (trim($name) === '') {
            return ['results' => [], 'hasMore' => false];
        }
        $resource = $type === 'artist' ? 'artist' : 'release';
        $offset = ($page - 1) * 30;
        $data = $this->get($resource, [
            'query' => $resource.':"'.$this->escape(trim($name)).'"',
            'limit' => 30, 'offset' => $offset, 'fmt' => 'json',
        ], 'musicbrainz_api');
        $key = $resource === 'artist' ? 'artists' : 'releases';
        if (! is_int($data['count'] ?? null) || ! is_array($data[$key] ?? null) || ! array_is_list($data[$key])) {
            $this->fail('musicbrainz_api', 'MusicBrainz returned an invalid search response.');
        }
        $results = [];
        foreach ($data[$key] as $entity) {
            $results[] = $this->parseEntity($resource, $entity, 'musicbrainz_api');
        }

        return ['results' => $results, 'hasMore' => $offset + count($results) < $data['count']];
    }

    /** @return array{mbid: string, name: string, source: string, aliases?: list<string>} */
    public function entity(string $type, string $id, string $name): array
    {
        $resource = $type === 'artist' ? 'artist' : 'release';
        $query = $type === 'artist' ? ['fmt' => 'json', 'inc' => 'aliases'] : ['fmt' => 'json'];
        $data = $this->get($resource.'/'.$id, $query, 'mbid');
        $match = $this->parseEntity($resource, $data, 'mbid');
        if ($match['mbid'] !== $id || $match['name'] !== $name) {
            $this->fail('mbid', 'The selected item no longer matches MusicBrainz. Search again.');
        }

        $result = ['mbid' => $id, 'name' => $match['name'], 'source' => 'musicbrainz'];
        if ($type === 'artist') {
            $aliases = $data['aliases'] ?? [];
            if (! is_array($aliases) || ! array_is_list($aliases)) {
                $this->fail('mbid', 'MusicBrainz returned invalid artist aliases.');
            }
            $result['aliases'] = [];
            foreach ($aliases as $alias) {
                if (! is_array($alias) || ! is_string($alias['name'] ?? null) || trim($alias['name']) === '' || mb_strlen($alias['name']) > 255) {
                    $this->fail('mbid', 'MusicBrainz returned invalid artist aliases.');
                }
                $result['aliases'][] = $alias['name'];
            }
        }

        return $result;
    }

    /** @return array{mbid: string, name: string, context: ?string} */
    private function parseEntity(string $resource, mixed $data, string $errorKey): array
    {
        $nameKey = $resource === 'artist' ? 'name' : 'title';
        if (! is_array($data) || ! is_string($data['id'] ?? null) || ! Str::isUuid($data['id'])
            || ! is_string($data[$nameKey] ?? null) || trim($data[$nameKey]) === '' || mb_strlen($data[$nameKey]) > 255) {
            $this->fail($errorKey, 'MusicBrainz returned invalid '.$resource.' metadata.');
        }
        $context = [];
        if ($resource === 'release' && isset($data['artist-credit'])) {
            if (! is_array($data['artist-credit']) || ! array_is_list($data['artist-credit'])) {
                $this->fail($errorKey, 'MusicBrainz returned invalid release metadata.');
            }
            $credit = '';
            foreach ($data['artist-credit'] as $artist) {
                $name = is_array($artist) ? ($artist['name'] ?? $artist['artist']['name'] ?? null) : null;
                if (! is_string($name) || trim($name) === ''
                    || (isset($artist['joinphrase']) && ! is_string($artist['joinphrase']))) {
                    $this->fail($errorKey, 'MusicBrainz returned invalid release metadata.');
                }
                $credit .= $name.($artist['joinphrase'] ?? '');
            }
            if ($credit !== '') {
                $context[] = $credit;
            }
        }
        foreach ($resource === 'artist' ? ['disambiguation'] : ['date', 'country', 'disambiguation'] as $field) {
            if (isset($data[$field]) && ! is_string($data[$field])) {
                $this->fail($errorKey, 'MusicBrainz returned invalid '.$resource.' metadata.');
            }
            if (($data[$field] ?? '') !== '') {
                $context[] = $data[$field];
            }
        }

        return ['mbid' => $data['id'], 'name' => $data[$nameKey], 'context' => $context === [] ? null : implode(' · ', $context)];
    }

    private function escape(string $value): string
    {
        return preg_replace_callback('/[+\-&|!(){}\[\]^"~*?:\\\\\/]/u', fn ($match) => '\\'.$match[0], $value) ?? throw new \UnexpectedValueException('Invalid MusicBrainz search text.');
    }

    /** @param array<string, string|int> $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query, string $errorKey, bool $background = false): array
    {
        try {
            $response = Cache::lock('musicbrainz-api-lock', 30)->block(30, function () use ($path, $query, $background) {
                $cooldown = Cache::get('musicbrainz-api-cooldown');
                if ($cooldown !== null && (! is_numeric($cooldown) || (float) $cooldown > microtime(true))) {
                    if (! is_numeric($cooldown)) {
                        throw new \UnexpectedValueException('Invalid MusicBrainz API cooldown timestamp.');
                    }
                    throw new MusicBrainzTemporaryException('MusicBrainz is rate-limited.', (int) ceil((float) $cooldown - microtime(true)));
                }
                $last = Cache::get('musicbrainz-api-last-request');
                if ($last !== null && ! is_numeric($last)) {
                    throw new \UnexpectedValueException('Invalid MusicBrainz API throttle timestamp.');
                }
                if ($last !== null) {
                    $delay = 1.1 - (microtime(true) - (float) $last);
                    if ($delay > 0) {
                        Sleep::usleep((int) ceil($delay * 1000000));
                    }
                }
                Cache::put('musicbrainz-api-last-request', microtime(true), 60);

                $response = Http::acceptJson()
                    ->withUserAgent('playweft/1.0 (https://github.com/knutle/playweft)')
                    ->connectTimeout(5)->timeout(15)->withoutRedirecting()
                    ->get('https://musicbrainz.org/ws/2/'.$path, $query);
                if (in_array($response->status(), [429, 503], true) || ($background && $response->serverError())) {
                    $header = $response->header('Retry-After');
                    $retryAfter = ctype_digit($header) ? (int) $header : 0;
                    if ($retryAfter === 0 && ! ctype_digit($header)) {
                        $date = strtotime($header);
                        $retryAfter = $date === false ? 0 : max(0, $date - time());
                    }
                    if ($background) {
                        $retryAfter = max(5, $retryAfter);
                    }
                    if ($retryAfter > 0) {
                        if (! Cache::put('musicbrainz-api-cooldown', microtime(true) + $retryAfter, $retryAfter + 1)) {
                            throw new \RuntimeException('Could not save the MusicBrainz API cooldown.');
                        }
                    }
                    throw new MusicBrainzTemporaryException('MusicBrainz is unavailable or rate-limited.', $retryAfter);
                }

                return $response;
            });
        } catch (LockTimeoutException) {
            if ($background) {
                throw new MusicBrainzTemporaryException('MusicBrainz is busy.');
            }
            $this->fail($errorKey, 'MusicBrainz is busy. Please try again later.');
        } catch (ConnectionException) {
            if ($background) {
                throw new MusicBrainzTemporaryException('Could not connect to MusicBrainz.');
            }
            $this->fail($errorKey, 'Could not connect to MusicBrainz. Please try again.');
        } catch (MusicBrainzTemporaryException $exception) {
            if ($background) {
                throw $exception;
            }
            $this->fail($errorKey, 'MusicBrainz is unavailable or rate-limited. Please try again later.');
        }
        if (! $response->successful()) {
            $this->fail($errorKey, 'MusicBrainz could not complete this request. Please try again.');
        }
        $data = $response->json();
        if (! is_array($data)) {
            $this->fail($errorKey, 'MusicBrainz returned an invalid response.');
        }

        return $data;
    }

    /** @return array{recording_mbid: string, track_name: string, artist_name: string, artist_mbids: list<string>, artist_names: array<string, string>} */
    private function parseRecording(mixed $data, string $errorKey): array
    {
        if (! is_array($data) || ! is_string($data['id'] ?? null) || ! Str::isUuid($data['id'])
            || ! is_string($data['title'] ?? null) || trim($data['title']) === '' || mb_strlen($data['title']) > 255
            || ! is_array($data['artist-credit'] ?? null) || ! array_is_list($data['artist-credit']) || $data['artist-credit'] === []) {
            $this->fail($errorKey, 'MusicBrainz returned invalid recording metadata.');
        }
        $artist = '';
        $ids = [];
        $names = [];
        foreach ($data['artist-credit'] as $credit) {
            $name = is_array($credit) ? ($credit['name'] ?? $credit['artist']['name'] ?? null) : null;
            if (! is_array($credit) || ! is_string($name) || trim($name) === ''
                || ! is_string($credit['artist']['id'] ?? null) || ! Str::isUuid($credit['artist']['id'])
                || (isset($credit['joinphrase']) && ! is_string($credit['joinphrase']))) {
                $this->fail($errorKey, 'MusicBrainz returned invalid artist credit metadata.');
            }
            $artist .= $name.($credit['joinphrase'] ?? '');
            $ids[] = $credit['artist']['id'];
            if (isset($credit['artist']['name'])) {
                $canonicalName = $credit['artist']['name'];
                if (! is_string($canonicalName) || trim($canonicalName) === '' || mb_strlen($canonicalName) > 255
                    || (isset($names[$credit['artist']['id']]) && $names[$credit['artist']['id']] !== $canonicalName)) {
                    $this->fail($errorKey, 'MusicBrainz returned invalid artist credit metadata.');
                }
                $names[$credit['artist']['id']] = $canonicalName;
            }
        }
        if (mb_strlen($artist) > 255) {
            $this->fail($errorKey, 'MusicBrainz returned an artist credit that is too long.');
        }

        return ['recording_mbid' => $data['id'], 'track_name' => $data['title'], 'artist_name' => $artist,
            'artist_mbids' => $ids, 'artist_names' => $names];
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
