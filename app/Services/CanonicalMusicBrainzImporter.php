<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;

class CanonicalMusicBrainzImporter
{
    public function import(string $csv, string $root, ?callable $progress = null): int
    {
        $file = fopen($csv, 'rb');
        if ($file === false) {
            throw new RuntimeException("Cannot open canonical metadata CSV: {$csv}");
        }

        try {
            $header = fgetcsv($file, escape: '');
            $required = ['artist_mbids', 'artist_credit_name', 'release_mbid', 'release_name', 'recording_mbid', 'recording_name'];
            if ($header === false) {
                throw new RuntimeException('Canonical metadata CSV is empty.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            if (count($header) !== count(array_unique($header)) || array_diff($required, $header)) {
                throw new RuntimeException('Expected the canonical_musicbrainz_data.csv header; redirect CSVs cannot be used for metadata matching.');
            }
            $columns = array_flip($header);

            File::ensureDirectoryExists(dirname($root), 0700, true);
            if (! mkdir($root, 0700)) {
                throw new RuntimeException("Cannot create new setup directory: {$root}");
            }
            $directory = realpath($root);
            if ($directory === false) {
                throw new RuntimeException("Cannot resolve setup directory: {$root}");
            }
            $database = $directory.'/canonical.sqlite';
            $db = new PDO('sqlite:'.$database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if (! chmod($database, 0600)) {
                throw new RuntimeException('Could not protect the canonical database file.');
            }
            $db->exec('CREATE TABLE canonical_recordings (artist_mbids TEXT NOT NULL, artist_credit_name TEXT NOT NULL, release_mbid TEXT NOT NULL, release_name TEXT NOT NULL, recording_mbid TEXT NOT NULL, recording_name TEXT NOT NULL)');
            $insert = $db->prepare('INSERT INTO canonical_recordings VALUES (?, ?, ?, ?, ?, ?)');
            $db->beginTransaction();
            $count = 0;
            while (($row = fgetcsv($file, escape: '')) !== false) {
                if (count($row) !== count($header)) {
                    throw new RuntimeException('Malformed canonical metadata CSV at row '.($count + 2).'.');
                }
                $values = array_map(fn ($column) => $row[$columns[$column]], $required);
                $mbids = [...($values[0] === '' ? [] : explode(',', $values[0])), $values[2], $values[4]];
                foreach ($mbids as $mbid) {
                    if (! preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/i', $mbid)) {
                        throw new RuntimeException('Invalid MusicBrainz identifier in canonical metadata CSV at row '.($count + 2).'.');
                    }
                }
                $insert->execute($values);
                $count++;
                if ($progress !== null && $count % 1_000_000 === 0) {
                    $progress($count);
                }
            }
            if (! feof($file)) {
                throw new RuntimeException('Could not read the entire canonical metadata CSV.');
            }
            if ($count === 0) {
                throw new RuntimeException('Canonical metadata CSV contains no recordings.');
            }
            $db->commit();
            if ($progress !== null) {
                $progress($count);
            }
            $db->exec('CREATE INDEX canonical_recordings_lookup ON canonical_recordings (recording_name, artist_credit_name, release_name)');
            $db = null;
            $this->verify($database);
            $connection = json_encode(['format' => 'canonical', 'database' => $database], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
            $pending = $directory.'/connection.pending';
            if (File::put($pending, $connection) === false || ! chmod($pending, 0600) || ! rename($pending, $directory.'/connection.json')) {
                throw new RuntimeException('Could not write the canonical database configuration.');
            }

            return $count;
        } finally {
            fclose($file);
        }
    }

    public function verify(string $database): void
    {
        if (! is_file($database)) {
            throw new RuntimeException("Canonical MusicBrainz database is missing: {$database}");
        }
        $db = new PDO('sqlite:'.$database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $result = $db->query('SELECT EXISTS (SELECT 1 FROM canonical_recordings LIMIT 1)');
        if ($result === false || (int) $result->fetchColumn() !== 1) {
            throw new RuntimeException('Canonical MusicBrainz database contains no recordings.');
        }
    }
}
