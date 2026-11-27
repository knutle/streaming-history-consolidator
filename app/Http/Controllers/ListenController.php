<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportListensRequest;
use App\Jobs\ProcessRecordingAssociation;
use App\Models\Listen;
use App\Services\AlbumArtwork;
use App\Services\CatalogMatcher;
use App\Services\CatalogRecordingGroups;
use App\Services\Isrc;
use App\Services\ListenFilters;
use App\Services\MusicBrainzApi;
use App\Services\MusicBrainzMatcher;
use App\Services\SpotifyCatalogLookup;
use App\Services\StreamingHistoryImporter;
use App\Services\StreamingHistoryUpload;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ListenController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('settings/import-history', [
            'musicbrainzEnabled' => (bool) config('musicbrainz.enabled'),
            'importResult' => $request->session()->get('import_result'),
            'canImport' => ! ($request->user() instanceof MustVerifyEmail) || $request->user()->hasVerifiedEmail(),
            'cancelRequested' => $request->query->has('cancel_requested') ? max(0, $request->integer('cancel_requested')) : null,
            'cancelUnavailable' => max(0, $request->integer('cancel_unavailable')),
            'deletedImports' => $request->query->has('deleted_imports') ? max(0, $request->integer('deleted_imports')) : null,
        ]);
    }

    public function index(Request $request, ListenFilters $listenFilters): Response
    {
        $filters = $listenFilters->validate($request);
        $query = $listenFilters->query($request->user()->id, $filters);

        $listens = $query->orderBy($filters['sort'] ?? 'listened_at', ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc')
            ->orderBy('id', 'desc')
            ->paginate(25)
            ->withQueryString();
        $ids = $listens->getCollection()->modelKeys();
        $artists = DB::table('listen_artists')->join('music_artists', 'music_artists.id', '=', 'listen_artists.music_artist_id')
            ->whereIn('listen_artists.listen_id', $ids)->get(['listen_artists.listen_id', 'music_artists.mbid'])->groupBy('listen_id');
        $albums = DB::table('listen_releases')->join('music_releases', 'music_releases.id', '=', 'listen_releases.music_release_id')
            ->whereIn('listen_releases.listen_id', $ids)->get(['listen_releases.listen_id', 'music_releases.mbid'])->groupBy('listen_id');
        $listens->getCollection()->each(function ($listen) use ($artists, $albums) {
            $listen->setAttribute('matched_artist_mbids', $artists->get($listen->id, collect())->pluck('mbid')->all());
            $listen->setAttribute('matched_album_mbids', $albums->get($listen->id, collect())->pluck('mbid')->all());
        });
        $selection = DB::table('bulk_musicbrainz_selections')->where('user_id', $request->user()->id)->first();
        $selectionNeedsClear = $selection !== null && $selection->filter_hash !== $listenFilters->selectionHash($filters);
        if ($selectionNeedsClear) {
            $selection = null;
        }
        $selectedIds = [];
        $selectedCount = 0;
        if ($selection !== null) {
            $items = DB::table('bulk_musicbrainz_selection_items')->where('user_id', $request->user()->id)->select('listen_id');
            $selected = $listenFilters->query($request->user()->id, $filters)->whereNull('recording_mbid');
            if ($selection->all_selected) {
                $selected->whereNotIn('id', $items);
            } else {
                $selected->whereIn('id', $items);
            }
            $selectedCount = (clone $selected)->count();
            $selectedIds = (clone $selected)->whereKey($ids)->pluck('id')->all();
        }

        return Inertia::render('listens/index', [
            'listens' => $listens,
            'filters' => $filters,
            'bulkSelection' => ['ids' => $selectedIds, 'count' => $selectedCount, 'all' => (bool) ($selection->all_selected ?? false),
                'needsClear' => $selectionNeedsClear],
            'bulkRun' => DB::table('bulk_musicbrainz_runs')->where('user_id', $request->user()->id)
                ->orderByDesc('id')->first(['id', 'status', 'total', 'processed', 'matched', 'unmatched', 'skipped', 'propagated', 'error', 'retry_at']),
            'pendingMusicBrainzCount' => DB::table('pending_musicbrainz_matches')->where('user_id', $request->user()->id)->count(),
        ]);
    }

    public function store(ImportListensRequest $request, StreamingHistoryImporter $importer, StreamingHistoryUpload $upload): RedirectResponse
    {
        $executionLimit = ini_get('max_execution_time');
        if ((int) $executionLimit > 0 && (int) $executionLimit < 7200
            && (! function_exists('set_time_limit') || ! set_time_limit(7200))) {
            throw ValidationException::withMessages([
                $request->hasFile('files') ? 'files' : 'file' => 'The PHP execution time limit could not be extended for this import. Check the server configuration.',
            ]);
        }

        if ($request->hasFile('files')) {
            $files = $request->file('files');
            if (! is_array($files)) {
                throw new \LogicException('Validated import files must be an array.');
            }
            $result = $upload->import($request->user(), array_values($files));
        } else {
            $result = $importer->import($request->user(), $request->validated('source'), $request->file('file')->getRealPath());
        }

        return to_route('listens.create')->with('import_result', $result);
    }

    public function show(Request $request, int $listen, MusicBrainzMatcher $matcher, CatalogMatcher $catalogMatcher, CatalogRecordingGroups $groups, MusicBrainzApi $api, AlbumArtwork $artwork): Response
    {
        $values = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'artist' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'merge_q' => ['nullable', 'string', 'max:255'],
            'search_source' => ['nullable', Rule::in(['local', 'api'])],
        ]);
        $item = Listen::query()->where('user_id', $request->user()->id)->findOrFail($listen);
        $search = $values['q'] ?? mb_substr($item->track_name, 0, 12);
        $artist = $values['artist'] ?? '';
        $page = (int) ($values['page'] ?? 1);
        $source = $values['search_source'] ?? 'local';
        $searchResult = null;
        $searchRecordings = function () use (&$searchResult, $item, $source, $api, $matcher, $search, $artist, $page): array {
            if ($searchResult !== null) {
                return $searchResult;
            }
            $candidates = ['results' => [], 'hasMore' => false];
            $apiError = null;
            if ($item->recording_mbid === null) {
                if ($source === 'api') {
                    try {
                        $candidates = $api->search($search, $artist, $page);
                    } catch (ValidationException $exception) {
                        $apiError = $exception->errors()['musicbrainz_api'][0];
                    }
                } else {
                    $candidates = $matcher->search($search, $artist, $page);
                }
            }

            return $searchResult = ['candidates' => $candidates, 'error' => $apiError];
        };
        $related = Listen::query()->where('user_id', $request->user()->id)
            ->where('id', '!=', $item->id)
            ->when($item->recording_mbid !== null,
                fn ($query) => $query->where('recording_mbid', $item->recording_mbid),
                fn ($query) => $query->where('track_name', $item->track_name)->where('artist_name', $item->artist_name))
            ->orderByDesc('listened_at')->limit(10)->get();
        $members = $groups->members($item);
        $linkedCatalogs = $groups->evidence($item);
        $mergeSearch = trim($values['merge_q'] ?? '');
        $mergeCandidates = [];
        if ($mergeSearch !== '' && $groups->native($item) !== null) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($mergeSearch)).'%';
            $seen = [];
            $query = Listen::query()->where('user_id', $request->user()->id)->where('source', '!=', $item->source)
                ->where(function ($query) use ($pattern): void {
                    $query->whereRaw("LOWER(track_name) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(artist_name) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(release_name) LIKE ? ESCAPE '!'", [$pattern]);
                })->orderByDesc('id');
            foreach ($query->cursor() as $candidate) {
                $catalog = $groups->native($candidate);
                if ($catalog === null) {
                    continue;
                }
                $key = $candidate->source.':'.$catalog['id'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $mergeCandidates[] = ['id' => $candidate->id, 'source' => $candidate->source,
                    'track_name' => $candidate->track_name, 'artist_name' => $candidate->artist_name,
                    'release_name' => $candidate->release_name, 'recording_mbid' => $candidate->recording_mbid,
                    'catalog_id' => $catalog['id']];
                if (count($mergeCandidates) === 30) {
                    break;
                }
            }
        }

        return Inertia::render('listens/show', [
            'artworkEnabled' => (bool) config('artwork.enabled'),
            'artwork' => config('artwork.enabled') ? Inertia::defer(fn () => $artwork->forListen($item), 'artwork') : null,
            'artworkResult' => $request->session()->get('artwork_result'),
            'listen' => $item,
            'related' => $related,
            'musicbrainzEnabled' => (bool) config('musicbrainz.enabled'),
            'searchSource' => $source,
            'apiError' => fn () => $searchRecordings()['error'],
            'search' => $search,
            'artistSearch' => $artist,
            'searchPage' => $page,
            'hasMore' => fn () => $searchRecordings()['candidates']['hasMore'],
            'suggestions' => fn () => $searchRecordings()['candidates']['results'],
            'matchResult' => $request->session()->get('match_result'),
            'recordingAssociation' => DB::table('recording_association_runs')
                ->where('user_id', $request->user()->id)->where('listen_id', $item->id)
                ->orderByDesc('id')->first(['status', 'matched', 'error']),
            'catalogResult' => $request->session()->get('catalog_result'),
            'mergeResult' => $request->session()->get('merge_result'),
            'mergeSearch' => $mergeSearch,
            'mergeAvailable' => $groups->native($item) !== null,
            'mergeCandidates' => $mergeCandidates,
            'linkedCatalogs' => $members,
            'linkedEvidence' => $linkedCatalogs,
            'catalogs' => $catalogMatcher->forListen($item, includeRelated: true),
            'musicBrainzSeed' => $item->recording_mbid === null ? $catalogMatcher->musicBrainzSeed($item, $linkedCatalogs) : null,
            'matchedArtists' => DB::table('music_artists')
                ->join('listen_artists', 'listen_artists.music_artist_id', '=', 'music_artists.id')
                ->where('listen_artists.listen_id', $item->id)->orderBy('music_artists.id')
                ->get(['music_artists.mbid', 'music_artists.name']),
            'matchedAlbums' => DB::table('music_releases')
                ->join('listen_releases', 'listen_releases.music_release_id', '=', 'music_releases.id')
                ->where('listen_releases.listen_id', $item->id)
                ->get(['music_releases.mbid', 'music_releases.name']),
        ]);
    }

    public function merge(Request $request, int $listen, CatalogRecordingGroups $groups): RedirectResponse
    {
        $item = Listen::query()->where('user_id', $request->user()->id)->findOrFail($listen);
        $values = $request->validate(['other_listen' => ['required', 'integer', 'min:1']]);
        $other = Listen::query()->where('user_id', $request->user()->id)->whereKey($values['other_listen'])->firstOrFail();
        $groups->merge($request->user(), $item, $other);

        return to_route('listens.show', $listen)->with('merge_result', 'Catalog items linked. Listening events remain separate.');
    }

    public function unlink(Request $request, int $listen, int $member, CatalogRecordingGroups $groups): RedirectResponse
    {
        $item = Listen::query()->where('user_id', $request->user()->id)->findOrFail($listen);
        $groups->unlink($request->user(), $item, $member);

        return to_route('listens.show', $listen)->with('merge_result', 'Catalog item unlinked. Existing listening events and MusicBrainz matches were not changed.');
    }

    public function matchCatalog(Request $request, int $listen, CatalogMatcher $matcher, CatalogRecordingGroups $groups): RedirectResponse
    {
        $item = Listen::query()->where('user_id', $request->user()->id)->findOrFail($listen);
        $values = $request->validate([
            'provider' => ['required', Rule::in(['apple', 'spotify'])],
            'catalog_url' => ['required', 'string', 'max:2048'],
            'catalog_track' => ['required', 'string', 'max:255'],
            'catalog_artist' => ['required', 'string', 'max:255'],
            'catalog_album' => ['nullable', 'string', 'max:255'],
            'catalog_duration' => ['nullable', 'integer', 'min:1', 'max:86400000'],
            'catalog_isrc' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                if (Isrc::normalize($value) === null) {
                    $fail('Enter a valid 12-character ISRC.');
                }
            }],
        ]);
        $id = $matcher->identifier($values['provider'], $values['catalog_url']);
        if ($id === null) {
            throw ValidationException::withMessages(['catalog_url' => 'Enter a valid track link for the selected catalog.']);
        }
        $match = $matcher->match($values['provider'], $id, $values['catalog_track'], $values['catalog_artist'],
            $values['catalog_album'] ?? null, isset($values['catalog_duration']) ? (int) $values['catalog_duration'] : null, 'manual',
            isset($values['catalog_isrc']) ? Isrc::normalize($values['catalog_isrc']) : null);
        $count = DB::transaction(function () use ($matcher, $request, $item, $match, $groups) {
            $count = $matcher->remember($request->user(), $item, $match);
            $groups->syncEvidence($request->user());

            return $count;
        });

        return to_route('listens.show', $item->id)->with('catalog_result', $count);
    }

    public function lookupSpotify(Request $request, int $listen, CatalogMatcher $matcher, SpotifyCatalogLookup $lookup): JsonResponse
    {
        Listen::query()->where('user_id', $request->user()->id)->findOrFail($listen);
        $values = $request->validate(['catalog_url' => ['required', 'string', 'max:2048']]);
        $id = $matcher->identifier('spotify', $values['catalog_url']);
        if ($id === null) {
            throw ValidationException::withMessages(['catalog_url' => 'Enter a valid Spotify track link or URI.']);
        }

        return response()->json($lookup->track($id));
    }

    public function match(Request $request, int $listen, MusicBrainzMatcher $matcher, MusicBrainzApi $api): RedirectResponse
    {
        $item = Listen::query()->where('user_id', $request->user()->id)->whereNull('recording_mbid')->findOrFail($listen);
        $values = $request->validate([
            'recording_mbid' => ['required', 'uuid'],
            'track_name' => ['required', 'string', 'max:255'],
            'artist_name' => ['required', 'string', 'max:255'],
            'search_source' => ['nullable', Rule::in(['local', 'api'])],
        ]);
        $match = ($values['search_source'] ?? 'local') === 'api'
            ? $api->recording($values['recording_mbid'], $values['track_name'], $values['artist_name'])
            : $matcher->recording($values['recording_mbid'], $values['track_name'], $values['artist_name']);
        if ($match === null) {
            throw ValidationException::withMessages(['recording_mbid' => 'The selected recording is unavailable in the local MusicBrainz database.']);
        }

        DB::transaction(function () use ($request, $item, $match, $values): void {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $current = Listen::query()->where('user_id', $request->user()->id)
                ->whereKey($item->id)->whereNull('recording_mbid')->first();
            if ($current === null || $current->track_name !== $item->track_name
                || $current->artist_name !== $item->artist_name || $current->release_name !== $item->release_name) {
                throw ValidationException::withMessages(['recording_mbid' => 'This listen has changed, been identified, or deleted.']);
            }
            ProcessRecordingAssociation::enqueue($request->user(), $current, [
                'type' => 'manual', 'match' => $match, 'recording_name' => $values['track_name'],
            ]);
        });

        return to_route('listens.show', $item->id);
    }

    public function destroy(Request $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            if (DB::table('import_runs')->where('user_id', $request->user()->id)->whereIn('status', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages(['history' => 'Wait for your active imports to finish before deleting listening history.']);
            }
            Listen::query()->where('user_id', $request->user()->id)->delete();
            DB::table('import_batches')->where('user_id', $request->user()->id)->delete();
        });

        return to_route('listens.create');
    }
}
