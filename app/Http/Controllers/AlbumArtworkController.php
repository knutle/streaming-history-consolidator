<?php

namespace App\Http\Controllers;

use App\Models\Listen;
use App\Services\AlbumArtwork;
use App\Services\ManualMusicEntityMatcher;
use App\Services\MusicBrainzApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AlbumArtworkController extends Controller
{
    public function search(Request $request, int $listen, MusicBrainzApi $api): JsonResponse
    {
        $this->item($request, $listen);
        $values = $request->validate([
            'q' => ['required', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        return response()->json($api->searchEntity('album', $values['q'], (int) ($values['page'] ?? 1)));
    }

    public function store(Request $request, int $listen, MusicBrainzApi $api, ManualMusicEntityMatcher $groups): RedirectResponse
    {
        $item = $this->item($request, $listen);
        $values = $request->validate([
            'mbid' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $release = $api->entity('album', $values['mbid'], $values['name']);
        $key = ['user_id' => $request->user()->id, 'entity_key' => $groups->key('album', $item)];
        DB::table('album_artwork_choices')->upsert([
            $key + ['release_mbid' => $release['mbid'], 'created_at' => now(), 'updated_at' => now()],
        ], ['user_id', 'entity_key'], ['release_mbid', 'updated_at']);

        return back()->with('artwork_result', 'Artwork choice saved. Music matches were not changed.');
    }

    public function destroy(Request $request, int $listen, ManualMusicEntityMatcher $groups): RedirectResponse
    {
        $item = $this->item($request, $listen);
        DB::table('album_artwork_choices')->where('user_id', $request->user()->id)
            ->where('entity_key', $groups->key('album', $item))->delete();

        return back()->with('artwork_result', 'Artwork choice reset. Music matches were not changed.');
    }

    public function image(Request $request, string $mbid, AlbumArtwork $artwork): StreamedResponse
    {
        abort_unless(config('artwork.enabled') && Str::isUuid($mbid), 404);
        $mbid = strtolower($mbid);
        abort_unless(Storage::disk('local')->exists($artwork->path($mbid)), 404);
        $userId = $request->user()->id;
        $releaseMatches = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? "LOWER(JSON_UNQUOTE(JSON_EXTRACT(track_metadata, '$.additional_info.release_mbid'))) = ?"
            : "LOWER(JSON_EXTRACT(track_metadata, '$.additional_info.release_mbid')) = ?";
        $allowed = DB::table('album_artwork_choices')->where('user_id', $userId)->where('release_mbid', $mbid)->exists()
            || DB::table('listens')->join('listen_releases', 'listen_releases.listen_id', '=', 'listens.id')
                ->join('music_releases', 'music_releases.id', '=', 'listen_releases.music_release_id')
                ->where('listens.user_id', $userId)->where('music_releases.mbid', $mbid)->exists()
            || Listen::query()->where('user_id', $userId)
                ->whereRaw($releaseMatches, [$mbid])->exists();
        abort_unless($allowed, 404);

        return Storage::disk('local')->response($artwork->path($mbid), null, [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function item(Request $request, int $listen): Listen
    {
        abort_unless(config('artwork.enabled'), 404);
        $item = Listen::query()->where('user_id', $request->user()->id)->findOrFail($listen);
        abort_if(trim($item->release_name ?? '') === '', 404);

        return $item;
    }
}
