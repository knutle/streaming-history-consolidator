<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class MusicBrainzMatcher
{
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
