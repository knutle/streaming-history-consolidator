<?php

namespace App\Console\Commands;

use App\Models\Listen;
use App\Services\CatalogMatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('catalogs:sync-metadata')]
#[Description('Populate catalog metadata from locally stored matches and listens')]
class SyncCatalogMetadata extends Command
{
    public function handle(CatalogMatcher $matcher): int
    {
        $count = 0;
        DB::table('catalog_matches')->orderByDesc('updated_at')->orderByDesc('id')->chunk(500, function ($matches) use ($matcher) {
            foreach ($matches as $row) {
                DB::transaction(function () use ($matcher, $row) {
                    DB::table('users')->where('id', $row->user_id)->lockForUpdate()->first();
                    $matcher->storeMetadata((int) $row->user_id, json_decode($row->match, true, 512, JSON_THROW_ON_ERROR), overwriteManual: false);
                });
            }
        });
        Listen::query()->chunkById(500, function ($listens) use ($matcher, &$count) {
            foreach ($listens as $listen) {
                DB::transaction(function () use ($matcher, $listen) {
                    DB::table('users')->where('id', $listen->user_id)->lockForUpdate()->first();
                    $extra = $listen->track_metadata['additional_info']['apple_music'] ?? [];
                    foreach ($matcher->forListen($listen, includeStored: false) as $match) {
                        $matcher->storeMetadata($listen->user_id, $match,
                            ($match['provider'] ?? null) === 'apple' && is_array($extra)
                                && ($extra['catalog_id'] ?? null) === ($match['id'] ?? null) ? $extra : [],
                            overwriteManual: false);
                    }
                    $id = is_array($extra) ? ($extra['catalog_id'] ?? null) : null;
                    if (is_string($id) && $matcher->identifier('apple', $matcher->url('apple', $id)) === $id) {
                        $duration = $listen->track_metadata['additional_info']['duration_ms'] ?? null;
                        $artist = is_string($extra['track_artist'] ?? null) ? $extra['track_artist'] : $listen->artist_name;
                        $imported = $matcher->match('apple', $id, $listen->track_name, $artist, $listen->release_name,
                            is_int($duration) ? $duration : null, 'import');
                        $matcher->storeMetadata($listen->user_id, $imported, $extra, overwriteManual: false);
                    }
                });
                $count++;
            }
        });
        $this->info("Processed {$count} listens.");

        return self::SUCCESS;
    }
}
