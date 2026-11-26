<?php

namespace App\Http\Controllers;

use App\Models\Listen;
use App\Services\CatalogMatcher;
use App\Services\ManualListenMatcher;
use App\Services\MusicBrainzMatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ManualMatchController extends Controller
{
    public function index(Request $request, ManualListenMatcher $recordingMatcher, CatalogMatcher $catalogMatcher): Response
    {
        $userId = $request->user()->id;
        $recordings = DB::table('listen_matches')->where('user_id', $userId)
            ->orderByDesc('id')->paginate(25, ['*'], 'recording_page');
        $catalogs = DB::table('catalog_matches')->where('user_id', $userId)
            ->orderByDesc('id')->paginate(25, ['*'], 'catalog_page');

        $recordingKeys = $recordings->getCollection()->keyBy('song_key');
        $catalogKeys = $catalogs->getCollection()->keyBy('song_key');
        $related = ['recording' => [], 'catalog' => []];
        if ($recordingKeys->isNotEmpty() || $catalogKeys->isNotEmpty()) {
            foreach (Listen::query()->where('user_id', $userId)->select(['id', 'track_name', 'artist_name', 'release_name'])->cursor() as $listen) {
                $recordingKey = $recordingMatcher->key($listen->track_name, $listen->artist_name);
                $catalogKey = $catalogMatcher->key($listen->track_name, $listen->artist_name, $listen->release_name);
                if ($recordingKeys->has($recordingKey) && ! isset($related['recording'][$recordingKey])) {
                    $related['recording'][$recordingKey] = $listen;
                }
                if ($catalogKey !== null && $catalogKeys->has($catalogKey) && ! isset($related['catalog'][$catalogKey])) {
                    $related['catalog'][$catalogKey] = $listen;
                }
                if (count($related['recording']) === $recordingKeys->count() && count($related['catalog']) === $catalogKeys->count()) {
                    break;
                }
            }
        }

        foreach (['recording' => $recordings, 'catalog' => $catalogs] as $type => $paginator) {
            $paginator->through(function ($row) use ($related, $type) {
                $match = json_decode($row->match, true, 512, JSON_THROW_ON_ERROR);
                $listen = $related[$type][$row->song_key] ?? null;

                return [
                    'id' => $row->id,
                    'source_track' => $match['source_track'] ?? $listen?->track_name,
                    'source_artist' => $match['source_artist'] ?? $listen?->artist_name,
                    'source_album' => $match['source_album'] ?? $listen?->release_name,
                    'listen_id' => $listen?->id,
                    'match' => $match,
                ];
            });
        }

        return Inertia::render('matches/index', [
            'recordings' => $recordings->withQueryString(),
            'catalogs' => $catalogs->withQueryString(),
        ]);
    }

    public function edit(Request $request, int $match, MusicBrainzMatcher $matcher, ManualListenMatcher $recordingMatcher): Response
    {
        $row = DB::table('listen_matches')->where('user_id', $request->user()->id)->where('id', $match)->first();
        abort_if($row === null, 404);
        $saved = json_decode($row->match, true, 512, JSON_THROW_ON_ERROR);
        if (! isset($saved['source_track'], $saved['source_artist'])) {
            $saved = array_replace($saved, $this->legacySource($request->user()->id, $row->song_key, $recordingMatcher));
        }
        $values = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'artist' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $search = $values['q'] ?? mb_substr($saved['recording_name'] ?? $saved['source_track'] ?? '', 0, 12);
        $artist = $values['artist'] ?? '';
        $page = (int) ($values['page'] ?? 1);
        $results = $matcher->search($search, $artist, $page);

        return Inertia::render('matches/edit', [
            'id' => $match,
            'saved' => $saved,
            'search' => $search,
            'artist' => $artist,
            'page' => $page,
            'suggestions' => $results['results'],
            'hasMore' => $results['hasMore'],
            'musicbrainzEnabled' => (bool) config('musicbrainz.enabled'),
        ]);
    }

    public function update(Request $request, int $match, MusicBrainzMatcher $matcher, ManualListenMatcher $recordingMatcher): RedirectResponse
    {
        abort_unless(DB::table('listen_matches')->where('user_id', $request->user()->id)->where('id', $match)->exists(), 404);
        $values = $request->validate([
            'recording_mbid' => ['required', 'uuid'],
            'track_name' => ['required', 'string', 'max:255'],
            'artist_name' => ['required', 'string', 'max:255'],
        ]);
        $recording = $matcher->recording($values['recording_mbid'], $values['track_name'], $values['artist_name']);
        if ($recording === null) {
            throw ValidationException::withMessages(['recording_mbid' => 'The selected recording is unavailable in the local MusicBrainz database.']);
        }

        DB::transaction(function () use ($request, $match, $recording, $values, $recordingMatcher): void {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $row = DB::table('listen_matches')->where('user_id', $request->user()->id)->where('id', $match)->first();
            abort_if($row === null, 404);
            $saved = json_decode($row->match, true, 512, JSON_THROW_ON_ERROR);
            if (! isset($saved['source_track'], $saved['source_artist'])) {
                $saved = array_replace($saved, $this->legacySource($request->user()->id, $row->song_key, $recordingMatcher));
            }
            $recording['source_track'] = $saved['source_track'] ?? null;
            $recording['source_artist'] = $saved['source_artist'] ?? null;
            $recording['recording_name'] = $values['track_name'];
            DB::table('listen_matches')->where('user_id', $request->user()->id)->where('id', $match)
                ->update(['match' => json_encode($recording, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        });

        return to_route('matches.index');
    }

    /** @return array{source_track?: string, source_artist?: string} */
    private function legacySource(int $userId, string $key, ManualListenMatcher $matcher): array
    {
        foreach (Listen::query()->where('user_id', $userId)->select(['id', 'track_name', 'artist_name'])->cursor() as $listen) {
            if ($matcher->key($listen->track_name, $listen->artist_name) === $key) {
                return ['source_track' => $listen->track_name, 'source_artist' => $listen->artist_name];
            }
        }

        return [];
    }

    public function destroy(Request $request, string $type, int $match): RedirectResponse
    {
        abort_unless(in_array($type, ['recording', 'catalog'], true), 404);
        $table = $type === 'recording' ? 'listen_matches' : 'catalog_matches';
        DB::transaction(function () use ($request, $table, $match): void {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $deleted = DB::table($table)->where('user_id', $request->user()->id)->where('id', $match)->delete();
            abort_if($deleted !== 1, 404);
        });

        return to_route('matches.index');
    }
}
