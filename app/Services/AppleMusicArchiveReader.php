<?php

namespace App\Services;

use Generator;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class AppleMusicArchiveReader
{
    private const MAX_ENTRIES = 5000;

    private const MAX_FILE_BYTES = 1073741824;

    private const MAX_INDEX_ENTRIES = 100000;

    public function __construct(private StreamingHistoryReader $reader) {}

    /**
     * @return Generator<int, array{timestamp: string, track: string, artist: string, identity_artist: string, album: string, played_ms: int, duration_ms: ?int, uri: string, start_time: bool, apple_music: array<string, mixed>}>
     */
    public function read(string $path): Generator
    {
        if (! class_exists(ZipArchive::class)) {
            $this->invalid('Apple ZIP imports require the PHP zip extension.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            $this->invalid('The Apple export is not a valid ZIP archive.');
        }
        try {
            $wrapper = $this->find($zip, 'Apple_Media_Services.zip');
            if ($wrapper === null) {
                yield from $this->readMediaArchive($zip);
            } else {
                if ($this->find($zip, 'Apple Music Play Activity.csv') !== null) {
                    $this->invalid('The archive contains multiple Apple playback sources.');
                }
                [$nested, $temporary] = $this->openNested($zip, $wrapper);
                try {
                    yield from $this->readMediaArchive($nested);
                } finally {
                    $nested->close();
                    fclose($temporary);
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function readMediaArchive(ZipArchive $zip): Generator
    {
        $playIndex = $this->find($zip, 'Apple Music Play Activity.csv');
        if ($playIndex === null) {
            $this->invalid('The archive does not contain Apple Music Play Activity.csv.');
        }

        $daily = [];
        $dailyIndex = $this->find($zip, 'Apple Music - Play History Daily Tracks.csv');
        if ($dailyIndex !== null) {
            $stream = $this->stream($zip, $dailyIndex);
            try {
                $daily = $this->daily($stream);
                $this->verifyRead($zip, $dailyIndex, $stream);
            } finally {
                fclose($stream);
            }
        }

        $library = [];
        $libraryIndex = $this->find($zip, 'Apple Music Library Tracks.json.zip');
        $plainIndex = $this->find($zip, 'Apple Music Library Tracks.json');
        if ($libraryIndex !== null && $plainIndex !== null) {
            $this->invalid('The archive contains multiple Apple Music library track files.');
        }
        if ($libraryIndex !== null) {
            [$nested, $temporary] = $this->openNested($zip, $libraryIndex);
            try {
                $trackIndex = $this->find($nested, 'Apple Music Library Tracks.json');
                if ($trackIndex === null) {
                    $this->invalid('The Apple Music library archive does not contain its track file.');
                }
                $stream = $this->stream($nested, $trackIndex);
                try {
                    $library = $this->library($stream);
                    $this->verifyRead($nested, $trackIndex, $stream);
                } finally {
                    fclose($stream);
                }
            } finally {
                $nested->close();
                fclose($temporary);
            }
        } elseif ($plainIndex !== null) {
            $stream = $this->stream($zip, $plainIndex);
            try {
                $library = $this->library($stream);
                $this->verifyRead($zip, $plainIndex, $stream);
            } finally {
                fclose($stream);
            }
        }

        $stream = $this->stream($zip, $playIndex);
        try {
            foreach ($this->reader->apple($stream, includeDetails: true) as $entry) {
                $date = substr($entry['timestamp'], 0, 10);
                $dailyKey = $this->key($date, $entry['track']);
                $dailyMatch = $daily[$dailyKey] ?? null;
                $dailyAmbiguous = array_key_exists($dailyKey, $daily) && $daily[$dailyKey] === null;
                $libraryMatches = $library[$this->key($entry['track'], $entry['album'])] ?? [];
                $candidates = [];
                foreach ($libraryMatches as $candidate) {
                    if ($dailyAmbiguous) {
                        continue;
                    }
                    if ($dailyMatch !== null && $candidate['catalog_id'] !== $dailyMatch['catalog_id']) {
                        continue;
                    }
                    if ($entry['duration_ms'] !== null && $candidate['duration_ms'] !== null
                        && abs($entry['duration_ms'] - $candidate['duration_ms']) > 2000) {
                        continue;
                    }
                    $candidates[hash('sha256', json_encode($candidate, JSON_THROW_ON_ERROR))] = $candidate;
                }
                $track = count($candidates) === 1 ? reset($candidates) : null;
                $extra = $entry['apple_event'];
                if ($entry['uri'] !== '') {
                    $extra['event_id'] = $entry['uri'];
                }
                if ($dailyMatch !== null) {
                    $extra['catalog_id'] = $dailyMatch['catalog_id'];
                    $extra['track_artist'] = $dailyMatch['artist'];
                }
                if ($track !== null) {
                    foreach (['track_artist', 'album_artist', 'genre', 'release_date', 'track_number', 'disc_number'] as $field) {
                        if ($track[$field] !== null && $track[$field] !== '') {
                            $extra[$field] = $track[$field];
                        }
                    }
                    if ($track['catalog_id'] !== null) {
                        $extra['catalog_id'] = $track['catalog_id'];
                    }
                }
                if ($entry['artist'] === '') {
                    $entry['artist'] = $track['album_artist'] ?? $track['track_artist'] ?? $dailyMatch['artist'] ?? '';
                }
                unset($entry['apple_event']);
                $entry['apple_music'] = $extra;
                yield $entry;
            }
            $this->verifyRead($zip, $playIndex, $stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     * @return array<string, array{artist: string, catalog_id: string}|null>
     */
    private function daily($stream): array
    {
        $headers = $this->reader->boundedCsvRow($stream);
        if ($headers === false) {
            $this->invalid('The Apple daily track history is empty.');
        }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        if (count($headers) !== count(array_unique($headers)) || array_diff(['Date Played', 'Track Identifier', 'Track Description'], $headers)) {
            $this->invalid('The Apple daily track history has an invalid header.');
        }
        $index = [];
        while (($values = $this->reader->boundedCsvRow($stream)) !== false) {
            if ($values === [null]) {
                continue;
            }
            if (count($values) !== count($headers)) {
                $this->invalid('An Apple daily track history row has the wrong number of columns.');
            }
            $row = array_combine($headers, $values);
            $date = (string) ($row['Date Played'] ?? '');
            $id = (string) ($row['Track Identifier'] ?? '');
            $description = (string) ($row['Track Description'] ?? '');
            $parsed = \DateTimeImmutable::createFromFormat('!Ymd', $date);
            if ($parsed === false || $parsed->format('Ymd') !== $date || ! ctype_digit($id) || strlen($id) > 20 || (int) $id <= 0 || mb_strlen($description) > 1024) {
                continue;
            }
            $parts = explode(' - ', $description);
            if (count($parts) > 10) {
                continue;
            }
            for ($i = 1; $i < count($parts); $i++) {
                $artist = trim(implode(' - ', array_slice($parts, 0, $i)));
                $title = trim(implode(' - ', array_slice($parts, $i)));
                if ($artist === '' || $title === '' || mb_strlen($artist) > 255 || mb_strlen($title) > 255) {
                    continue;
                }
                $key = $this->key($parsed->format('Y-m-d'), $title);
                $match = ['artist' => $artist, 'catalog_id' => $id];
                if (! array_key_exists($key, $index)) {
                    $index[$key] = $match;
                } elseif ($index[$key] !== null && ($this->key($index[$key]['artist'], $index[$key]['catalog_id']) !== $this->key($artist, $id))) {
                    $index[$key] = null;
                }
                if (count($index) > self::MAX_INDEX_ENTRIES) {
                    $this->invalid('The Apple daily track history contains too many distinct tracks.');
                }
            }
        }

        return $index;
    }

    /**
     * @param  resource  $stream
     * @return array<string, list<array{track_artist: ?string, album_artist: ?string, catalog_id: ?string, duration_ms: ?int, genre: ?string, release_date: ?string, track_number: ?int, disc_number: ?int}>>
     */
    private function library($stream): array
    {
        $index = [];
        $count = 0;
        foreach ($this->reader->jsonObjects($stream) as $row) {
            if (++$count > self::MAX_INDEX_ENTRIES) {
                $this->invalid('The Apple Music library contains too many tracks.');
            }
            $title = $this->optionalText($row['Title'] ?? null);
            $album = $this->optionalText($row['Album'] ?? null);
            $artist = $this->optionalText($row['Artist'] ?? null);
            $albumArtist = $this->optionalText($row['Album Artist'] ?? null);
            if ($title === null || $album === null || ($artist === null && $albumArtist === null)) {
                continue;
            }
            $catalog = (string) ($row['Apple Music Track Identifier'] ?? '');
            $candidate = [
                'track_artist' => $artist,
                'album_artist' => $albumArtist,
                'catalog_id' => ctype_digit($catalog) && strlen($catalog) <= 20 && (int) $catalog > 0 ? $catalog : null,
                'duration_ms' => $this->positiveInteger($row['Track Duration'] ?? null),
                'genre' => $this->optionalText($row['Genre'] ?? null),
                'release_date' => $this->optionalText($row['Release Date'] ?? null),
                'track_number' => $this->positiveInteger($row['Track Number On Album'] ?? null),
                'disc_number' => $this->positiveInteger($row['Disc Number Of Album'] ?? null),
            ];
            $index[$this->key($title, $album)][] = $candidate;
        }

        return $index;
    }

    private function optionalText(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 255) {
            return null;
        }

        return trim($value);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if ((! is_int($value) && (! is_string($value) || ! ctype_digit($value))) || (float) $value > PHP_INT_MAX || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    private function key(string $first, string $second): string
    {
        return hash('sha256', json_encode([mb_strtolower(trim($first)), mb_strtolower(trim($second))], JSON_THROW_ON_ERROR));
    }

    private function find(ZipArchive $zip, string $name): ?int
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            $this->invalid('The Apple ZIP archive contains too many files.');
        }
        $found = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry !== false && strcasecmp(basename($entry), $name) === 0) {
                if ($found !== null) {
                    $this->invalid('The Apple ZIP archive contains duplicate music data files.');
                }
                $found = $i;
            }
        }

        return $found;
    }

    /** @return resource */
    private function stream(ZipArchive $zip, int $index)
    {
        $stat = $zip->statIndex($index);
        if ($stat === false || $stat['size'] > self::MAX_FILE_BYTES || ($stat['size'] > 0 && $stat['size'] > max(1, $stat['comp_size']) * 1000)
            || $stat['encryption_method'] !== 0) {
            $this->invalid('An Apple ZIP entry is encrypted or exceeds the safe decompression limit.');
        }
        $stream = $zip->getStreamIndex($index);
        if ($stream === false) {
            $this->invalid('An Apple ZIP entry could not be read.');
        }

        return $stream;
    }

    /** @return array{ZipArchive, resource} */
    private function openNested(ZipArchive $parent, int $index): array
    {
        $stream = $this->stream($parent, $index);
        $temporary = tmpfile();
        if ($temporary === false) {
            fclose($stream);
            $this->invalid('Could not create a temporary file for the Apple ZIP archive.');
        }
        $copied = stream_copy_to_stream($stream, $temporary, self::MAX_FILE_BYTES + 1);
        $stat = $parent->statIndex($index);
        fclose($stream);
        if ($stat === false || $copied === false || $copied !== $stat['size'] || $copied > self::MAX_FILE_BYTES || ! fflush($temporary)) {
            fclose($temporary);
            $this->invalid('The nested Apple ZIP archive could not be read safely.');
        }
        $path = stream_get_meta_data($temporary)['uri'] ?? null;
        if (! is_string($path)) {
            fclose($temporary);
            $this->invalid('Could not access a temporary file for the Apple ZIP archive.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            fclose($temporary);
            $this->invalid('A nested Apple ZIP archive is invalid.');
        }

        return [$zip, $temporary];
    }

    /** @param  resource  $stream */
    private function verifyRead(ZipArchive $zip, int $index, $stream): void
    {
        $stat = $zip->statIndex($index);
        if ($stat === false || ftell($stream) !== $stat['size']) {
            $this->invalid('An Apple ZIP entry is incomplete or could not be read.');
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
