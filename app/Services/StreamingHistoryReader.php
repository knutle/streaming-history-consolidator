<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Validation\ValidationException;
use JsonException;

class StreamingHistoryReader
{
    /**
     * @return Generator<int, array{timestamp: string, track: string, artist: string, identity_artist?: string, album: string, played_ms: int, duration_ms: ?int, uri: string, start_time: bool, isrc: ?string, spotify?: array<string, string|bool>}>
     */
    public function read(string $path, string $source): Generator
    {
        $file = fopen($path, 'rb');
        if ($file === false) {
            throw ValidationException::withMessages(['file' => 'The uploaded file could not be read.']);
        }

        try {
            yield from $source === 'spotify' ? $this->spotify($file) : $this->apple($file);
        } finally {
            fclose($file);
        }
    }

    /**
     * @param  resource  $file
     * @return Generator<int, array{timestamp: string, track: string, artist: string, album: string, played_ms: int, duration_ms: ?int, uri: string, start_time: bool, isrc: ?string, spotify: array<string, string|bool>}>
     */
    private function spotify($file): Generator
    {
        foreach ($this->jsonObjects($file) as $row) {
            if (! array_key_exists('master_metadata_track_name', $row) || ! array_key_exists('ms_played', $row) || ! array_key_exists('ts', $row)) {
                $this->invalid('The file is not a Spotify extended streaming history export.');
            }
            if ($row['master_metadata_track_name'] === null) {
                continue;
            }
            $track = $this->text($row['master_metadata_track_name']);
            $artist = $this->text($row['master_metadata_album_artist_name'] ?? '');
            $album = $this->text($row['master_metadata_album_album_name'] ?? '');
            $uri = $this->text($row['spotify_track_uri'] ?? '');
            $rawIsrc = $row['isrc'] ?? ($row['external_ids']['isrc'] ?? null);
            $isrc = $this->optionalIsrc($rawIsrc);
            $played = $this->milliseconds($row['ms_played']);
            $timestamp = $this->timestamp($row['ts']);
            if ($track === '') {
                $this->invalid('A Spotify entry has an empty track name.');
            }
            if ($played > 0) {
                $details = [];
                foreach (['reason_start', 'reason_end'] as $field) {
                    if (($row[$field] ?? null) !== null) {
                        $details[$field] = $this->text($row[$field]);
                    }
                }
                foreach (['shuffle', 'skipped', 'offline'] as $field) {
                    if (($row[$field] ?? null) !== null) {
                        if (! is_bool($row[$field])) {
                            $this->invalid('A Spotify playback flag is invalid.');
                        }
                        $details[$field] = $row[$field];
                    }
                }
                yield ['timestamp' => $timestamp, 'track' => $track, 'artist' => $artist, 'album' => $album, 'played_ms' => $played, 'duration_ms' => null, 'uri' => $uri, 'start_time' => false, 'isrc' => $isrc, 'spotify' => $details];
            }
        }
    }

    /**
     * @param  resource  $file
     * @return Generator<int, array<string, mixed>>
     */
    public function jsonObjects($file): Generator
    {
        if ($this->nextNonWhitespace($file) !== '[') {
            $this->invalid('Expected a JSON array.');
        }

        $first = true;
        while (true) {
            $char = $this->nextNonWhitespace($file);
            if ($char === ']' && $first) {
                break;
            }
            if (! $first) {
                if ($char === ']') {
                    break;
                }
                if ($char !== ',') {
                    $this->invalid('Invalid Spotify JSON array.');
                }
                $char = $this->nextNonWhitespace($file);
            }
            if ($char !== '{') {
                $this->invalid('Each JSON array entry must be an object.');
            }

            $json = '{';
            $depth = 1;
            $inString = false;
            $escaped = false;
            while ($depth > 0 && ($char = fgetc($file)) !== false) {
                $json .= $char;
                if (strlen($json) > 1024 * 1024) {
                    $this->invalid('A JSON array entry is too large.');
                }
                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($char === '\\') {
                        $escaped = true;
                    } elseif ($char === '"') {
                        $inString = false;
                    }
                } elseif ($char === '"') {
                    $inString = true;
                } elseif ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                }
            }
            if ($depth !== 0) {
                $this->invalid('The JSON file is incomplete.');
            }
            try {
                $row = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->invalid('A JSON array entry contains invalid JSON.');
            }
            if (! is_array($row)) {
                $this->invalid('Each JSON array entry must be an object.');
            }
            $first = false;
            yield $row;
        }

        if ($this->nextNonWhitespace($file) !== false) {
            $this->invalid('Unexpected data after the JSON array.');
        }
    }

    /**
     * @param  resource  $file
     * @return Generator<int, array{timestamp: string, track: string, artist: string, identity_artist: string, album: string, played_ms: int, duration_ms: ?int, uri: string, start_time: bool, isrc: ?string, apple_event: array<string, mixed>}>
     */
    public function apple($file, bool $includeDetails = false): Generator
    {
        $headers = $includeDetails ? $this->boundedCsvRow($file) : fgetcsv($file, escape: '');
        if ($headers === false) {
            $this->invalid('The Apple Music CSV file is empty.');
        }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        $headers = array_map(fn ($value) => trim((string) $value), $headers);
        if (count($headers) !== count(array_unique($headers)) || ! in_array('Song Name', $headers, true) || ! in_array('Play Duration Milliseconds', $headers, true) || (! in_array('Event Start Timestamp', $headers, true) && ! in_array('Event End Timestamp', $headers, true))) {
            $this->invalid('Expected an Apple Music Play Activity CSV with song, timestamp, and play duration columns.');
        }
        while (($values = $includeDetails ? $this->boundedCsvRow($file) : fgetcsv($file, escape: '')) !== false) {
            if ($values === [null]) {
                continue;
            }
            if (count($values) !== count($headers)) {
                $this->invalid('An Apple Music CSV row has the wrong number of columns.');
            }
            $row = array_combine($headers, $values);
            $event = strtoupper(trim((string) ($row['Event Type'] ?? '')));
            if ($event !== '' && $event !== 'PLAY_END') {
                continue;
            }
            $reason = strtoupper(trim((string) ($row['End Reason Type'] ?? '')));
            if (in_array($reason, ['SCRUB_BEGIN', 'SCRUB_END'], true)) {
                continue;
            }
            $radioType = strtoupper(trim((string) ($row['Radio Type'] ?? '')));
            if (in_array($radioType, ['INTERVIEW', 'CUSTOM_AUDIO_OTHER', 'JOCK_VOICEOVER'], true)) {
                continue;
            }
            $track = $this->text($row['Song Name']);
            if ($track === '' || ($row['Play Duration Milliseconds'] ?? '') === '') {
                continue;
            }
            $played = $this->milliseconds($row['Play Duration Milliseconds']);
            if ($played === 0) {
                continue;
            }
            $start = trim((string) ($row['Event Start Timestamp'] ?? ''));
            $timestamp = $this->timestamp($start !== '' ? $start : ($row['Event End Timestamp'] ?? ''));
            $duration = trim((string) ($row['Media Duration In Milliseconds'] ?? ''));
            $artist = $this->text($row['Artist Name'] ?? '');
            $albumArtist = $this->text($row['Album Artist Name'] ?? '');
            if ($albumArtist === '') {
                $albumArtist = $this->text($row['Album Artist'] ?? '');
            }
            $eventDetails = [];
            foreach ([
                'Container Name' => 'container_name',
                'Container Type' => 'container_type',
                'Container Album Name' => 'container_album_name',
                'Container Artist Name' => 'container_artist_name',
                'Container ID' => 'container_id',
                'Container Global Playlist ID' => 'container_global_playlist_id',
                'Container Playlist ID' => 'container_playlist_id',
                'Source Radio Name' => 'source_radio_name',
                'Source Radio Type' => 'source_radio_type',
            ] as $column => $key) {
                if (isset($row[$column]) && trim((string) $row[$column]) !== '') {
                    $eventDetails[$key] = $this->text($row[$column]);
                }
            }
            if ($includeDetails) {
                foreach (['End Reason Type' => 'end_reason', 'Source Type' => 'source_type', 'Offline' => 'offline', 'Shuffle Play' => 'shuffle', 'Repeat Play' => 'repeat'] as $column => $key) {
                    if (isset($row[$column]) && trim((string) $row[$column]) !== '') {
                        $eventDetails[$key] = $this->text($row[$column]);
                    }
                }
                foreach (['Start Position In Milliseconds' => 'start_position_ms', 'End Position In Milliseconds' => 'end_position_ms'] as $column => $key) {
                    if (isset($row[$column]) && trim((string) $row[$column]) !== '') {
                        $eventDetails[$key] = $this->milliseconds(trim((string) $row[$column]));
                    }
                }
            }
            yield [
                'timestamp' => $timestamp,
                'track' => $track,
                'artist' => $albumArtist !== '' ? $albumArtist : $artist,
                // Preserve fingerprints from imports made before album-artist support.
                'identity_artist' => $artist,
                'album' => $this->text($row['Album Name'] ?? ''),
                'played_ms' => $played,
                'duration_ms' => $duration === '' ? null : $this->milliseconds($duration),
                'uri' => $this->text($row['Event ID'] ?? ''),
                'start_time' => $start !== '',
                'isrc' => $this->optionalIsrc($row['ISRC'] ?? null),
                'apple_event' => $eventDetails,
            ];
        }
    }

    /**
     * @param  resource  $file
     * @return array<int, string|null>|false
     */
    public function boundedCsvRow($file): array|false
    {
        $record = '';
        $inQuotes = false;
        $pendingQuote = false;
        $fieldStart = true;
        while (($chunk = fgets($file, 65536)) !== false) {
            $record .= $chunk;
            if (strlen($record) > 1024 * 1024) {
                $this->invalid('An Apple CSV record is too large.');
            }
            $length = strlen($chunk);
            for ($i = 0; $i < $length; $i++) {
                $char = $chunk[$i];
                if ($pendingQuote) {
                    $pendingQuote = false;
                    if ($char === '"') {
                        continue;
                    }
                    $inQuotes = false;
                }
                if ($inQuotes) {
                    if ($char === '"') {
                        $pendingQuote = true;
                    }
                } elseif ($fieldStart && $char === '"') {
                    $inQuotes = true;
                    $fieldStart = false;
                } elseif ($char === ',') {
                    $fieldStart = true;
                } elseif ($char !== ' ' && $char !== "\t") {
                    $fieldStart = false;
                }
            }
            if (str_ends_with($chunk, "\n") && (! $inQuotes || $pendingQuote)) {
                return str_getcsv($record, escape: '');
            }
        }
        if ($record === '') {
            return false;
        }
        if ($inQuotes && ! $pendingQuote) {
            $this->invalid('An Apple CSV record is incomplete.');
        }

        return str_getcsv($record, escape: '');
    }

    private function text(mixed $value): string
    {
        if (! is_string($value) || mb_strlen($value) > 255) {
            $this->invalid('A track, artist, album, or URI value is invalid or too long.');
        }

        return trim($value);
    }

    public function optionalIsrc(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $isrc = Isrc::normalize($value);
        if ($isrc === null) {
            $this->invalid('An ISRC in the import file is invalid.');
        }

        return $isrc;
    }

    private function milliseconds(mixed $value): int
    {
        if ((! is_int($value) && (! is_string($value) || ! ctype_digit($value))) || (float) $value > PHP_INT_MAX || (int) $value < 0) {
            $this->invalid('A play duration is invalid.');
        }

        return (int) $value;
    }

    private function timestamp(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/', $value)) {
            $this->invalid('A playback timestamp is invalid.');
        }
        $parts = date_parse($value);
        if ($parts['warning_count'] > 0 || $parts['error_count'] > 0) {
            $this->invalid('A playback timestamp is invalid.');
        }
        try {
            return CarbonImmutable::parse($value, 'UTC')->utc()->toISOString();
        } catch (\Exception) {
            $this->invalid('A playback timestamp is invalid.');
        }
    }

    /**
     * @param  resource  $file
     *
     * @phpstan-impure
     */
    private function nextNonWhitespace($file): string|false
    {
        do {
            $char = fgetc($file);
        } while ($char !== false && str_contains(" \t\r\n", $char));

        return $char;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
