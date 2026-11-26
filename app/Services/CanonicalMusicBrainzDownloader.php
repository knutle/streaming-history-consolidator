<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class CanonicalMusicBrainzDownloader
{
    private const BASE_URL = 'https://data.metabrainz.org/pub/musicbrainz/canonical_data/';

    public function download(string $directory): string
    {
        $destination = $directory.'/canonical_musicbrainz_data.csv';
        File::ensureDirectoryExists($directory, 0700, true);
        if (file_exists($destination)) {
            throw new RuntimeException("Canonical CSV already exists: {$destination}. It was not overwritten.");
        }
        foreach (['bash', 'tar', 'zstd'] as $tool) {
            $result = Process::run([$tool, '--version']);
            if ($result->failed()) {
                throw new RuntimeException("The {$tool} command is required to extract the canonical dump.");
            }
        }

        $listing = Http::connectTimeout(15)->timeout(60)->get(self::BASE_URL)->throw()->body();
        preg_match_all('~href=["\'](musicbrainz-canonical-dump-[0-9]{8}-[0-9]{6})/~', $listing, $matches);
        if ($matches[1] === []) {
            throw new RuntimeException('No canonical MusicBrainz dump was found in the official directory.');
        }
        rsort($matches[1], SORT_STRING);
        $name = $matches[1][0];
        $url = self::BASE_URL.$name.'/'.$name.'.tar.zst';
        $checksum = trim(Http::connectTimeout(15)->timeout(60)->get($url.'.sha256')->throw()->body());
        if (! preg_match('/\A[0-9a-f]{64}\z/i', $checksum)) {
            throw new RuntimeException('The canonical dump SHA-256 checksum is invalid.');
        }

        $archive = tempnam($directory, '.canonical-archive-');
        if ($archive === false) {
            throw new RuntimeException("Cannot create a temporary archive in {$directory}.");
        }
        $partial = tempnam($directory, '.canonical-csv-');
        if ($partial === false) {
            unlink($archive);
            throw new RuntimeException("Cannot create a temporary CSV in {$directory}.");
        }

        try {
            Http::connectTimeout(15)->timeout(86400)->sink($archive)->get($url)->throw();
            $actual = hash_file('sha256', $archive);
            if ($actual === false || ! hash_equals(strtolower($checksum), $actual)) {
                throw new RuntimeException('The canonical dump failed SHA-256 verification.');
            }

            $result = Process::forever()->run([
                'bash', '-o', 'pipefail', '-c',
                'zstd -dc -- "$1" | tar -xOf - "$2" > "$3"',
                'extract-canonical', $archive, $name.'/canonical/canonical_musicbrainz_data.csv', $partial,
            ]);
            if ($result->failed()) {
                throw new RuntimeException('Could not extract canonical metadata: '.trim($result->errorOutput()));
            }
            if (filesize($partial) === 0) {
                throw new RuntimeException('The extracted canonical metadata CSV is empty.');
            }
            if (! @link($partial, $destination)) {
                throw new RuntimeException("Could not save canonical CSV without overwriting: {$destination}");
            }

            return $destination;
        } finally {
            unlink($archive);
            unlink($partial);
        }
    }
}
