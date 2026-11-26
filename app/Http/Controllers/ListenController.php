<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportListensRequest;
use App\Models\Listen;
use App\Services\StreamingHistoryImporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            'musicbrainzEnabled' => (bool) config('musicbrainz.enabled'),
            'importResult' => $request->session()->get('import_result'),
        ]);
    }

    public function store(ImportListensRequest $request, StreamingHistoryImporter $importer): RedirectResponse
    {
        $result = $importer->import($request->user(), $request->validated('source'), $request->file('file')->getRealPath());

        return to_route('listens.index')->with('import_result', $result);
    }
}
