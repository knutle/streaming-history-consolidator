<?php

namespace App\Http\Controllers;

use App\Models\Listen;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MusicEntityController extends Controller
{
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
