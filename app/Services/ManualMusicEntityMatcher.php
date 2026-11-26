<?php

namespace App\Services;

use App\Models\Listen;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualMusicEntityMatcher
{
    /** @var array<string, ?\stdClass> */
    private array $cache = [];

    public function key(string $type, Listen $listen): string
    {
        return hash('sha256', json_encode(
            $type === 'artist' ? [$listen->artist_name] : [$listen->artist_name, $listen->release_name],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @return Builder<Listen> */
    public function related(string $type, Listen $listen): Builder
    {
        $query = Listen::query()->where('user_id', $listen->user_id);
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->whereRaw('BINARY artist_name = BINARY ?', [$listen->artist_name]);
            if ($type === 'album') {
                $query->whereRaw('BINARY release_name = BINARY ?', [$listen->release_name]);
            }
        } else {
            $query->where('artist_name', $listen->artist_name);
            if ($type === 'album') {
                $query->where('release_name', $listen->release_name);
            }
        }

        return $query;
    }

    public function saved(string $type, Listen $listen): ?\stdClass
    {
        $key = $this->key($type, $listen);
        $cacheKey = $listen->user_id.':'.$type.':'.$key;
        if (! array_key_exists($cacheKey, $this->cache)) {
            $this->cache[$cacheKey] = DB::table('music_entity_matches')
                ->where(['user_id' => $listen->user_id, 'type' => $type, 'entity_key' => $key])->first();
        }

        return $this->cache[$cacheKey];
    }

    /** @param array{mbid: string, name: string, source: string}|null $musicbrainz */
    public function remember(User $user, Listen $listen, string $type, ?array $musicbrainz = null, ?string $provider = null, ?string $catalogId = null): void
    {
        DB::transaction(function () use ($user, $listen, $type, $musicbrainz, $provider, $catalogId): void {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $current = Listen::query()->where('user_id', $user->id)->findOrFail($listen->id);
            if ($current->artist_name === 'Unknown artist' || trim($current->artist_name) === ''
                || ($type === 'album' && trim($current->release_name ?? '') === '')) {
                throw ValidationException::withMessages(['match' => 'This item lacks a reliable artist or album identity and cannot be matched as a group.']);
            }
            $key = ['user_id' => $user->id, 'type' => $type, 'entity_key' => $this->key($type, $current)];
            $row = DB::table('music_entity_matches')->where($key)->first();
            $catalogs = $row === null ? [] : json_decode($row->catalogs, true, 512, JSON_THROW_ON_ERROR);
            if ($provider !== null && $catalogId !== null) {
                $catalogs[$provider] = $catalogId;
            }
            DB::table('music_entity_matches')->updateOrInsert($key, [
                'mbid' => $musicbrainz['mbid'] ?? $row?->mbid,
                'name' => $musicbrainz['name'] ?? $row?->name,
                'catalogs' => json_encode($catalogs, JSON_THROW_ON_ERROR),
                'created_at' => $row === null ? now() : $row->created_at,
                'updated_at' => now(),
            ]);
            unset($this->cache[$user->id.':'.$type.':'.$key['entity_key']]);

            if ($musicbrainz !== null) {
                $table = $type === 'artist' ? 'music_artists' : 'music_releases';
                $values = ['mbid' => $musicbrainz['mbid'], 'name' => $musicbrainz['name']];
                if ($type === 'artist') {
                    $values['name_source'] = $musicbrainz['source'];
                }
                DB::table($table)->upsert([$values], ['mbid'], ['mbid']);
                $this->related($type, $current)->chunkById(500, function ($listens): void {
                    foreach ($listens as $candidate) {
                        $this->apply($candidate);
                    }
                });
            }
        });
    }

    public function forget(User $user, Listen $listen, string $type, string $kind): void
    {
        DB::transaction(function () use ($user, $listen, $type, $kind): void {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $key = ['user_id' => $user->id, 'type' => $type, 'entity_key' => $this->key($type, $listen)];
            $row = DB::table('music_entity_matches')->where($key)->first();
            if ($row === null) {
                return;
            }
            $catalogs = json_decode($row->catalogs, true, 512, JSON_THROW_ON_ERROR);
            if ($kind === 'musicbrainz') {
                $row->mbid = null;
                $row->name = null;
            } else {
                unset($catalogs[$kind]);
            }
            if ($row->mbid === null && $catalogs === []) {
                DB::table('music_entity_matches')->where($key)->delete();
            } else {
                DB::table('music_entity_matches')->where($key)->update([
                    'mbid' => $row->mbid, 'name' => $row->name,
                    'catalogs' => json_encode($catalogs, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
            }
            unset($this->cache[$user->id.':'.$type.':'.$key['entity_key']]);
        });
    }

    public function apply(Listen $listen): void
    {
        $metadata = $listen->track_metadata;
        $changed = false;
        foreach (['artist', 'album'] as $type) {
            $row = $this->saved($type, $listen);
            if ($row === null || $row->mbid === null) {
                continue;
            }
            $table = $type === 'artist' ? 'music_artists' : 'music_releases';
            $pivot = $type === 'artist' ? 'listen_artists' : 'listen_releases';
            $foreignKey = $type === 'artist' ? 'music_artist_id' : 'music_release_id';
            $id = DB::table($table)->where('mbid', $row->mbid)->value('id');
            if ($id === null) {
                throw new \LogicException('A saved MusicBrainz entity match is missing its local entity.');
            }
            $existing = DB::table($pivot)->where('listen_id', $listen->id)->pluck($foreignKey)->all();
            if (count($existing) !== 1 || (int) $existing[0] !== (int) $id) {
                DB::table($pivot)->where('listen_id', $listen->id)->delete();
                DB::table($pivot)->insert(['listen_id' => $listen->id, $foreignKey => $id]);
            }
            $field = $type === 'artist' ? 'artist_mbids' : 'release_mbid';
            $value = $type === 'artist' ? [$row->mbid] : $row->mbid;
            if (($metadata['additional_info'][$field] ?? null) !== $value) {
                $metadata['additional_info'][$field] = $value;
                $changed = true;
            }
        }
        if ($changed) {
            $listen->update(['track_metadata' => $metadata]);
        }
    }
}
