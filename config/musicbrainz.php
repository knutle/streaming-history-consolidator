<?php

$setupPath = env('MUSICBRAINZ_SETUP_PATH', storage_path('app/private/musicbrainz'));
$path = $setupPath.'/connection.json';
$contents = is_file($path) ? file_get_contents($path) : null;
if ($contents === false) {
    throw new RuntimeException('Unable to read the local MusicBrainz connection.');
}
$local = $contents !== null ? json_decode($contents, true, 512, JSON_THROW_ON_ERROR) : null;

return [
    'enabled' => env('MUSICBRAINZ_ENABLED', $local !== null),
    'setup_path' => $setupPath,
    'format' => $local['format'] ?? 'full',
];
