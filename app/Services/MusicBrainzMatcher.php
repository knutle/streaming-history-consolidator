<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class MusicBrainzMatcher
{
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
    public function match(string $track, string $artist, string $album): ?array
    {
        if (! config('musicbrainz.enabled') || ($artist === '' && $album === '')) {
            return null;
        }

        $db = DB::connection('musicbrainz');
        if (config('musicbrainz.format') === 'canonical' && $db->getDriverName() === 'sqlite') {
            return $this->matchCanonical($track, $artist, $album);
        }

        $query = $db->table('recording')
            ->join('artist_credit', 'recording.artist_credit', '=', 'artist_credit.id')
            ->where('recording.name', $track)
            ->select('recording.id', 'recording.gid', 'artist_credit.id as artist_credit_id', 'artist_credit.name as artist_name');

        if ($artist !== '') {
            $query->where('artist_credit.name', $artist);
        }
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

        $recordings = $query->limit(2)->get();
        if ($recordings->count() !== 1) {
            return null;
        }
        $recording = $recordings->first();
        $artistMbids = $db->table('artist_credit_name')
            ->join('artist', 'artist.id', '=', 'artist_credit_name.artist')
            ->where('artist_credit_name.artist_credit', $recording->artist_credit_id)
            ->orderBy('artist_credit_name.position')
            ->pluck('artist.gid')->map(fn ($gid) => (string) $gid)->values()->all();
        $artistMbids = array_values($artistMbids);

        $releaseMbid = null;
        if ($album !== '') {
            $releases = $db->table('track')
                ->join('medium', 'track.medium', '=', 'medium.id')
                ->join('release', 'medium.release', '=', 'release.id')
                ->where('track.recording', $recording->id)
                ->where('release.name', $album)
                ->distinct()->limit(2)->pluck('release.gid');
            if ($releases->count() === 1) {
                $releaseMbid = (string) $releases->first();
            }
        }

        return [
            'recording_mbid' => (string) $recording->gid,
            'artist_name' => (string) $recording->artist_name,
            'artist_mbids' => $artistMbids,
            'release_mbid' => $releaseMbid,
        ];
    }

    /**
     * @return array{recording_mbid: string, artist_name: string, artist_mbids: list<string>, release_mbid: ?string}|null
     */
    private function matchCanonical(string $track, string $artist, string $album): ?array
    {
        $query = DB::connection('musicbrainz')->table('canonical_recordings')
            ->where('recording_name', $track);
        if ($artist !== '') {
            $query->where('artist_credit_name', $artist);
        }
        if ($album !== '') {
            $query->where('release_name', $album);
        }
        $recordings = (clone $query)->distinct()->limit(2)->pluck('recording_mbid');
        if ($recordings->count() !== 1) {
            return null;
        }
        $query->where('recording_mbid', $recordings->first());
        $credits = (clone $query)->distinct()->limit(2)->get(['artist_credit_name', 'artist_mbids']);
        if ($credits->count() !== 1) {
            return null;
        }
        $row = $credits->first();
        $releases = $album === '' ? collect() : $query->distinct()->limit(2)->pluck('release_mbid');

        return [
            'recording_mbid' => (string) $recordings->first(),
            'artist_name' => (string) $row->artist_credit_name,
            'artist_mbids' => $row->artist_mbids === '' ? [] : explode(',', $row->artist_mbids),
            'release_mbid' => $releases->count() === 1 ? (string) $releases->first() : null,
        ];
    }
}
