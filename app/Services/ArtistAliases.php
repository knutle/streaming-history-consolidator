<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class ArtistAliases
{
    /** @var array<string, ?string> */
    private array $artists = [];

    /** @var array<string, list<string>> */
    private array $names = [];

    public function __construct(private MusicBrainzMatcher $matcher) {}

    public static function key(string $name): string
    {
        return hash('sha256', mb_convert_case(trim($name), MB_CASE_FOLD, 'UTF-8'));
    }

    /** @return array{recording_mbid: string, artist_name: string, artist_mbids: list<string>, release_mbid: ?string}|null */
    public function match(string $track, string $artist, string $album, ?string $confirmedMbid = null, ?bool &$ambiguous = null): ?array
    {
        $ambiguous = false;
        if (! config('musicbrainz.enabled')) {
            return null;
        }
        $direct = $this->matcher->match($track, $artist, $album, $ambiguous);
        if ($ambiguous) {
            return null;
        }
        if ($direct !== null) {
            return $confirmedMbid === null || $direct['artist_mbids'] === [$confirmedMbid] ? $direct : null;
        }
        if (trim($artist) === '') {
            return null;
        }
        if ($confirmedMbid === null) {
            $key = self::key($artist);
            if (! array_key_exists($key, $this->artists)) {
                $ids = DB::table('music_artist_aliases')->join('music_artists', 'music_artists.id', '=', 'music_artist_aliases.music_artist_id')
                    ->where('name_key', $key)->distinct()->limit(2)->pluck('music_artists.mbid');
                $this->artists[$key] = $ids->count() === 1 ? $ids->first() : null;
            }
            $confirmedMbid = $this->artists[$key];
            if ($confirmedMbid === null) {
                return null;
            }
        }
        if (! array_key_exists($confirmedMbid, $this->names)) {
            $names = DB::table('music_artist_aliases')->join('music_artists', 'music_artists.id', '=', 'music_artist_aliases.music_artist_id')
                ->where('music_artists.mbid', $confirmedMbid)->pluck('music_artist_aliases.name');
            $canonicalName = DB::table('music_artists')->where('mbid', $confirmedMbid)->value('name');
            if (is_string($canonicalName) && $canonicalName !== '') {
                $names->push($canonicalName);
            }
            $validNames = [];
            foreach ($names as $name) {
                if (! is_string($name) || $name === '') {
                    throw new UnexpectedValueException('Stored artist alias must be a non-empty string.');
                }
                $validNames[] = $name;
            }
            $this->names[$confirmedMbid] = array_values(array_unique($validNames));
        }
        $matches = [];
        foreach ($this->names[$confirmedMbid] as $name) {
            $match = $this->matcher->match($track, $name, $album, $ambiguous);
            if ($ambiguous) {
                return null;
            }
            if ($match !== null && $match['artist_mbids'] === [$confirmedMbid]) {
                $matches[$match['recording_mbid']] = $match;
                if (count($matches) > 1) {
                    $ambiguous = true;

                    return null;
                }
            }
        }

        return count($matches) === 1 ? reset($matches) : null;
    }
}
