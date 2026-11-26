<?php

namespace App\Console\Commands;

use App\Models\Listen;
use App\Services\MatchedMusicMetadata;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('listens:sync-metadata')]
#[Description('Store artist and album metadata for existing matched listens')]
class SyncListenMetadata extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MatchedMusicMetadata $metadata): int
    {
        $count = 0;
        Listen::query()->whereNotNull('recording_mbid')->chunkById(500, function ($listens) use ($metadata, &$count) {
            foreach ($listens as $listen) {
                $metadata->sync($listen);
                $count++;
            }
        });
        $this->info("Synchronized {$count} matched listens.");

        return self::SUCCESS;
    }
}
