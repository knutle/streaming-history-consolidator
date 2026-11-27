<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessRecordingAssociation;
use App\Models\Listen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PendingMusicBrainzMatchController extends Controller
{
    public function index(Request $request): Response
    {
        $pending = DB::table('pending_musicbrainz_matches')
            ->join('listens', 'listens.id', '=', 'pending_musicbrainz_matches.listen_id')
            ->where('pending_musicbrainz_matches.user_id', $request->user()->id)
            ->orderByDesc('pending_musicbrainz_matches.id')
            ->paginate(25, ['pending_musicbrainz_matches.*', 'listens.recording_mbid'])
            ->withQueryString();
        $latest = DB::table('recording_association_runs')->where('user_id', $request->user()->id)
            ->whereIn('listen_id', $pending->getCollection()->pluck('listen_id'))->groupBy('listen_id')->selectRaw('MAX(id)');
        $associations = DB::table('recording_association_runs')->whereIn('id', $latest)
            ->get(['listen_id', 'status', 'matched', 'error'])->keyBy('listen_id');
        $pending->through(function ($row) use ($associations) {
            $row->candidates = json_decode($row->candidates, true, 512, JSON_THROW_ON_ERROR);
            $row->incomplete = (bool) $row->incomplete;
            $row->association = $associations->get($row->listen_id);

            return $row;
        });

        $bulkRun = DB::table('bulk_musicbrainz_runs')->where('user_id', $request->user()->id)
            ->orderByDesc('id')->first(['id', 'status', 'matched', 'propagated']);
        $autoMatched = DB::table('bulk_musicbrainz_run_items')
            ->join('listens', 'listens.id', '=', 'bulk_musicbrainz_run_items.listen_id')
            ->where('bulk_musicbrainz_run_items.run_id', $bulkRun?->id)
            ->where('bulk_musicbrainz_run_items.status', 'matched')
            ->where('listens.user_id', $request->user()->id)
            ->orderBy('bulk_musicbrainz_run_items.id')
            ->paginate(25, ['listens.id', 'listens.track_name', 'listens.artist_name', 'listens.release_name', 'listens.recording_mbid'], 'matched_page')
            ->withQueryString();

        return Inertia::render('listens/pending-musicbrainz', [
            'pending' => $pending, 'bulkRun' => $bulkRun, 'autoMatched' => $autoMatched,
        ]);
    }

    public function approve(Request $request, int $pending): RedirectResponse
    {
        $values = $request->validate(['recording_mbid' => ['required', 'uuid']]);
        DB::transaction(function () use ($request, $pending, $values): void {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $row = DB::table('pending_musicbrainz_matches')->where('user_id', $request->user()->id)->where('id', $pending)->first();
            abort_if($row === null, 404);
            $listen = Listen::query()->where('user_id', $request->user()->id)->whereKey($row->listen_id)->firstOrFail();
            if ($listen->recording_mbid !== null || $listen->track_name !== $row->track_name
                || $listen->artist_name !== $row->artist_name || $listen->release_name !== $row->release_name) {
                throw ValidationException::withMessages(['recording_mbid' => 'This listen has changed since the search. Dismiss this result and search again.']);
            }
            $candidates = json_decode($row->candidates, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($candidates) || ! array_is_list($candidates)) {
                throw new \UnexpectedValueException('Invalid stored MusicBrainz candidates.');
            }
            $candidate = collect($candidates)->firstWhere('recording_mbid', $values['recording_mbid']);
            if ($candidate === null) {
                throw ValidationException::withMessages(['recording_mbid' => 'Choose one of the stored candidates.']);
            }
            $match = [
                'recording_mbid' => $candidate['recording_mbid'],
                'artist_name' => $candidate['artist_name'],
                'artist_mbids' => $candidate['artist_mbids'],
                'artist_names' => $candidate['artist_names'],
                'release_mbid' => null,
            ];
            ProcessRecordingAssociation::enqueue($request->user(), $listen, [
                'type' => 'approval', 'pending_id' => $row->id, 'match' => $match,
            ]);
        });

        return back();
    }

    public function destroy(Request $request, int $pending): RedirectResponse
    {
        DB::transaction(function () use ($request, $pending): void {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $row = DB::table('pending_musicbrainz_matches')->where('user_id', $request->user()->id)->where('id', $pending)->first();
            abort_if($row === null, 404);
            if (DB::table('recording_association_runs')->where('user_id', $request->user()->id)
                ->where('listen_id', $row->listen_id)->whereIn('status', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages(['recording_mbid' => 'Wait for the recording association to finish before dismissing this review.']);
            }
            $deleted = DB::table('pending_musicbrainz_matches')->where('user_id', $request->user()->id)->where('id', $pending)->delete();
            abort_if($deleted !== 1, 404);
        });

        return back();
    }
}
