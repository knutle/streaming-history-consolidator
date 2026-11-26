<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Validation\ValidationException;
use JsonException;

class StreamingHistoryReader
{
    /**
     * @return Generator<int, array{timestamp: string, track: string, artist: string, identity_artist?: string, album: string, played_ms: int, duration_ms: ?int, uri: string, start_time: bool}>
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
     * @return Generator<int, array{timestamp: string, track: string, artist: string, album: string, played_ms: int, duration_ms: ?int, uri: string, start_time: bool}>
     */
    private function spotify($file): Generator
    {
        if ($this->nextNonWhitespace($file) !== '[') {
            $this->invalid('Expected a Spotify extended history JSON array.');
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
                $this->invalid('Each Spotify history entry must be an object.');
            }

            $json = '{';
            $depth = 1;
            $inString = false;
            $escaped = false;
            while ($depth > 0 && ($char = fgetc($file)) !== false) {
                $json .= $char;
                if (strlen($json) > 1024 * 1024) {
                    $this->invalid('A Spotify history entry is too large.');
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
                $this->invalid('The Spotify JSON file is incomplete.');
            }
            try {
                $row = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->invalid('A Spotify history entry contains invalid JSON.');
            }
            if (! is_array($row) || ! array_key_exists('master_metadata_track_name', $row) || ! array_key_exists('ms_played', $row) || ! array_key_exists('ts', $row)) {
                $this->invalid('The file is not a Spotify extended streaming history export.');
            }
            $first = false;
            if ($row['master_metadata_track_name'] === null) {
                continue;
            }
            $track = $this->text($row['master_metadata_track_name']);
            $artist = $this->text($row['master_metadata_album_artist_name'] ?? '');
            $album = $this->text($row['master_metadata_album_album_name'] ?? '');
            $uri = $this->text($row['spotify_track_uri'] ?? '');
            $played = $this->milliseconds($row['ms_played']);
            $timestamp = $this->timestamp($row['ts']);
            if ($track === '') {
                $this->invalid('A Spotify entry has an empty track name.');
            }
            if ($played > 0) {
                yield ['timestamp' => $timestamp, 'track' => $track, 'artist' => $artist, 'album' => $album, 'played_ms' => $played, 'duration_ms' => null, 'uri' => $uri, 'start_time' => false];
            }
        }

        if ($this->nextNonWhitespace($file) !== false) {
            $this->invalid('Unexpected data after the Spotify JSON array.');
        }
    }

    /**
     * @param  resource  $file
     * @return Generator<int, array{timestamp: string, track: string, artist: string, identity_artist: string, album: string, played_ms: int, duration_ms: ?int, uri: string, start_time: bool}>
     */
    private function apple($file): Generator
    {
        $headers = fgetcsv($file, escape: '');
        if ($headers === false) {
            $this->invalid('The Apple Music CSV file is empty.');
        }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        $headers = array_map(fn ($value) => trim((string) $value), $headers);
        if (count($headers) !== count(array_unique($headers)) || ! in_array('Song Name', $headers, true) || ! in_array('Play Duration Milliseconds', $headers, true) || (! in_array('Event Start Timestamp', $headers, true) && ! in_array('Event End Timestamp', $headers, true))) {
            $this->invalid('Expected an Apple Music Play Activity CSV with song, timestamp, and play duration columns.');
        }
        while (($values = fgetcsv($file, escape: '')) !== false) {
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
            ];
        }
    }

    private function text(mixed $value): string
    {
        if (! is_string($value) || mb_strlen($value) > 255) {
            $this->invalid('A track, artist, album, or URI value is invalid or too long.');
        }

        return trim($value);
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
