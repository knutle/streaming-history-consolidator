<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CatalogRecordingGroups;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('catalogs:refresh-linked-evidence {--user= : Limit the refresh to one user ID}')]
#[Description('Collect existing catalog evidence for linked recordings')]
class RefreshLinkedCatalogEvidence extends Command
{
    public function handle(CatalogRecordingGroups $groups): int
    {
        $userId = $this->option('user');
        if ($userId !== null && (! ctype_digit($userId) || (int) $userId < 1)) {
            $this->error('Enter a valid user ID.');

            return self::FAILURE;
        }
        $processed = 0;
        $skipped = 0;
        User::query()->whereExists(fn ($query) => $query->selectRaw('1')->from('catalog_recording_members')
            ->whereColumn('catalog_recording_members.user_id', 'users.id'))
            ->when($userId !== null, fn ($query) => $query->whereKey($userId))
            ->chunkById(100, function ($users) use ($groups, &$processed, &$skipped): void {
                foreach ($users as $user) {
                    if (DB::table('import_runs')->where('user_id', $user->id)->whereIn('status', ['queued', 'running'])->exists()) {
                        $skipped++;

                        continue;
                    }
                    $groups->syncEvidence($user);
                    $processed++;
                }
            });
        $this->info("Refreshed linked catalog evidence for {$processed} users.");
        if ($skipped > 0) {
            $this->warn("Skipped {$skipped} users with active imports; rerun after they finish.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
