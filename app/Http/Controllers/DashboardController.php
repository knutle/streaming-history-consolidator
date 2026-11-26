<?php

namespace App\Http\Controllers;

use App\Models\Listen;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $userId = $request->user()->id;
        $stats = DB::table('listens')->where('user_id', $userId)
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(played_ms), 0) as played_ms, SUM(CASE WHEN recording_mbid IS NULL THEN 1 ELSE 0 END) as unmatched')
            ->first();
        $recent = Listen::query()->where('user_id', $userId)
            ->where('listened_at', '>=', now()->subDays(30)->timestamp)
            ->where('listened_at', '<=', now()->timestamp)
            ->count();

        return Inertia::render('dashboard', [
            'stats' => [
                'total' => (int) $stats->total,
                'playedMs' => (int) $stats->played_ms,
                'unmatched' => (int) $stats->unmatched,
                'last30Days' => $recent,
            ],
            'imports' => DB::table('import_batches')->where('user_id', $userId)
                ->orderByDesc('id')->limit(5)->get(['id', 'source', 'imported', 'duplicates', 'matched', 'created_at']),
        ]);
    }
}
