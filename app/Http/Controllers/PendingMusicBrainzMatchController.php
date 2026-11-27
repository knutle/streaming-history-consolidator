<?php

namespace App\Http\Controllers;

use App\Models\Listen;
use App\Services\BulkMusicBrainzMatcher;
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
            ->paginate(25, ['pending_musicbrainz_matches.*', 'listens.recording_mbid']);
        $pending->through(function ($row) {
            $row->candidates = json_decode($row->candidates, true, 512, JSON_THROW_ON_ERROR);
            $row->incomplete = (bool) $row->incomplete;

            return $row;
        });

        return Inertia::render('listens/pending-musicbrainz', ['pending' => $pending]);
    }

    public function approve(Request $request, int $pending, BulkMusicBrainzMatcher $matcher): RedirectResponse
    {
        $values = $request->validate(['recording_mbid' => ['required', 'uuid']]);
        DB::transaction(function () use ($request, $pending, $matcher, $values): void {
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
            if ($matcher->apply($request->user(), $listen, $match) === null) {
                throw ValidationException::withMessages(['recording_mbid' => 'This choice conflicts with a saved or linked match. Resolve the conflict first.']);
            }
            DB::table('pending_musicbrainz_matches')->where('id', $row->id)->delete();
        });

        return back();
    }

    public function destroy(Request $request, int $pending): RedirectResponse
    {
        DB::transaction(function () use ($request, $pending): void {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $deleted = DB::table('pending_musicbrainz_matches')->where('user_id', $request->user()->id)->where('id', $pending)->delete();
            abort_if($deleted !== 1, 404);
        });

        return back();
    }
}
