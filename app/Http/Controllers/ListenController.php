<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportListensRequest;
use App\Models\Listen;
use App\Services\ManualListenMatcher;
use App\Services\MusicBrainzMatcher;
use App\Services\StreamingHistoryImporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ListenController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', Rule::in(['spotify', 'apple'])],
            'match' => ['nullable', Rule::in(['matched', 'unmatched'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['listened_at', 'track_name', 'artist_name', 'release_name'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $query = Listen::query()->where('user_id', $request->user()->id);
        if (! empty($filters['search'])) {
            $search = mb_strtolower($filters['search']);
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(function ($query) use ($pattern) {
                $query->whereRaw("LOWER(track_name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(artist_name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(release_name) LIKE ? ESCAPE '!'", [$pattern]);
            });
        }
        if (! empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }
        if (($filters['match'] ?? null) === 'matched') {
            $query->whereNotNull('recording_mbid');
        } elseif (($filters['match'] ?? null) === 'unmatched') {
            $query->whereNull('recording_mbid');
        }
        if (! empty($filters['from'])) {
            $query->where('listened_at', '>=', CarbonImmutable::parse($filters['from'], 'UTC')->startOfDay()->getTimestamp());
        }
        if (! empty($filters['to'])) {
            $query->where('listened_at', '<', CarbonImmutable::parse($filters['to'], 'UTC')->addDay()->startOfDay()->getTimestamp());
        }

        $listens = $query->orderBy($filters['sort'] ?? 'listened_at', $filters['direction'] ?? 'desc')
            ->orderBy('id', 'desc')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('listens/index', [
            'listens' => $listens,
            'filters' => $filters,
        ]);
    }

    public function store(ImportListensRequest $request, StreamingHistoryImporter $importer): RedirectResponse
    {
        $result = $importer->import($request->user(), $request->validated('source'), $request->file('file')->getRealPath());

        return to_route('profile.edit')->with('import_result', $result);
    }

    public function show(Request $request, int $listen, MusicBrainzMatcher $matcher): Response
    {
        $values = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'artist' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $item = Listen::query()->where('user_id', $request->user()->id)->findOrFail($listen);
        $search = $values['q'] ?? mb_substr($item->track_name, 0, 12);
        $artist = $values['artist'] ?? '';
        $page = (int) ($values['page'] ?? 1);
        $candidates = $item->recording_mbid === null ? $matcher->search($search, $artist, $page) : ['results' => [], 'hasMore' => false];
        $related = Listen::query()->where('user_id', $request->user()->id)
            ->where('id', '!=', $item->id)
            ->when($item->recording_mbid !== null,
                fn ($query) => $query->where('recording_mbid', $item->recording_mbid),
                fn ($query) => $query->where('track_name', $item->track_name)->where('artist_name', $item->artist_name))
            ->orderByDesc('listened_at')->limit(10)->get();

        return Inertia::render('listens/show', [
            'listen' => $item,
            'related' => $related,
            'musicbrainzEnabled' => (bool) config('musicbrainz.enabled'),
            'search' => $search,
            'artistSearch' => $artist,
            'searchPage' => $page,
            'hasMore' => $candidates['hasMore'],
            'suggestions' => $candidates['results'],
            'matchResult' => $request->session()->get('match_result'),
        ]);
    }

    public function match(Request $request, int $listen, MusicBrainzMatcher $matcher, ManualListenMatcher $manualMatcher): RedirectResponse
    {
        $item = Listen::query()->where('user_id', $request->user()->id)->whereNull('recording_mbid')->findOrFail($listen);
        $values = $request->validate([
            'recording_mbid' => ['required', 'uuid'],
            'track_name' => ['required', 'string', 'max:255'],
            'artist_name' => ['required', 'string', 'max:255'],
        ]);
        $match = $matcher->recording($values['recording_mbid'], $values['track_name'], $values['artist_name']);
        if ($match === null) {
            throw ValidationException::withMessages(['recording_mbid' => 'The selected recording is unavailable in the local MusicBrainz database.']);
        }

        $count = $manualMatcher->remember($request->user(), $item, $match);

        return to_route('listens.show', $item->id)->with('match_result', $count);
    }

    public function destroy(Request $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            Listen::query()->where('user_id', $request->user()->id)->delete();
            DB::table('import_batches')->where('user_id', $request->user()->id)->delete();
        });

        return to_route('profile.edit');
    }
}
