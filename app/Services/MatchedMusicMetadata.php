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

    /** @var array<string, list<string>> */
    private array $isrcs = [];

    public function __construct(private MusicBrainzMatcher $matcher, private ManualMusicEntityMatcher $manualEntities) {}

    /** @param array<string, string> $names */
    public function rememberArtistNames(array $names): void
    {
        foreach ($names as $mbid => $name) {
            DB::table('music_artists')->upsert(
                [['mbid' => $mbid, 'name' => $name, 'name_source' => 'musicbrainz']],
                ['mbid'],
                ['name', 'name_source'],
            );
        }
    }

    /** @param array{recording_mbid: string, artist_mbids: list<string>} $match */
    public function replaceRecording(Listen $listen, array $match, ?string $recordingName = null): void
    {
        $metadata = $listen->track_metadata;
        unset($metadata['additional_info']['release_mbid']);
        $metadata['additional_info']['recording_mbid'] = $match['recording_mbid'];
        $metadata['additional_info']['artist_mbids'] = $match['artist_mbids'];
        DB::table('listen_artists')->where('listen_id', $listen->id)->delete();
        DB::table('listen_releases')->where('listen_id', $listen->id)->delete();
        $listen->update(['recording_mbid' => $match['recording_mbid'], 'track_metadata' => $metadata]);
        $this->sync($listen, $recordingName);
    }

    public function sync(Listen $listen, ?string $recordingName = null): void
    {
        if ($listen->recording_mbid === null) {
            $this->manualEntities->apply($listen);

            return;
        }
        $metadata = $listen->track_metadata;
        if (! array_key_exists($listen->recording_mbid, $this->isrcs)) {
            $this->isrcs[$listen->recording_mbid] = $this->matcher->isrcs($listen->recording_mbid);
        }
        $codes = array_values(array_unique([
            ...($metadata['additional_info']['isrcs'] ?? []), ...$this->isrcs[$listen->recording_mbid],
        ]));
        if ($codes !== [] && $codes !== ($metadata['additional_info']['isrcs'] ?? [])) {
            $metadata['additional_info']['isrcs'] = $codes;
            $listen->update(['track_metadata' => $metadata]);
        }
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
        $track = $recordingName ?? $listen->track_name;
        $artistKey = json_encode([$mbids, $listen->recording_mbid, $track], JSON_THROW_ON_ERROR);
        if (! array_key_exists($artistKey, $this->artists)) {
            $this->artists[$artistKey] = $this->matcher->artistNames($mbids, $listen->recording_mbid, $track);
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
        $this->manualEntities->apply($listen);
    }
}
