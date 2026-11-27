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

    public function nativeKey(Listen $listen): ?string
    {
        $catalog = $this->native($listen);

        return $catalog === null ? null : $this->key($listen->source, $catalog['id']);
    }

    /**
     * The caller must hold the user's row lock inside a transaction.
     *
     * @param  list<string>  $keys
     * @param  array{recording_mbid: string, artist_name: string, artist_mbids: list<string>, artist_names?: array<string, string>, release_mbid: null}  $match
     * @return list<string>
     */
    public function replaceRemembered(User $user, array $keys, array $match, string $previousRecordingMbid): array
    {
        $groupIds = DB::table('catalog_recording_members')->where('user_id', $user->id)
            ->whereIn('catalog_key', $keys)->distinct()->pluck('group_id');
        $groups = DB::table('catalog_recording_groups')->where('user_id', $user->id)->whereIn('id', $groupIds);
        foreach ((clone $groups)->whereNotNull('recording_match')->pluck('recording_match') as $value) {
            $saved = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            if (! in_array($saved['recording_mbid'], [$previousRecordingMbid, $match['recording_mbid']], true)) {
                throw ValidationException::withMessages(['recording_mbid' => 'A linked catalog group has a conflicting saved recording. Resolve or unlink it before re-matching. No matches were changed.']);
            }
        }
        $groups->update(['recording_match' => json_encode($match, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        $members = DB::table('catalog_recording_members')->where('user_id', $user->id)
            ->whereIn('group_id', $groupIds)->pluck('catalog_key')->all();
        $this->matches = [];
        $this->loaded = [];

        return array_values(array_unique([...$keys, ...$members]));
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
            DB::table('catalog_recording_auto_exclusions')->where('user_id', $user->id)
                ->whereIn('catalog_key', [$this->key($first->source, $one['id']), $this->key($second->source, $two['id'])])->delete();
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
            $member = DB::table('catalog_recording_members')->where('user_id', $user->id)->where('group_id', $groupId)->where('id', $memberId)->first();
            abort_if($member === null, 404);
            DB::table('catalog_recording_auto_exclusions')->updateOrInsert(
                ['user_id' => $user->id, 'catalog_key' => $member->catalog_key],
                ['updated_at' => now(), 'created_at' => now()],
            );
            $deleted = DB::table('catalog_recording_members')->where('user_id', $user->id)->where('group_id', $groupId)->where('id', $memberId)->delete();
            abort_if($deleted !== 1, 404);
            if (DB::table('catalog_recording_members')->where('group_id', $groupId)->count() < 2) {
                DB::table('catalog_recording_groups')->where('user_id', $user->id)->where('id', $groupId)->delete();
            }
            $this->matches = [];
            $this->loaded = [];
        });
    }

    /**
     * @param  list<string>|null  $recordingMbids
     * @param  (\Closure(): void)|null  $checkAbort
     * @return array{linked: int, matched: int}
     */
    public function linkMatching(User $user, ManualListenMatcher $manual, ?array $recordingMbids = null, ?\Closure $checkAbort = null): array
    {
        if ($recordingMbids === []) {
            return ['linked' => 0, 'matched' => 0];
        }

        return DB::transaction(function () use ($user, $manual, $recordingMbids, $checkAbort): array {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $targets = $recordingMbids === null ? null : array_fill_keys($recordingMbids, true);
            $savedMatches = DB::table('listen_matches')->where('user_id', $user->id)->pluck('match', 'song_key');
            $excluded = array_fill_keys(DB::table('catalog_recording_auto_exclusions')->where('user_id', $user->id)->pluck('catalog_key')->all(), true);
            $catalogs = [];
            $candidates = [];
            Listen::query()->where('user_id', $user->id)->chunkById(500, function ($listens) use ($manual, $savedMatches, $targets, $checkAbort, &$catalogs, &$candidates): void {
                if ($checkAbort !== null) {
                    $checkAbort();
                }
                foreach ($listens as $listen) {
                    $catalog = $this->native($listen);
                    if ($catalog === null) {
                        continue;
                    }
                    $key = $this->key($listen->source, $catalog['id']);
                    $catalogs[$key] ??= ['catalog' => $catalog, 'provider' => $listen->source, 'recordings' => []];
                    if ($listen->recording_mbid !== null) {
                        $catalogs[$key]['recordings'][$listen->recording_mbid] = true;
                        if ($targets === null || isset($targets[$listen->recording_mbid])) {
                            $candidates[$listen->recording_mbid][$key] = [
                                'recording_mbid' => $listen->recording_mbid,
                                'artist_name' => $listen->artist_name,
                                'artist_mbids' => $listen->track_metadata['additional_info']['artist_mbids'] ?? [],
                                'release_mbid' => null,
                            ];
                        }
                    }
                    $saved = $savedMatches[$manual->key($listen->track_name, $listen->artist_name)] ?? null;
                    if ($saved !== null) {
                        $match = json_decode($saved, true, 512, JSON_THROW_ON_ERROR);
                        $catalogs[$key]['recordings'][$match['recording_mbid']] = true;
                    }
                }
            });

            $members = [];
            $groupMembers = [];
            foreach (DB::table('catalog_recording_members')->where('user_id', $user->id)->get() as $member) {
                $members[$member->catalog_key] = $member->group_id;
                $groupMembers[$member->group_id][$member->catalog_key] = true;
            }
            $groupMatches = [];
            foreach (DB::table('catalog_recording_groups')->where('user_id', $user->id)->whereNotNull('recording_match')->get() as $group) {
                $groupMatches[$group->id] = json_decode($group->recording_match, true, 512, JSON_THROW_ON_ERROR);
            }

            $linked = 0;
            $changedGroups = [];
            foreach ($candidates as $mbid => $items) {
                if ($checkAbort !== null) {
                    $checkAbort();
                }
                $units = [];
                $providers = [];
                foreach ($items as $key => $match) {
                    $groupId = $members[$key] ?? null;
                    $keys = $groupId === null ? [$key => true] : $groupMembers[$groupId];
                    $valid = $groupId === null || ! isset($groupMatches[$groupId]) || $groupMatches[$groupId]['recording_mbid'] === $mbid;
                    foreach ($keys as $memberKey => $_) {
                        if (isset($excluded[$memberKey]) || ! isset($catalogs[$memberKey])
                            || array_diff(array_keys($catalogs[$memberKey]['recordings']), [$mbid]) !== []) {
                            $valid = false;
                            break;
                        }
                    }
                    if (! $valid) {
                        continue;
                    }
                    $unit = $groupId === null ? $key : 'group:'.$groupId;
                    $units[$unit] = ['group' => $groupId, 'key' => $key, 'match' => $match];
                    foreach ($keys as $memberKey => $_) {
                        $providers[$catalogs[$memberKey]['provider']] = true;
                    }
                }
                if (count($providers) < 2 || $units === []) {
                    continue;
                }
                if (count($units) === 1) {
                    $only = reset($units);
                    if ($only['group'] === null || isset($groupMatches[$only['group']])) {
                        continue;
                    }
                }
                $groupId = null;
                foreach ($units as $unit) {
                    if ($unit['group'] !== null) {
                        $groupId = $unit['group'];
                        break;
                    }
                }
                $match = $groupId !== null && isset($groupMatches[$groupId]) ? $groupMatches[$groupId] : reset($units)['match'];
                foreach ($units as $unit) {
                    $candidate = $unit['group'] !== null && isset($groupMatches[$unit['group']]) ? $groupMatches[$unit['group']] : $unit['match'];
                    if (count($candidate['artist_mbids']) > count($match['artist_mbids'])) {
                        $match = $candidate;
                    }
                }
                if ($groupId === null) {
                    $groupId = DB::table('catalog_recording_groups')->insertGetId([
                        'user_id' => $user->id, 'recording_match' => json_encode($match, JSON_THROW_ON_ERROR),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $groupMembers[$groupId] = [];
                } elseif (! isset($groupMatches[$groupId]) || count($match['artist_mbids']) > count($groupMatches[$groupId]['artist_mbids'])) {
                    DB::table('catalog_recording_groups')->where('user_id', $user->id)->where('id', $groupId)
                        ->update(['recording_match' => json_encode($match, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                    $linked++;
                }
                $groupMatches[$groupId] = $match;
                $changedGroups[$groupId] = true;
                foreach ($units as $unit) {
                    $oldGroup = $unit['group'];
                    if ($oldGroup === $groupId) {
                        continue;
                    }
                    if ($oldGroup !== null) {
                        DB::table('catalog_recording_members')->where('user_id', $user->id)->where('group_id', $oldGroup)->update(['group_id' => $groupId]);
                        DB::table('catalog_recording_groups')->where('user_id', $user->id)->where('id', $oldGroup)->delete();
                        foreach ($groupMembers[$oldGroup] as $memberKey => $_) {
                            $members[$memberKey] = $groupId;
                            $groupMembers[$groupId][$memberKey] = true;
                        }
                        unset($groupMembers[$oldGroup], $groupMatches[$oldGroup]);
                    } else {
                        $key = $unit['key'];
                        $catalog = $this->catalogs->withMetadata($user->id, $catalogs[$key]['catalog']);
                        DB::table('catalog_recording_members')->insert([
                            'user_id' => $user->id, 'group_id' => $groupId, 'catalog_key' => $key,
                            'catalog' => json_encode($catalog, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $members[$key] = $groupId;
                        $groupMembers[$groupId][$key] = true;
                    }
                    $linked++;
                }
            }
            $matched = 0;
            if ($linked > 0) {
                Listen::query()->where('user_id', $user->id)->whereNull('recording_mbid')
                    ->chunkById(500, function ($listens) use ($members, $changedGroups, $groupMatches, $checkAbort, &$matched): void {
                        if ($checkAbort !== null) {
                            $checkAbort();
                        }
                        foreach ($listens as $listen) {
                            $catalog = $this->native($listen);
                            if ($catalog === null) {
                                continue;
                            }
                            $groupId = $members[$this->key($listen->source, $catalog['id'])] ?? null;
                            if ($groupId === null || ! isset($changedGroups[$groupId])) {
                                continue;
                            }
                            $match = $groupMatches[$groupId];
                            $metadata = $listen->track_metadata;
                            $metadata['additional_info']['recording_mbid'] = $match['recording_mbid'];
                            if ($match['artist_mbids'] !== []) {
                                $metadata['additional_info']['artist_mbids'] = $match['artist_mbids'];
                            }
                            $listen->update(['recording_mbid' => $match['recording_mbid'], 'track_metadata' => $metadata]);
                            $this->metadata->sync($listen);
                            $matched++;
                        }
                    });
                $this->matches = [];
                $this->loaded = [];
                $this->syncEvidence($user, $checkAbort);
            }

            return ['linked' => $linked, 'matched' => $matched];
        });
    }
}
