<?php

namespace App\Services;

use App\Models\Listen;
use App\Models\User;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StreamingHistoryImporter
{
    public function __construct(
        private StreamingHistoryReader $reader,
        private ManualListenMatcher $manualMatcher,
        private AppleMusicArchiveReader $archiveReader,
        private CatalogMatcher $catalogMatcher,
        private MatchedMusicMetadata $matchedMetadata,
        private CatalogRecordingGroups $catalogGroups,
        private ArtistAliases $artistAliases,
        private ManualMusicEntityMatcher $manualEntities,
    ) {}

    /**
     * @param  array<string, string|null>  $spotifyMetadata
     * @param  array<string, array<string, bool>>  $spotifyPresence
     * @param  (\Closure(int): void)|null  $progress
     * @param  (\Closure(): void)|null  $checkAbort
     * @return array{imported: int, duplicates: int, matched: int}
     */
    public function import(User $user, string $source, string $path, array $spotifyMetadata = [], array $spotifyPresence = [], ?\Closure $progress = null, ?\Closure $checkAbort = null): array
    {
        $archive = $source === 'apple_zip';
        $source = $archive ? 'apple' : $source;

        return DB::transaction(function () use ($user, $source, $path, $archive, $spotifyMetadata, $spotifyPresence, $progress, $checkAbort) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $counts = ['imported' => 0, 'duplicates' => 0, 'matched' => 0];
            $matches = [];
            $processed = 0;
            $syncCatalogEvidence = false;

            $entries = $this->entries($path, $source, $archive, $checkAbort);
            foreach ($entries as $entry) {
                if ($progress !== null && $processed > 0 && $processed % 100 === 0) {
                    $progress($processed);
                }
                $processed++;
                if ($source === 'spotify' && $entry['uri'] === '') {
                    $key = StreamingHistoryUpload::spotifyKey($entry['track'], $entry['artist'], $entry['album']);
                    $entry['identity_uri'] = $entry['uri'];
                    $entry['uri'] = $spotifyMetadata[$key] ?? '';
                }
                if ($source === 'spotify' && $entry['uri'] !== '') {
                    $id = $this->catalogMatcher->identifier('spotify', $entry['uri']);
                    if ($id !== null && isset($spotifyPresence[$id])) {
                        $entry['spotify'] = ($entry['spotify'] ?? []) + $spotifyPresence[$id];
                    }
                }
                $appleMusic = $entry['apple_music'] ?? ($source === 'apple' ? ($entry['apple_event'] ?? null) : null);
                if ($archive && $appleMusic === null) {
                    throw new \LogicException('The Apple archive reader returned an invalid entry.');
                }
                $identityValues = $source === 'apple' && $entry['uri'] !== ''
                    ? ['event_id', $entry['uri']]
                    : [
                        $entry['timestamp'], $entry['played_ms'], $entry['start_time'],
                        ($entry['identity_uri'] ?? $entry['uri']) !== '' ? ($entry['identity_uri'] ?? $entry['uri']) : [$entry['track'], $entry['identity_artist'] ?? $entry['artist'], $entry['album']],
                    ];
                $fingerprint = hash('sha256', json_encode($identityValues, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $identity = ['user_id' => $user->id, 'source' => $source, 'fingerprint' => $fingerprint];
                $nativeId = $source === 'spotify'
                    ? $this->catalogMatcher->identifier('spotify', $entry['uri'])
                    : ($appleMusic['catalog_id'] ?? null);
                if (! is_string($nativeId)) {
                    $nativeId = null;
                }
                $existing = Listen::query()->where($identity)->first();
                if ($existing !== null) {
                    if ($existing->track_name === $entry['track'] && ($existing->release_name ?? '') === $entry['album']) {
                        $group = $this->catalogGroups->remembered($user->id, $source, $nativeId);
                        if ($group !== null && $existing->recording_mbid !== null && $existing->recording_mbid !== $group['recording_mbid']) {
                            throw ValidationException::withMessages([
                                'file' => 'An existing listen conflicts with a linked catalog recording. Unlink or resolve the conflicting recording; nothing was imported.',
                            ]);
                        }
                        if ($group !== null && $existing->recording_mbid === null) {
                            $manual = $this->manualMatcher->remembered($user, $entry['track'], $entry['artist']);
                            if ($manual !== null && $manual['recording_mbid'] !== $group['recording_mbid']) {
                                throw ValidationException::withMessages([
                                    'file' => 'A saved recording match conflicts with a linked catalog recording. Resolve the saved match or unlink the catalog item; nothing was imported.',
                                ]);
                            }
                            $metadata = $existing->track_metadata;
                            $metadata['additional_info']['recording_mbid'] = $group['recording_mbid'];
                            if ($group['artist_mbids'] !== []) {
                                $metadata['additional_info']['artist_mbids'] = $group['artist_mbids'];
                            }
                            $existing->update(['recording_mbid' => $group['recording_mbid'], 'track_metadata' => $metadata]);
                        }
                        $this->enrichExisting($existing, $entry, $appleMusic, $user, $matches);
                    }
                    if ($existing->track_name === $entry['track'] && ($existing->release_name ?? '') === $entry['album']) {
                        $catalogs = $this->catalogMatcher->imported($user, $source, $entry['uri'], $appleMusic ?? [],
                            $entry['track'], $existing->artist_name, $entry['album'], $entry['duration_ms'], $entry['isrc'] ?? null);
                        $syncCatalogEvidence = $syncCatalogEvidence || count($catalogs) > 1;
                        if ($catalogs !== []) {
                            $metadata = $existing->track_metadata;
                            if (($metadata['additional_info']['catalogs'][$source]['origin'] ?? null) === 'manual'
                                && ! isset($metadata['additional_info']['native_catalog'])
                                && isset($catalogs[$source])) {
                                $metadata['additional_info']['native_catalog'] = $catalogs[$source];
                            }
                            $metadata['additional_info']['catalogs'] = $this->catalogMatcher->forListen($existing) + $catalogs;
                            $existing->update(['track_metadata' => $metadata]);
                        }
                    }
                    $this->matchedMetadata->sync($existing);
                    $counts['duplicates']++;

                    continue;
                }

                $key = json_encode([$entry['track'], $entry['artist'], $entry['album'], $source, $nativeId], JSON_THROW_ON_ERROR);
                if (! array_key_exists($key, $matches)) {
                    try {
                        $manual = $this->manualMatcher->remembered($user, $entry['track'], $entry['artist']);
                        $group = $this->catalogGroups->remembered($user->id, $source, $nativeId);
                        if ($manual !== null && $group !== null && $manual['recording_mbid'] !== $group['recording_mbid']) {
                            throw ValidationException::withMessages([
                                'file' => 'A saved recording match conflicts with a linked catalog recording. Resolve the saved match or unlink the catalog item; nothing was imported.',
                            ]);
                        }
                        $matches[$key] = $group ?? $manual
                            ?? $this->artistAliases->match($entry['track'], $entry['artist'], $entry['album'],
                                $this->manualEntities->artistMbid($user, $entry['artist']));
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
                if (($entry['isrc'] ?? null) !== null) {
                    $additionalInfo['isrcs'] = [$entry['isrc']];
                }
                if ($entry['duration_ms'] !== null && $entry['duration_ms'] > 0) {
                    $additionalInfo['duration_ms'] = $entry['duration_ms'];
                }
                if ($appleMusic !== null && $appleMusic !== []) {
                    $additionalInfo['apple_music'] = $appleMusic;
                }
                if (($entry['spotify'] ?? []) !== []) {
                    $additionalInfo['spotify'] = $entry['spotify'];
                }
                $catalogs = $this->catalogMatcher->imported($user, $source, $entry['uri'], $appleMusic ?? [],
                    $entry['track'], $artist, $entry['album'], $entry['duration_ms'], $entry['isrc'] ?? null);
                $syncCatalogEvidence = $syncCatalogEvidence || count($catalogs) > 1;
                if ($catalogs !== []) {
                    $additionalInfo['catalogs'] = $catalogs;
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
                    $this->matchedMetadata->sync($listen);
                    $counts['imported']++;
                    $counts['matched'] += (int) ($match !== null);
                } else {
                    $counts['duplicates']++;
                }
            }

            if ($syncCatalogEvidence) {
                $this->catalogGroups->syncEvidence($user, $checkAbort);
            }
            if ($progress !== null && $processed > 0) {
                $progress($processed);
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

    /**
     * @return Generator<int, array{timestamp: string, track: string, artist: string, identity_artist?: string, album: string, played_ms: int, duration_ms: ?int, uri: string, start_time: bool, isrc: ?string, apple_event?: array<string, mixed>, apple_music?: array<string, mixed>, spotify?: array<string, string|bool>}>
     */
    private function entries(string $path, string $source, bool $archive, ?\Closure $checkAbort): Generator
    {
        if ($archive) {
            yield from $this->archiveReader->read($path, $checkAbort);
        } else {
            yield from $this->reader->read($path, $source);
        }
    }

    /**
     * @param  array{track: string, artist: string, album: string, isrc: ?string, spotify?: array<string, string|bool>}  $entry
     * @param  array<string, mixed>|null  $appleMusic
     * @param  array<string, mixed>  $matches
     */
    private function enrichExisting(Listen $listen, array $entry, ?array $appleMusic, User $user, array &$matches): void
    {
        $metadata = $listen->track_metadata;
        if (($entry['isrc'] ?? null) !== null) {
            $metadata['additional_info']['isrcs'] = array_values(array_unique([
                ...($metadata['additional_info']['isrcs'] ?? []), $entry['isrc'],
            ]));
        }
        if ($appleMusic !== null) {
            $existing = $metadata['additional_info']['apple_music'] ?? [];
            if (! is_array($existing)) {
                throw ValidationException::withMessages(['file' => 'An existing listen has invalid Apple Music metadata.']);
            }
            $metadata['additional_info']['apple_music'] = $existing + $appleMusic;
        }
        if (($entry['spotify'] ?? []) !== []) {
            $existing = $metadata['additional_info']['spotify'] ?? [];
            if (! is_array($existing)) {
                throw ValidationException::withMessages(['file' => 'An existing listen has invalid Spotify metadata.']);
            }
            $metadata['additional_info']['spotify'] = $existing + $entry['spotify'];
        }
        $artist = $listen->artist_name;
        if ($artist === 'Unknown artist' && $entry['artist'] !== '' && $listen->recording_mbid === null) {
            $artist = $entry['artist'];
            $metadata['artist_name'] = $artist;
        }
        $match = null;
        if ($listen->recording_mbid === null && $artist !== 'Unknown artist') {
            $key = json_encode([$entry['track'], $artist, $entry['album']], JSON_THROW_ON_ERROR);
            if (! array_key_exists($key, $matches)) {
                try {
                    $matches[$key] = $this->manualMatcher->remembered($user, $entry['track'], $artist)
                        ?? $this->artistAliases->match($entry['track'], $artist, $entry['album'], $this->manualEntities->artistMbid($user, $artist));
                } catch (QueryException $exception) {
                    report($exception);

                    throw ValidationException::withMessages([
                        'file' => 'The local MusicBrainz database could not be queried. Check its connection and schema; nothing was imported.',
                    ]);
                }
            }
            $match = $matches[$key];
            if ($match !== null) {
                $metadata['additional_info']['recording_mbid'] = $match['recording_mbid'];
                if ($match['artist_mbids'] !== []) {
                    $metadata['additional_info']['artist_mbids'] = $match['artist_mbids'];
                }
                if ($match['release_mbid'] !== null) {
                    $metadata['additional_info']['release_mbid'] = $match['release_mbid'];
                }
            }
        }
        $listen->update([
            'artist_name' => $artist,
            'recording_mbid' => $match['recording_mbid'] ?? $listen->recording_mbid,
            'track_metadata' => $metadata,
        ]);
    }
}
