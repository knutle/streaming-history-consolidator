<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessBulkMusicBrainzMatch;
use App\Services\ListenFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BulkMusicBrainzController extends Controller
{
    public function selection(Request $request, ListenFilters $filters): RedirectResponse
    {
        $values = $request->validate([
            'action' => ['required', Rule::in(['all', 'clear', 'add', 'remove'])],
            'ids' => ['array', 'max:25'],
            'ids.*' => ['integer', 'distinct'],
        ]);
        $criteria = $filters->validate($request);
        $userId = $request->user()->id;
        $ids = $values['ids'] ?? [];
        DB::transaction(function () use ($userId, $values, $ids, $criteria, $filters): void {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            if (in_array($values['action'], ['add', 'remove'], true)) {
                $count = $filters->query($userId, $criteria)->whereNull('recording_mbid')->whereKey($ids)->count();
                if ($ids === [] || $count !== count($ids)) {
                    throw ValidationException::withMessages(['selection' => 'The selection contains unavailable listens. Refresh and try again.']);
                }
            }
            $hash = $filters->selectionHash($criteria);
            $selection = DB::table('bulk_musicbrainz_selections')->where('user_id', $userId)->first();
            if ($selection === null || $selection->filter_hash !== $hash || in_array($values['action'], ['clear', 'all'], true)) {
                DB::table('bulk_musicbrainz_selections')->where('user_id', $userId)->delete();
                if ($values['action'] === 'clear') {
                    return;
                }
                DB::table('bulk_musicbrainz_selections')->insert([
                    'user_id' => $userId, 'filter_hash' => $hash, 'all_selected' => $values['action'] === 'all',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $all = $values['action'] === 'all';
            } else {
                $all = (bool) $selection->all_selected;
            }
            if ($ids === []) {
                return;
            }
            $record = ($values['action'] === 'add') !== $all;
            if ($record) {
                DB::table('bulk_musicbrainz_selection_items')->insertOrIgnore(array_map(
                    fn ($id) => ['user_id' => $userId, 'listen_id' => $id], $ids,
                ));
            } else {
                DB::table('bulk_musicbrainz_selection_items')->where('user_id', $userId)->whereIn('listen_id', $ids)->delete();
            }
        });

        return back();
    }

    public function store(Request $request, ListenFilters $filters): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']]);
        $criteria = $filters->validate($request);
        $userId = $request->user()->id;
        DB::transaction(function () use ($userId, $criteria, $filters): void {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            if (DB::table('bulk_musicbrainz_runs')->where('user_id', $userId)->whereIn('status', ['queued', 'running', 'waiting', 'paused'])->exists()) {
                throw ValidationException::withMessages(['selection' => 'Finish or cancel the current bulk match before starting another.']);
            }
            $selection = DB::table('bulk_musicbrainz_selections')->where('user_id', $userId)
                ->where('filter_hash', $filters->selectionHash($criteria))->first();
            if ($selection === null) {
                throw ValidationException::withMessages(['selection' => 'Select at least one unmatched listen.']);
            }
            $runId = DB::table('bulk_musicbrainz_runs')->insertGetId([
                'user_id' => $userId, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $items = DB::table('bulk_musicbrainz_selection_items')->where('user_id', $userId)->select('listen_id');
            $query = $filters->query($userId, $criteria)->whereNull('recording_mbid');
            if ($selection->all_selected) {
                $query->whereNotIn('id', $items);
            } else {
                $query->whereIn('id', $items);
            }
            DB::table('bulk_musicbrainz_run_items')->insertUsing(
                ['run_id', 'listen_id'], $query->selectRaw('? as run_id, id as listen_id', [$runId])->toBase(),
            );
            $total = DB::table('bulk_musicbrainz_run_items')->where('run_id', $runId)->count();
            if ($total === 0) {
                throw ValidationException::withMessages(['selection' => 'No selected unmatched listens are available.']);
            }
            DB::table('bulk_musicbrainz_runs')->where('id', $runId)->update(['total' => $total]);
            DB::table('bulk_musicbrainz_selections')->where('user_id', $userId)->delete();
            ProcessBulkMusicBrainzMatch::dispatch($runId);
        });

        return back();
    }

    public function cancel(Request $request, int $run): RedirectResponse
    {
        DB::transaction(function () use ($request, $run): void {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $row = DB::table('bulk_musicbrainz_runs')->where('user_id', $request->user()->id)->where('id', $run)->first();
            abort_if($row === null, 404);
            DB::table('bulk_musicbrainz_runs')->where('id', $run)->whereIn('status', ['queued', 'running', 'waiting', 'paused'])
                ->update(['status' => 'cancelled', 'retry_at' => null, 'updated_at' => now()]);
        });

        return back();
    }

    public function resume(Request $request, int $run): RedirectResponse
    {
        DB::transaction(function () use ($request, $run): void {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $row = DB::table('bulk_musicbrainz_runs')->where('user_id', $request->user()->id)->where('id', $run)->first();
            abort_if($row === null, 404);
            if ($row->status !== 'paused') {
                throw ValidationException::withMessages(['selection' => 'This run cannot be resumed.']);
            }
            DB::table('bulk_musicbrainz_run_items')->where('run_id', $run)->where('status', 'pending')->update(['attempts' => 0]);
            DB::table('bulk_musicbrainz_runs')->where('id', $run)->update([
                'status' => 'queued', 'error' => null, 'retry_at' => null, 'updated_at' => now(),
            ]);
            ProcessBulkMusicBrainzMatch::dispatch($run);
        });

        return back();
    }
}
