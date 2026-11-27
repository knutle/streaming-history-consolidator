<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CatalogRecordingGroups;
use App\Services\ManualListenMatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('catalogs:link-matched {--user= : Limit linking to one user ID}')]
#[Description('Link catalog recordings with the same MusicBrainz recording')]
class LinkMatchedCatalogRecordings extends Command
{
    public function handle(CatalogRecordingGroups $groups, ManualListenMatcher $manual): int
    {
        $userId = $this->option('user');
        if ($userId !== null && (! ctype_digit($userId) || (int) $userId < 1)) {
            $this->error('Enter a valid user ID.');

            return self::FAILURE;
        }
        $this->warn('Unlinks made before automatic-link exclusions existed cannot be detected by this backfill.');
        $processed = 0;
        $skipped = 0;
        $linked = 0;
        User::query()->whereExists(fn ($query) => $query->selectRaw('1')->from('listens')
            ->whereColumn('listens.user_id', 'users.id')->whereNotNull('listens.recording_mbid'))
            ->when($userId !== null, fn ($query) => $query->whereKey($userId))
            ->chunkById(100, function ($users) use ($groups, $manual, &$processed, &$skipped, &$linked): void {
                foreach ($users as $user) {
                    DB::transaction(function () use ($user, $groups, $manual, &$processed, &$skipped, &$linked): void {
                        DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                        $activeImport = DB::table('import_runs')->where('user_id', $user->id)->whereIn('status', ['queued', 'running'])->exists();
                        $activeScan = DB::table('artist_recording_scans')->where('user_id', $user->id)->whereIn('status', ['queued', 'running'])->exists();
                        if ($activeImport || $activeScan) {
                            $skipped++;

                            return;
                        }
                        $linked += $groups->linkMatching($user, $manual)['linked'];
                        $processed++;
                    });
                }
            });
        $this->info("Processed {$processed} users; linked {$linked} catalog items or groups.");
        if ($skipped > 0) {
            $this->warn("Skipped {$skipped} users with active imports or artist scans; rerun after they finish.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
