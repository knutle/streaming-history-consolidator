<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use JsonException;
use ZipArchive;

class StreamingHistoryUpload
{
    private const MAX_BYTES = 1073741824;

    private const MAX_METADATA_BYTES = 8388608;

    private const MAX_TRACKS = 100000;

    public function __construct(
        private StreamingHistoryImporter $importer,
        private StreamingHistoryReader $reader,
        private CatalogMatcher $catalogMatcher,
    ) {}

    /** @param list<UploadedFile> $files
     * @return array{imported: int, duplicates: int, matched: int, errors: list<array{file: string, message: string}>}
     */
    public function import(User $user, array $files): array
    {
        $result = ['imported' => 0, 'duplicates' => 0, 'matched' => 0, 'errors' => []];
        $inputs = [];
        $temporaries = [];
        $extracted = 0;
        foreach ($files as $file) {
            $name = $file->getClientOriginalName();
            try {
                if (strtolower($file->getClientOriginalExtension()) === 'zip') {
                    $this->unpack($file->getRealPath(), $name, $inputs, $temporaries, $extracted, $result['errors']);
                } else {
                    $inputs[] = ['name' => $name, 'path' => $file->getRealPath()];
                }
            } catch (ValidationException $exception) {
                $this->addError($result['errors'], $name, $exception);
            }
        }

        $history = [];
        $metadata = [];
        $presence = [];
        $hasMetadata = false;
        foreach ($inputs as $input) {
            try {
                $type = $input['type'] ?? $this->detect($input['path'], $input['name']);
                if ($type === 'library' || $type === 'playlist') {
                    $this->metadata($input['path'], $type, $metadata, $presence);
                    $hasMetadata = true;
                } else {
                    $history[] = $input + ['type' => $type];
                }
            } catch (ValidationException $exception) {
                $this->addError($result['errors'], $input['name'], $exception);
            }
        }

        foreach ($history as $input) {
            try {
                $counts = $this->importer->import($user, $input['type'], $input['path'], $metadata, $presence);
                foreach ($counts as $key => $count) {
                    $result[$key] += $count;
                }
            } catch (ValidationException $exception) {
                $this->addError($result['errors'], $input['name'], $exception);
            }
        }
        if ($history === [] && ($hasMetadata || $result['errors'] === [])) {
            $result['errors'][] = ['file' => 'Upload', 'message' => 'No supported listening history was found. Metadata files must be uploaded with history.'];
        }

        return $result;
    }

    public static function spotifyKey(string $track, string $artist, string $album): string
    {
        return hash('sha256', json_encode(array_map(
            fn ($value) => mb_strtolower(trim($value)),
            [$track, $artist, $album],
        ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  list<array{name: string, path: string, type?: string}>  $inputs
     * @param  list<resource>  $temporaries
     * @param  list<array{file: string, message: string}>  $errors
     */
    private function unpack(string $path, string $name, array &$inputs, array &$temporaries, int &$extracted, array &$errors): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->invalid('ZIP imports require the PHP zip extension.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            $this->invalid('The file is not a valid ZIP archive.');
        }
        try {
            if ($zip->numFiles > 5000) {
                $this->invalid('The ZIP archive contains too many files.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if ($entry !== false && in_array(strtolower(basename($entry)), ['apple_media_services.zip', 'apple music play activity.csv'], true)) {
                    $inputs[] = ['name' => $name, 'path' => $path, 'type' => 'apple_zip'];

                    return;
                }
            }

            $found = false;
            $initialErrors = count($errors);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if ($entry === false || str_ends_with($entry, '/')) {
                    continue;
                }
                $base = basename($entry);
                if (preg_match('/^read.?me.*\\.pdf$/i', $base)) {
                    continue;
                }
                $label = $name.' / '.$entry;
                if (! preg_match('/^(Streaming_History_Audio.*|YourLibrary|Playlist[0-9]+)\\.json$/i', $base)) {
                    $errors[] = ['file' => $label, 'message' => 'Unsupported archive file; it was not imported.'];

                    continue;
                }
                $found = true;
                try {
                    if (count($temporaries) >= 100) {
                        $this->invalid('The upload contains too many supported ZIP entries (maximum 100).');
                    }
                    $stat = $zip->statIndex($i);
                    if ($stat === false || $stat['size'] > self::MAX_BYTES || $extracted + $stat['size'] > self::MAX_BYTES
                        || ($stat['size'] > 0 && $stat['size'] > max(1, $stat['comp_size']) * 1000)
                        || $stat['encryption_method'] !== 0) {
                        $this->invalid('The ZIP entry is encrypted or exceeds the safe decompression limit.');
                    }
                    $stream = $zip->getStreamIndex($i);
                    if ($stream === false) {
                        $this->invalid('The ZIP entry could not be read.');
                    }
                    $temporary = tmpfile();
                    if ($temporary === false) {
                        fclose($stream);
                        $this->invalid('Could not create a temporary file for the ZIP entry.');
                    }
                    $copied = stream_copy_to_stream($stream, $temporary, self::MAX_BYTES + 1);
                    fclose($stream);
                    if ($copied === false || $copied !== $stat['size'] || ! fflush($temporary)) {
                        fclose($temporary);
                        $this->invalid('The ZIP entry is incomplete or could not be read.');
                    }
                    $extracted += $copied;
                    $temporaryPath = stream_get_meta_data($temporary)['uri'] ?? null;
                    if (! is_string($temporaryPath)) {
                        fclose($temporary);
                        $this->invalid('Could not access a temporary ZIP entry.');
                    }
                    $temporaries[] = $temporary;
                    $inputs[] = ['name' => $label, 'path' => $temporaryPath];
                } catch (ValidationException $exception) {
                    $this->addError($errors, $label, $exception);
                }
            }
            if (! $found && count($errors) === $initialErrors) {
                $this->invalid('No supported Spotify or Apple Music files were found in the ZIP archive.');
            }
        } finally {
            $zip->close();
        }
    }

    private function detect(string $path, string $name): string
    {
        $base = basename($name);
        $extension = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if ($extension === 'csv') {
            return 'apple';
        }
        if ($extension !== 'json') {
            $this->invalid('Unsupported file type. Upload a supported JSON, CSV, or ZIP file.');
        }
        if (strcasecmp($base, 'YourLibrary.json') === 0) {
            return 'library';
        }
        if (preg_match('/^Playlist[0-9]+\\.json$/i', $base)) {
            return 'playlist';
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            $this->invalid('The uploaded file could not be read.');
        }
        try {
            $row = null;
            foreach ($this->reader->jsonObjects($stream) as $first) {
                $row = $first;
                break;
            }
            if ($row === null && preg_match('/^Streaming_History_Audio.*\\.json$/i', $base)) {
                return 'spotify';
            }
            if ($row !== null && array_key_exists('ts', $row) && array_key_exists('ms_played', $row)
                && array_key_exists('master_metadata_track_name', $row)) {
                return 'spotify';
            }
            if ($row !== null && isset($row['endTime'], $row['msPlayed'])) {
                $this->invalid('Spotify account streaming history has minute-precision timestamps and cannot be safely deduplicated against extended history. Upload extended audio history instead.');
            }
            $this->invalid('Could not detect a supported streaming history structure.');
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  array<string, string|null>  $index
     * @param  array<string, array<string, bool>>  $presence
     */
    private function metadata(string $path, string $type, array &$index, array &$presence): void
    {
        $size = filesize($path);
        if ($size === false || $size > self::MAX_METADATA_BYTES) {
            $this->invalid('The Spotify metadata file exceeds the safe size limit.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->invalid('The Spotify metadata file could not be read.');
        }
        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalid('The Spotify metadata file contains invalid JSON.');
        }
        $rows = [];
        if ($type === 'library') {
            if (! is_array($data) || ! isset($data['tracks']) || ! is_array($data['tracks']) || ! array_is_list($data['tracks'])) {
                $this->invalid('The Spotify library must contain a tracks array.');
            }
            $rows = $data['tracks'];
        } else {
            if (! is_array($data) || ! isset($data['playlists']) || ! is_array($data['playlists']) || ! array_is_list($data['playlists'])) {
                $this->invalid('The Spotify playlist file must contain a playlists array.');
            }
            foreach ($data['playlists'] as $playlist) {
                if (! is_array($playlist) || ! isset($playlist['items']) || ! is_array($playlist['items']) || ! array_is_list($playlist['items'])) {
                    $this->invalid('A Spotify playlist has an invalid items array.');
                }
                foreach ($playlist['items'] as $item) {
                    if (! is_array($item)) {
                        $this->invalid('A Spotify playlist item is invalid.');
                    }
                    if (isset($item['track'])) {
                        $rows[] = $item['track'];
                    }
                    if (count($rows) > self::MAX_TRACKS) {
                        $this->invalid('The Spotify metadata file contains too many tracks.');
                    }
                }
            }
        }
        if (count($rows) > self::MAX_TRACKS) {
            $this->invalid('The Spotify metadata file contains too many tracks.');
        }
        $additions = [];
        $presenceAdditions = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                $this->invalid('A Spotify metadata track is invalid.');
            }
            $fields = $type === 'library'
                ? ['track', 'artist', 'album', 'uri']
                : ['trackName', 'artistName', 'albumName', 'trackUri'];
            foreach ($fields as $field) {
                if (! array_key_exists($field, $row) || ($row[$field] !== null && ! is_string($row[$field]))) {
                    $this->invalid('A Spotify metadata track has an invalid structure.');
                }
            }
            [$track, $artist, $album, $uri] = array_map(fn ($field) => $row[$field], $fields);
            $id = is_string($uri) ? $this->catalogMatcher->identifier('spotify', $uri) : null;
            if ($id !== null) {
                $presenceAdditions[$id][$type === 'library' ? 'saved_in_library_export' : 'in_playlist_export'] = true;
            }
            if (! is_string($track) || ! is_string($artist) || ! is_string($album) || ! is_string($uri)
                || trim($track) === '' || trim($artist) === '' || trim($album) === ''
                || mb_strlen($track) > 255 || mb_strlen($artist) > 255 || mb_strlen($album) > 255
                || $id === null) {
                continue;
            }
            $key = self::spotifyKey($track, $artist, $album);
            $uri = 'spotify:track:'.$id;
            if (! array_key_exists($key, $additions)) {
                $additions[$key] = $uri;
            } elseif ($additions[$key] !== $uri) {
                $additions[$key] = null;
            }
        }
        foreach ($additions as $key => $uri) {
            if (! array_key_exists($key, $index)) {
                $index[$key] = $uri;
            } elseif ($index[$key] !== $uri) {
                $index[$key] = null;
            }
        }
        foreach ($presenceAdditions as $id => $flags) {
            $presence[$id] = ($presence[$id] ?? []) + $flags;
        }
    }

    /** @param list<array{file: string, message: string}> $errors */
    private function addError(array &$errors, string $name, ValidationException $exception): void
    {
        foreach ($exception->errors() as $messages) {
            foreach ($messages as $message) {
                if (! is_string($message)) {
                    throw new \UnexpectedValueException('An import validation message is not a string.');
                }
                $errors[] = ['file' => $name, 'message' => $message];
            }
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
