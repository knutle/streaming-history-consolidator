<?php

namespace App\Services;

use App\Models\Listen;
use Illuminate\Support\Facades\DB;

class MatchedMusicMetadata
{
    /** @var array<string, array<string, array{name: string, source: string}>> */
    private array $artists = [];

    /** @var array<string, ?string> */
    private array $releases = [];

    public function __construct(private MusicBrainzMatcher $matcher) {}

    public function sync(Listen $listen): void
    {
        if ($listen->recording_mbid === null) {
            return;
        }
        $metadata = $listen->track_metadata;
        $values = $metadata['additional_info']['artist_mbids'] ?? [];
        if (! is_array($values) || ! array_is_list($values)) {
            throw new \UnexpectedValueException('Invalid MusicBrainz artist identifiers in listen metadata.');
        }
        $mbids = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new \UnexpectedValueException('Invalid MusicBrainz artist identifier in listen metadata.');
            }
            $mbids[] = $value;
        }
        $artistKey = json_encode([$mbids, $listen->recording_mbid, $listen->track_name], JSON_THROW_ON_ERROR);
        if (! array_key_exists($artistKey, $this->artists)) {
            $this->artists[$artistKey] = $this->matcher->artistNames($mbids, $listen->recording_mbid, $listen->track_name);
        }
        foreach ($mbids as $mbid) {
            $artist = $this->artists[$artistKey][$mbid] ?? null;
            DB::table('music_artists')->upsert([[
                'mbid' => $mbid, 'name' => $artist['name'] ?? null, 'name_source' => $artist['source'] ?? null,
            ]], ['mbid'], ['mbid']);
            if ($artist !== null) {
                DB::table('music_artists')->where('mbid', $mbid)
                    ->when($artist['source'] !== 'musicbrainz', fn ($query) => $query->whereNull('name'))
                    ->update(['name' => $artist['name'], 'name_source' => $artist['source']]);
            }
            $id = DB::table('music_artists')->where('mbid', $mbid)->value('id');
            DB::table('listen_artists')->upsert(
                [['listen_id' => $listen->id, 'music_artist_id' => $id]],
                ['listen_id', 'music_artist_id'],
                ['listen_id'],
            );
        }
        $release = $metadata['additional_info']['release_mbid'] ?? null;
        if ($release === null) {
            $key = json_encode([$listen->recording_mbid, $listen->release_name, $listen->track_name], JSON_THROW_ON_ERROR);
            if (! array_key_exists($key, $this->releases)) {
                $this->releases[$key] = $this->matcher->releaseForRecording($listen->recording_mbid, $listen->release_name, $listen->track_name);
            }
            $release = $this->releases[$key];
            if ($release !== null) {
                $metadata['additional_info']['release_mbid'] = $release;
                $listen->update(['track_metadata' => $metadata]);
            }
        }
        if (is_string($release) && $listen->release_name !== null && $listen->release_name !== '') {
            DB::table('music_releases')->upsert(
                [['mbid' => $release, 'name' => $listen->release_name]],
                ['mbid'],
                ['mbid'],
            );
            $id = DB::table('music_releases')->where('mbid', $release)->value('id');
            DB::table('listen_releases')->upsert(
                [['listen_id' => $listen->id, 'music_release_id' => $id]],
                ['listen_id', 'music_release_id'],
                ['listen_id'],
            );
        }
    }
}
