<?php

namespace App\Http\Controllers;

use App\Models\Listen;
use App\Services\CatalogMatcher;
use App\Services\ManualMusicEntityMatcher;
use App\Services\MusicBrainzMatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MusicEntityController extends Controller
{
    public function artists(Request $request): Response
    {
        return $this->index($request, 'artist');
    }

    public function albums(Request $request): Response
    {
        return $this->index($request, 'album');
    }

    private function index(Request $request, string $type): Response
    {
        $values = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'match' => ['nullable', Rule::in(['matched', 'unmatched'])],
        ]);
        $pivot = $type === 'artist' ? 'listen_artists' : 'listen_releases';
        $matched = "SUM(CASE WHEN EXISTS (SELECT 1 FROM {$pivot} WHERE {$pivot}.listen_id = listens.id) THEN 1 ELSE 0 END)";
        $binary = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
        $artistColumn = $binary ? 'BINARY artist_name' : 'artist_name';
        $releaseColumn = $binary ? 'BINARY release_name' : 'release_name';
        $query = DB::table('listens')->where('user_id', $request->user()->id)
            ->selectRaw("{$artistColumn} as artist_name, MIN(id) as listen_id, COUNT(*) as listen_count, {$matched} as matched_count")
            ->groupByRaw($artistColumn);
        if ($type === 'album') {
            $query->whereNotNull('release_name')->where('release_name', '!=', '')
                ->selectRaw("{$releaseColumn} as release_name")->groupByRaw($releaseColumn)->orderBy('release_name');
        }
        if (! empty($values['search'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($values['search'])).'%';
            $column = $type === 'artist' ? 'artist_name' : 'release_name';
            $query->whereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
        }
        if (($values['match'] ?? null) === 'matched') {
            $query->havingRaw("{$matched} > 0");
        } elseif (($values['match'] ?? null) === 'unmatched') {
            $query->havingRaw("{$matched} < COUNT(*)");
        }
        $entities = $query->orderBy('artist_name')->paginate(25)->withQueryString();

        return Inertia::render('music-entities/index', ['type' => $type, 'entities' => $entities, 'filters' => $values]);
    }

    public function manage(Request $request, string $type, int $listen, ManualMusicEntityMatcher $manual, MusicBrainzMatcher $matcher): Response
    {
        $item = $this->item($request, $type, $listen);
        $values = $request->validate(['q' => ['nullable', 'string', 'max:255'], 'page' => ['nullable', 'integer', 'min:1', 'max:1000']]);
        $search = $values['q'] ?? '';
        $page = (int) ($values['page'] ?? 1);
        $results = $matcher->searchEntity($type, $search, $page);
        $row = $manual->saved($type, $item);
        $pivot = $type === 'artist' ? 'listen_artists' : 'listen_releases';
        $table = $type === 'artist' ? 'music_artists' : 'music_releases';
        $foreignKey = $type === 'artist' ? 'music_artist_id' : 'music_release_id';
        $matched = $manual->related($type, $item)->join($pivot, "{$pivot}.listen_id", '=', 'listens.id')
            ->join($table, "{$table}.id", '=', "{$pivot}.{$foreignKey}")
            ->distinct()->orderBy("{$table}.mbid")->limit(30)->get(["{$table}.mbid", "{$table}.name"]);
        $related = $manual->related($type, $item)->orderByDesc('listened_at')->orderByDesc('id')
            ->paginate(25, ['listens.*'], 'listen_page')->withQueryString();

        return Inertia::render('music-entities/manage', [
            'type' => $type,
            'item' => ['listen_id' => $item->id, 'artist_name' => $item->artist_name, 'release_name' => $item->release_name],
            'canMatch' => $item->artist_name !== 'Unknown artist' && trim($item->artist_name) !== ''
                && ($type !== 'album' || trim($item->release_name ?? '') !== ''),
            'saved' => $row === null ? null : ['mbid' => $row->mbid, 'name' => $row->name,
                'catalogs' => json_decode($row->catalogs, true, 512, JSON_THROW_ON_ERROR)],
            'matched' => $matched,
            'listens' => $related,
            'search' => $search,
            'searchPage' => $page,
            'suggestions' => $results['results'],
            'hasMore' => $results['hasMore'],
            'musicbrainzEnabled' => (bool) config('musicbrainz.enabled'),
            'entityResult' => $request->session()->get('entity_result'),
        ]);
    }

    public function matchMusicBrainz(Request $request, string $type, int $listen, MusicBrainzMatcher $matcher, ManualMusicEntityMatcher $manual): RedirectResponse
    {
        $item = $this->item($request, $type, $listen);
        $values = $request->validate(['mbid' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:255']]);
        $match = $matcher->entity($type, $values['mbid'], $values['name']);
        if ($match === null) {
            throw ValidationException::withMessages(['mbid' => 'The selected item is unavailable in the local MusicBrainz database.']);
        }
        $manual->remember($request->user(), $item, $type, musicbrainz: $match);

        return to_route('music-entities.manage', ['type' => $type, 'listen' => $listen])->with('entity_result', 'MusicBrainz match saved.');
    }

    public function matchCatalog(Request $request, string $type, int $listen, CatalogMatcher $matcher, ManualMusicEntityMatcher $manual): RedirectResponse
    {
        $item = $this->item($request, $type, $listen);
        $values = $request->validate([
            'provider' => ['required', Rule::in(['apple', 'spotify'])],
            'catalog_url' => ['required', 'string', 'max:2048'],
        ]);
        $id = $matcher->entityIdentifier($type, $values['provider'], $values['catalog_url']);
        if ($id === null) {
            throw ValidationException::withMessages(['catalog_url' => 'Enter a valid '.$type.' link for the selected catalog.']);
        }
        $manual->remember($request->user(), $item, $type, provider: $values['provider'], catalogId: $id);

        return to_route('music-entities.manage', ['type' => $type, 'listen' => $listen])->with('entity_result', 'Catalog match saved.');
    }

    public function removeMatch(Request $request, string $type, int $listen, string $kind, ManualMusicEntityMatcher $manual): RedirectResponse
    {
        $item = $this->item($request, $type, $listen);
        abort_unless(in_array($kind, ['musicbrainz', 'apple', 'spotify'], true), 404);
        $manual->forget($request->user(), $item, $type, $kind);

        return to_route('music-entities.manage', ['type' => $type, 'listen' => $listen])->with('entity_result', 'Saved choice removed. Existing listen associations were not changed.');
    }

    private function item(Request $request, string $type, int $listen): Listen
    {
        abort_unless(in_array($type, ['artist', 'album'], true), 404);
        $item = Listen::query()->where('user_id', $request->user()->id)->findOrFail($listen);
        abort_if($type === 'album' && trim($item->release_name ?? '') === '', 404);

        return $item;
    }

    public function artist(Request $request, string $mbid): Response
    {
        return $this->show($request, $mbid, 'artist');
    }

    public function album(Request $request, string $mbid): Response
    {
        return $this->show($request, $mbid, 'album');
    }

    private function show(Request $request, string $mbid, string $type): Response
    {
        $table = $type === 'artist' ? 'music_artists' : 'music_releases';
        $pivot = $type === 'artist' ? 'listen_artists' : 'listen_releases';
        $foreignKey = $type === 'artist' ? 'music_artist_id' : 'music_release_id';
        $entity = DB::table($table)->where('mbid', $mbid)->first();
        abort_if($entity === null, 404);
        $query = Listen::query()->join($pivot, "{$pivot}.listen_id", '=', 'listens.id')
            ->where("{$pivot}.{$foreignKey}", $entity->id)
            ->where('listens.user_id', $request->user()->id);
        abort_unless((clone $query)->exists(), 404);

        $tracks = (clone $query)->select('listens.track_name', 'listens.artist_name', 'listens.recording_mbid')
            ->selectRaw('MIN(listens.id) as listen_id, COUNT(*) as listen_count')
            ->groupBy('listens.track_name', 'listens.artist_name', 'listens.recording_mbid')
            ->orderBy('listens.track_name')->limit(100)->get();
        $listens = (clone $query)->select('listens.*')->orderByDesc('listens.listened_at')
            ->orderByDesc('listens.id')->paginate(25)->withQueryString();

        return Inertia::render('music-entities/show', [
            'entity' => ['mbid' => $entity->mbid, 'name' => $entity->name, 'type' => $type,
                'nameSource' => $type === 'artist' ? $entity->name_source : null],
            'tracks' => $tracks,
            'listens' => $listens,
        ]);
    }
}
