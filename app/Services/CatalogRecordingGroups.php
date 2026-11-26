<?php

namespace App\Services;

use App\Models\Listen;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogRecordingGroups
{
    /** @var array<string, ?array{recording_mbid: string, artist_name: string, artist_mbids: list<string>, release_mbid: null}> */
    private array $matches = [];

    /** @var array<int, bool> */
    private array $loaded = [];

    public function __construct(private CatalogMatcher $catalogs, private MatchedMusicMetadata $metadata) {}

    private function key(string $provider, string $id): string
    {
        return hash('sha256', $provider.':'.$id);
    }

    /** @return array<string, mixed>|null */
    public function native(Listen $listen): ?array
    {
        $catalog = $listen->track_metadata['additional_info']['native_catalog']
            ?? $this->catalogs->forListen($listen, includeStored: false)[$listen->source] ?? null;
        if (is_array($catalog) && ($catalog['origin'] ?? null) !== 'import') {
            $catalog = null;
        }
        if (! is_array($catalog) || ! is_string($catalog['id'] ?? null) || ($catalog['provider'] ?? null) !== $listen->source
            || ! in_array($listen->source, ['apple', 'spotify'], true)
            || $this->catalogs->identifier($listen->source, $this->catalogs->url($listen->source, $catalog['id'])) !== $catalog['id']) {
            return null;
        }

        return $catalog;
    }

    public function groupId(Listen $listen): ?int
    {
        $catalog = $this->native($listen);
        if ($catalog === null) {
            return null;
        }
        $id = DB::table('catalog_recording_members')->where('user_id', $listen->user_id)
            ->where('catalog_key', $this->key($listen->source, $catalog['id']))->value('group_id');

        return $id === null ? null : (int) $id;
    }

    /** @return list<array{id: int, catalog: array<string, mixed>}> */
    public function members(Listen $listen): array
    {
        $groupId = $this->groupId($listen);
        if ($groupId === null) {
            return [];
        }
        $members = [];
        foreach (DB::table('catalog_recording_members')->where('user_id', $listen->user_id)->where('group_id', $groupId)->orderBy('id')->get() as $row) {
            $catalog = json_decode($row->catalog, true, 512, JSON_THROW_ON_ERROR);
            $members[] = ['id' => $row->id, 'catalog' => $this->catalogs->withMetadata($listen->user_id, $catalog)];
        }

        return $members;
    }

    /** @return list<array<string, mixed>> */
    public function evidence(Listen $listen): array
    {
        $members = $this->members($listen);
        $evidence = [];
        foreach ($members as $member) {
            $catalog = $member['catalog'];
            $evidence[$this->key($catalog['provider'], $catalog['id'])] = $catalog;
        }
        foreach ($members as $member) {
            foreach ($member['catalog']['related_catalogs'] ?? [] as $reference) {
                $key = $this->key($reference['provider'], $reference['id']);
                if (! isset($evidence[$key])) {
                    $evidence[$key] = $this->catalogs->withMetadata($listen->user_id, $reference + [
                        'track_name' => '', 'artist_name' => '', 'release_name' => null, 'duration_ms' => null,
                    ]);
                }
            }
        }

        return array_values($evidence);
    }

    /** @param (\Closure(): void)|null $checkAbort */
    public function syncEvidence(User $user, ?\Closure $checkAbort = null): void
    {
        DB::transaction(function () use ($user, $checkAbort): void {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $members = DB::table('catalog_recording_members')->where('user_id', $user->id)->get();
            if ($members->isEmpty()) {
                return;
            }
            $snapshots = [];
            foreach ($members as $member) {
                $catalog = json_decode($member->catalog, true, 512, JSON_THROW_ON_ERROR);
                $references = [];
                foreach ($catalog['related_catalogs'] ?? [] as $reference) {
                    $references[$this->key($reference['provider'], $reference['id'])] = $reference;
                }
                $snapshots[$member->catalog_key] = ['id' => $member->id, 'catalog' => $catalog, 'references' => $references];
            }
            $stored = [];
            Listen::query()->where('user_id', $user->id)->chunkById(500, function ($listens) use (&$snapshots, &$stored, $checkAbort): void {
                if ($checkAbort !== null) {
                    $checkAbort();
                }
                foreach ($listens as $listen) {
                    $native = $this->native($listen);
                    if ($native === null) {
                        continue;
                    }
                    $memberKey = $this->key($listen->source, $native['id']);
                    if (! isset($snapshots[$memberKey])) {
                        continue;
                    }
                    foreach ([$native, ...array_values($this->catalogs->forListen($listen, includeStored: false))] as $catalog) {
                        $provider = $catalog['provider'] ?? null;
                        $id = $catalog['id'] ?? null;
                        $origin = $catalog['origin'] ?? null;
                        if (! is_string($provider) || ! in_array($provider, ['apple', 'spotify'], true)
                            || ! is_string($id) || ! in_array($origin, ['import', 'manual'], true)
                            || $this->catalogs->identifier($provider, $this->catalogs->url($provider, $id)) !== $id) {
                            continue;
                        }
                        $key = $this->key($provider, $id);
                        $storageKey = $key.':'.$origin;
                        if (! isset($stored[$storageKey])) {
                            $stored[$storageKey] = $catalog;
                        } else {
                            foreach ($catalog as $field => $value) {
                                if ((! isset($stored[$storageKey][$field]) || $stored[$storageKey][$field] === '') && $value !== null && $value !== '') {
                                    $stored[$storageKey][$field] = $value;
                                }
                            }
                        }
                        if ($key !== $memberKey) {
                            $snapshots[$memberKey]['references'][$key] = compact('provider', 'id', 'origin');
                        }
                    }
                }
            });
            foreach ($stored as $catalog) {
                $this->catalogs->storeMetadata($user->id, $catalog, overwriteManual: false);
            }
            foreach ($snapshots as $snapshot) {
                $catalog = $snapshot['catalog'];
                $catalog['related_catalogs'] = array_values($snapshot['references']);
                if ($catalog === $snapshot['catalog']) {
                    continue;
                }
                $encoded = json_encode($catalog, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                DB::table('catalog_recording_members')->where('user_id', $user->id)->where('id', $snapshot['id'])
                    ->update(['catalog' => $encoded, 'updated_at' => now()]);
            }
        });
    }

    /** @return array{recording_mbid: string, artist_name: string, artist_mbids: list<string>, release_mbid: null}|null */
    public function remembered(int $userId, string $provider, ?string $id): ?array
    {
        if ($id === null) {
            return null;
        }
        if (! isset($this->loaded[$userId])) {
            $rows = DB::table('catalog_recording_members')
                ->join('catalog_recording_groups', 'catalog_recording_groups.id', '=', 'catalog_recording_members.group_id')
                ->where('catalog_recording_members.user_id', $userId)
                ->whereNotNull('catalog_recording_groups.recording_match')
                ->get(['catalog_recording_members.catalog_key', 'catalog_recording_groups.recording_match']);
            foreach ($rows as $row) {
                $this->matches[$userId.':'.$row->catalog_key] = json_decode($row->recording_match, true, 512, JSON_THROW_ON_ERROR);
            }
            $this->loaded[$userId] = true;
        }

        return $this->matches[$userId.':'.$this->key($provider, $id)] ?? null;
    }

    public function merge(User $user, Listen $first, Listen $second): void
    {
        DB::transaction(function () use ($user, $first, $second): void {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $first = Listen::query()->where('user_id', $user->id)->findOrFail($first->id);
            $second = Listen::query()->where('user_id', $user->id)->findOrFail($second->id);
            $one = $this->native($first);
            $two = $this->native($second);
            if ($one === null || $two === null || $first->source === $second->source) {
                throw ValidationException::withMessages(['merge' => 'Choose listens from different catalogs with a valid source catalog identifier.']);
            }
            $firstGroup = $this->groupId($first);
            $secondGroup = $this->groupId($second);
            if ($firstGroup !== null && $firstGroup === $secondGroup) {
                throw ValidationException::withMessages(['merge' => 'These catalog items are already linked.']);
            }
            $keys = [$this->key($first->source, $one['id']) => true, $this->key($second->source, $two['id']) => true];
            $groupIds = array_values(array_filter([$firstGroup, $secondGroup], fn ($id) => $id !== null));
            if ($groupIds !== []) {
                foreach (DB::table('catalog_recording_members')->where('user_id', $user->id)->whereIn('group_id', $groupIds)->pluck('catalog_key') as $key) {
                    $keys[$key] = true;
                }
            }
            $recordings = [];
            foreach (Listen::query()->where('user_id', $user->id)->whereNotNull('recording_mbid')->cursor() as $listen) {
                $catalog = $this->native($listen);
                if ($catalog !== null && isset($keys[$this->key($listen->source, $catalog['id'])])) {
                    $artists = $listen->track_metadata['additional_info']['artist_mbids'] ?? [];
                    if (! isset($recordings[$listen->recording_mbid]) || count($artists) > count($recordings[$listen->recording_mbid]['artist_mbids'])) {
                        $recordings[$listen->recording_mbid] = [
                            'recording_mbid' => $listen->recording_mbid, 'artist_name' => $listen->artist_name,
                            'artist_mbids' => $artists, 'release_mbid' => null,
                        ];
                    }
                }
            }
            foreach (DB::table('catalog_recording_groups')->where('user_id', $user->id)->whereIn('id', $groupIds)->whereNotNull('recording_match')->pluck('recording_match') as $saved) {
                $match = json_decode($saved, true, 512, JSON_THROW_ON_ERROR);
                if (! isset($recordings[$match['recording_mbid']]) || count($match['artist_mbids']) > count($recordings[$match['recording_mbid']]['artist_mbids'])) {
                    $recordings[$match['recording_mbid']] = $match;
                }
            }
            if (count($recordings) > 1) {
                throw ValidationException::withMessages(['merge' => 'These catalog items have different MusicBrainz recordings. Resolve the conflict before linking them.']);
            }
            $groupId = $firstGroup ?? $secondGroup;
            if ($groupId === null) {
                $groupId = DB::table('catalog_recording_groups')->insertGetId(['user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            if ($firstGroup !== null && $secondGroup !== null) {
                DB::table('catalog_recording_members')->where('user_id', $user->id)->where('group_id', $secondGroup)->update(['group_id' => $firstGroup]);
                DB::table('catalog_recording_groups')->where('user_id', $user->id)->where('id', $secondGroup)->delete();
            }
            foreach ([[$first, $one], [$second, $two]] as [$listen, $catalog]) {
                $resolved = $this->catalogs->withMetadata($user->id, $catalog);
                $previous = DB::table('catalog_recording_members')->where('user_id', $user->id)
                    ->where('catalog_key', $this->key($listen->source, $catalog['id']))->value('catalog');
                if ($previous !== null) {
                    $saved = json_decode($previous, true, 512, JSON_THROW_ON_ERROR);
                    $resolved['related_catalogs'] = $saved['related_catalogs'] ?? [];
                }
                DB::table('catalog_recording_members')->updateOrInsert(
                    ['user_id' => $user->id, 'catalog_key' => $this->key($listen->source, $catalog['id'])],
                    ['group_id' => $groupId, 'catalog' => json_encode($resolved, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()],
                );
            }
            $this->matches = [];
            $this->loaded = [];
            $this->syncEvidence($user);
            if ($recordings !== []) {
                $mbid = array_key_first($recordings);
                $this->recordMatch($user, $first, $recordings[$mbid]);
            }
        });
    }

    /** @param array{recording_mbid: string, artist_name: string, artist_mbids: list<string>, release_mbid: null} $match */
    public function recordMatch(User $user, Listen $listen, array $match): int
    {
        $groupId = $this->groupId($listen);
        if ($groupId === null) {
            return 0;
        }
        $keys = array_fill_keys(DB::table('catalog_recording_members')->where('user_id', $user->id)->where('group_id', $groupId)->pluck('catalog_key')->all(), true);
        $updated = 0;
        Listen::query()->where('user_id', $user->id)->chunkById(500, function ($listens) use ($keys, $match, &$updated): void {
            foreach ($listens as $candidate) {
                $catalog = $this->native($candidate);
                if ($catalog === null || ! isset($keys[$this->key($candidate->source, $catalog['id'])])) {
                    continue;
                }
                if ($candidate->recording_mbid !== null && $candidate->recording_mbid !== $match['recording_mbid']) {
                    throw ValidationException::withMessages(['recording_mbid' => 'Linked catalog items have conflicting MusicBrainz recordings. Unlink them before matching.']);
                }
                if ($candidate->recording_mbid !== null) {
                    continue;
                }
                $metadata = $candidate->track_metadata;
                $metadata['additional_info']['recording_mbid'] = $match['recording_mbid'];
                if ($match['artist_mbids'] !== []) {
                    $metadata['additional_info']['artist_mbids'] = $match['artist_mbids'];
                }
                $candidate->update(['recording_mbid' => $match['recording_mbid'], 'track_metadata' => $metadata]);
                $this->metadata->sync($candidate);
                $updated++;
            }
        });
        DB::table('catalog_recording_groups')->where('user_id', $user->id)->where('id', $groupId)
            ->update(['recording_match' => json_encode($match, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        $this->matches = [];
        $this->loaded = [];

        return $updated;
    }

    public function unlink(User $user, Listen $listen, int $memberId): void
    {
        DB::transaction(function () use ($user, $listen, $memberId): void {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $groupId = $this->groupId($listen);
            abort_if($groupId === null, 404);
            $deleted = DB::table('catalog_recording_members')->where('user_id', $user->id)->where('group_id', $groupId)->where('id', $memberId)->delete();
            abort_if($deleted !== 1, 404);
            if (DB::table('catalog_recording_members')->where('group_id', $groupId)->count() < 2) {
                DB::table('catalog_recording_groups')->where('user_id', $user->id)->where('id', $groupId)->delete();
            }
            $this->matches = [];
            $this->loaded = [];
        });
    }
}
