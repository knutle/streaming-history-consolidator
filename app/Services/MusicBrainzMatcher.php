<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class MusicBrainzMatcher
{
    /** @return array{results: list<array{mbid: string, name: string, context: ?string}>, hasMore: bool} */
    public function searchEntity(string $type, string $prefix, int $page): array
    {
        if (! config('musicbrainz.enabled') || trim($prefix) === '') {
            return ['results' => [], 'hasMore' => false];
        }
        $prefix = trim($prefix);
        $db = DB::connection('musicbrainz');
        if (config('musicbrainz.format') === 'canonical' && $db->getDriverName() === 'sqlite') {
            $name = $type === 'artist' ? 'artist_credit_name' : 'release_name';
            $id = $type === 'artist' ? 'artist_mbids' : 'release_mbid';
            $query = $db->table('canonical_recordings')->where($name, '>=', $prefix)
                ->where($name, '<', $prefix."\u{10ffff}")->where($id, '!=', '')
                ->select("{$id} as mbid", "{$name} as name")->distinct()->orderBy($name)->orderBy($id);
            if ($type === 'artist') {
                $query->whereRaw('length(artist_mbids) = 36');
            } else {
                $query->addSelect('artist_credit_name as context')->orderBy('artist_credit_name');
            }
        } else {
            $table = $type === 'artist' ? 'artist' : 'release';
            $query = $db->table($table)->where('name', '>=', $prefix)->where('name', '<', $prefix."\u{10ffff}")
                ->select('gid as mbid', 'name')->orderBy('name')->orderBy('gid');
            if ($type === 'album') {
                $query = $db->table('release')->join('artist_credit', 'artist_credit.id', '=', 'release.artist_credit')
                    ->where('release.name', '>=', $prefix)->where('release.name', '<', $prefix."\u{10ffff}")
                    ->select('release.gid as mbid', 'release.name', 'artist_credit.name as context')
                    ->orderBy('release.name')->orderBy('release.gid');
            }
        }
        $rows = $query->offset(($page - 1) * 30)->limit(31)->get();

        return [
            'results' => array_values($rows->take(30)->map(fn ($row) => [
                'mbid' => (string) $row->mbid, 'name' => (string) $row->name,
                'context' => isset($row->context) ? (string) $row->context : null,
            ])->all()),
            'hasMore' => $rows->count() > 30,
        ];
    }

    /** @return array{mbid: string, name: string, source: string}|null */
    public function entity(string $type, string $mbid, string $name): ?array
    {
        if (! config('musicbrainz.enabled')) {
            return null;
        }
        $db = DB::connection('musicbrainz');
        if (config('musicbrainz.format') === 'canonical' && $db->getDriverName() === 'sqlite') {
            $id = $type === 'artist' ? 'artist_mbids' : 'release_mbid';
            $column = $type === 'artist' ? 'artist_credit_name' : 'release_name';
            $exists = $db->table('canonical_recordings')->where($id, $mbid)->where($column, $name)->exists();
            $source = 'canonical_credit';
        } else {
            $table = $type === 'artist' ? 'artist' : 'release';
            $exists = $db->table($table)->where('gid', $mbid)->where('name', $name)->exists();
            $source = 'musicbrainz';
        }

        return $exists ? ['mbid' => $mbid, 'name' => $name, 'source' => $source] : null;
    }

    /** @return array{results: list<array{recording_mbid: string, track_name: string, artist_name: string}>, hasMore: bool} */
    public function search(string $prefix, string $artist, int $page): array
    {
        if (! config('musicbrainz.enabled') || trim($prefix) === '') {
            return ['results' => [], 'hasMore' => false];
        }

        $prefix = trim($prefix);
        $artist = trim($artist);
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($artist)).'%';
        $db = DB::connection('musicbrainz');
        if (config('musicbrainz.format') === 'canonical' && $db->getDriverName() === 'sqlite') {
            $query = $db->table('canonical_recordings')
                ->where('recording_name', '>=', $prefix)
                ->where('recording_name', '<', $prefix."\u{10ffff}")
                ->select('recording_mbid', 'recording_name as track_name', 'artist_credit_name as artist_name')
                ->distinct()->orderBy('recording_name')->orderBy('recording_mbid')->orderBy('artist_credit_name');
            if ($artist !== '') {
                $query->whereRaw("LOWER(artist_credit_name) LIKE ? ESCAPE '!'", [$pattern]);
            }
        } else {
            $query = $db->table('recording')
                ->join('artist_credit', 'recording.artist_credit', '=', 'artist_credit.id')
                ->where('recording.name', '>=', $prefix)
                ->where('recording.name', '<', $prefix."\u{10ffff}")
                ->select('recording.gid as recording_mbid', 'recording.name as track_name', 'artist_credit.name as artist_name')
                ->orderBy('recording.name')->orderBy('recording.gid');
            if ($artist !== '') {
                $query->whereRaw("LOWER(artist_credit.name) LIKE ? ESCAPE '!'", [$pattern]);
            }
        }
        $rows = $query->offset(($page - 1) * 30)->limit(31)->get();

        return [
            'results' => array_values($rows->take(30)->map(fn ($row) => [
                'recording_mbid' => (string) $row->recording_mbid,
                'track_name' => (string) $row->track_name,
                'artist_name' => (string) $row->artist_name,
            ])->all()),
            'hasMore' => $rows->count() > 30,
        ];
    }

    /** @return array{recording_mbid: string, artist_name: string, artist_mbids: list<string>, release_mbid: null}|null */
    public function recording(string $mbid, string $track, string $artist): ?array
    {
        if (! config('musicbrainz.enabled')) {
            return null;
        }

        $db = DB::connection('musicbrainz');
        if (config('musicbrainz.format') === 'canonical' && $db->getDriverName() === 'sqlite') {
            $rows = $db->table('canonical_recordings')->where('recording_name', $track)
                ->where('recording_mbid', $mbid)->where('artist_credit_name', $artist)
                ->distinct()->limit(2)->get(['artist_credit_name', 'artist_mbids']);
            if ($rows->isEmpty()) {
                return null;
            }
            $row = $rows->first();

            return [
                'recording_mbid' => $mbid,
                'artist_name' => (string) $row->artist_credit_name,
                'artist_mbids' => $rows->count() !== 1 || $row->artist_mbids === '' ? [] : explode(',', $row->artist_mbids),
                'release_mbid' => null,
            ];
        }

        $row = $db->table('recording')->join('artist_credit', 'recording.artist_credit', '=', 'artist_credit.id')
            ->where('recording.name', $track)->where('recording.gid', $mbid)->where('artist_credit.name', $artist)
            ->first(['artist_credit.id as artist_credit_id', 'artist_credit.name as artist_name']);
        if ($row === null) {
            return null;
        }
        $artistMbids = $db->table('artist_credit_name')->join('artist', 'artist.id', '=', 'artist_credit_name.artist')
            ->where('artist_credit_name.artist_credit', $row->artist_credit_id)
            ->orderBy('artist_credit_name.position')->pluck('artist.gid')->map(fn ($gid) => (string) $gid)->all();

        return ['recording_mbid' => $mbid, 'artist_name' => (string) $row->artist_name, 'artist_mbids' => array_values($artistMbids), 'release_mbid' => null];
    }

    /**
     * @return array{recording_mbid: string, artist_name: string, artist_mbids: list<string>, release_mbid: ?string}|null
     */
    public function match(string $track, string $artist, string $album, ?bool &$ambiguous = null): ?array
    {
        $ambiguous = false;
        if (! config('musicbrainz.enabled') || ($artist === '' && $album === '')) {
            return null;
        }

        $db = DB::connection('musicbrainz');
        if (config('musicbrainz.format') === 'canonical' && $db->getDriverName() === 'sqlite') {
            return $this->matchCanonical($track, $artist, $album, $ambiguous);
        }

        $query = $db->table('recording')
            ->join('artist_credit', 'recording.artist_credit', '=', 'artist_credit.id')
            ->where('recording.name', $track)
            ->select('recording.id', 'recording.gid', 'artist_credit.id as artist_credit_id', 'artist_credit.name as artist_name');

        if ($album !== '') {
            $query->whereExists(function ($query) use ($album) {
                $query->selectRaw('1')
                    ->from('track')
                    ->join('medium', 'track.medium', '=', 'medium.id')
                    ->join('release', 'medium.release', '=', 'release.id')
                    ->whereColumn('track.recording', 'recording.id')
                    ->where('release.name', $album);
            });
        }

        $recordings = $this->matchingRecords($query->cursor(), $artist, 'artist_name');
        if (count($recordings) === 0 && $artist !== '') {
            $aliases = (clone $query)
                ->join('artist_credit_name', 'artist_credit_name.artist_credit', '=', 'artist_credit.id')
                ->join('artist_alias', 'artist_alias.artist', '=', 'artist_credit_name.artist')
                ->whereNotExists(function ($subquery) {
                    $subquery->selectRaw('1')->from('artist_credit_name as other_credit')
                        ->whereColumn('other_credit.artist_credit', 'artist_credit.id')
                        ->whereColumn('other_credit.position', '!=', 'artist_credit_name.position');
                })
                ->addSelect('artist_alias.name as alias_name');
            $recordings = $this->matchingRecords($aliases->cursor(), $artist, 'alias_name');
        }
        if (count($recordings) !== 1) {
            $ambiguous = count($recordings) > 1;

            return null;
        }
        $recording = reset($recordings);
        $artistMbids = $db->table('artist_credit_name')
            ->join('artist', 'artist.id', '=', 'artist_credit_name.artist')
            ->where('artist_credit_name.artist_credit', $recording['artist_credit_id'])
            ->orderBy('artist_credit_name.position')
            ->pluck('artist.gid')->map(fn ($gid) => (string) $gid)->values()->all();
        $artistMbids = array_values($artistMbids);

        $releaseMbid = null;
        if ($album !== '') {
            $releases = $db->table('track')
                ->join('medium', 'track.medium', '=', 'medium.id')
                ->join('release', 'medium.release', '=', 'release.id')
                ->where('track.recording', $recording['id'])
                ->where('release.name', $album)
                ->distinct()->limit(2)->pluck('release.gid');
            if ($releases->count() === 1) {
                $releaseMbid = (string) $releases->first();
            }
        }

        return [
            'recording_mbid' => $recording['gid'],
            'artist_name' => $recording['artist_name'],
            'artist_mbids' => $artistMbids,
            'release_mbid' => $releaseMbid,
        ];
    }

    /**
     * @return array{recording_mbid: string, artist_name: string, artist_mbids: list<string>, release_mbid: ?string}|null
     */
    private function matchCanonical(string $track, string $artist, string $album, bool &$ambiguous): ?array
    {
        $query = DB::connection('musicbrainz')->table('canonical_recordings')
            ->where('recording_name', $track);
        if ($album !== '') {
            $query->where('release_name', $album);
        }
        $recordings = [];
        $credits = [];
        $releases = [];
        foreach ($query->cursor() as $row) {
            if ($artist !== '' && ! $this->sameArtist((string) $row->artist_credit_name, $artist)) {
                continue;
            }
            $recordings[$row->recording_mbid] = true;
            $credits[json_encode([$row->artist_credit_name, $row->artist_mbids], JSON_THROW_ON_ERROR)] = $row;
            if ($album !== '') {
                $releases[$row->release_mbid] = true;
            }
            if (count($recordings) > 1 || count($credits) > 1) {
                $ambiguous = true;

                return null;
            }
        }
        if (count($recordings) !== 1 || count($credits) !== 1) {
            return null;
        }
        $row = reset($credits);

        return [
            'recording_mbid' => (string) array_key_first($recordings),
            'artist_name' => (string) $row->artist_credit_name,
            'artist_mbids' => $row->artist_mbids === '' ? [] : explode(',', $row->artist_mbids),
            'release_mbid' => count($releases) === 1 ? (string) array_key_first($releases) : null,
        ];
    }

    /**
     * @param  iterable<\stdClass>  $rows
     * @return array<string, array{id: int, gid: string, artist_credit_id: int, artist_name: string}>
     */
    private function matchingRecords(iterable $rows, string $artist, string $field): array
    {
        $matches = [];
        foreach ($rows as $row) {
            if ($artist !== '' && ! $this->sameArtist((string) $row->{$field}, $artist)) {
                continue;
            }
            $matches[(string) $row->gid] = [
                'id' => (int) $row->id,
                'gid' => (string) $row->gid,
                'artist_credit_id' => (int) $row->artist_credit_id,
                'artist_name' => (string) $row->artist_name,
            ];
            if (count($matches) > 1) {
                break;
            }
        }

        return $matches;
    }

    private function sameArtist(string $left, string $right): bool
    {
        return mb_convert_case($left, MB_CASE_FOLD, 'UTF-8') === mb_convert_case($right, MB_CASE_FOLD, 'UTF-8');
    }

    /**
     * @param  list<string>  $mbids
     * @return array<string, array{name: string, source: string}>
     */
    public function artistNames(array $mbids, string $recording, string $track): array
    {
        if (! config('musicbrainz.enabled') || $mbids === []) {
            return [];
        }
        $db = DB::connection('musicbrainz');
        if (config('musicbrainz.format') === 'canonical' && $db->getDriverName() === 'sqlite') {
            if (count($mbids) !== 1) {
                return [];
            }
            $names = $db->table('canonical_recordings')->where('recording_name', $track)
                ->where('recording_mbid', $recording)->where('artist_mbids', $mbids[0])
                ->distinct()->limit(2)->pluck('artist_credit_name');

            return $names->count() === 1 ? [$mbids[0] => ['name' => (string) $names->first(), 'source' => 'canonical_credit']] : [];
        }

        $names = [];
        foreach ($db->table('artist')->whereIn('gid', $mbids)->get(['name', 'gid']) as $row) {
            $names[(string) $row->gid] = ['name' => (string) $row->name, 'source' => 'musicbrainz'];
        }

        return $names;
    }

    public function releaseForRecording(string $recording, ?string $album, string $track): ?string
    {
        if (! config('musicbrainz.enabled') || $album === null || $album === '') {
            return null;
        }
        $db = DB::connection('musicbrainz');
        if (config('musicbrainz.format') === 'canonical' && $db->getDriverName() === 'sqlite') {
            $releases = $db->table('canonical_recordings')->where('recording_name', $track)->where('recording_mbid', $recording)
                ->where('release_name', $album)->distinct()->limit(2)->pluck('release_mbid');
        } else {
            $releases = $db->table('recording')->join('track', 'track.recording', '=', 'recording.id')
                ->join('medium', 'medium.id', '=', 'track.medium')
                ->join('release', 'release.id', '=', 'medium.release')
                ->where('recording.gid', $recording)->where('release.name', $album)
                ->distinct()->limit(2)->pluck('release.gid');
        }

        return $releases->count() === 1 ? (string) $releases->first() : null;
    }

    /** @return list<string> */
    public function isrcs(string $recording): array
    {
        if (! config('musicbrainz.enabled')) {
            return [];
        }
        $db = DB::connection('musicbrainz');
        if (config('musicbrainz.format') === 'canonical' && $db->getDriverName() === 'sqlite') {
            return [];
        }
        $codes = $db->table('isrc')->join('recording', 'recording.id', '=', 'isrc.recording')
            ->where('recording.gid', $recording)->distinct()->orderBy('isrc.isrc')->pluck('isrc.isrc');
        $result = [];
        foreach ($codes as $code) {
            $isrc = Isrc::normalize($code);
            if ($isrc === null) {
                throw new \UnexpectedValueException('Invalid ISRC in the local MusicBrainz database.');
            }
            $result[] = $isrc;
        }

        return array_values(array_unique($result));
    }
}
