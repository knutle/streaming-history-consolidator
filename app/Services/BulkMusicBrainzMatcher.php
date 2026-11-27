<?php

namespace App\Services;

use App\Models\Listen;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BulkMusicBrainzMatcher
{
    public function __construct(
        private ManualListenMatcher $manual,
        private MatchedMusicMetadata $metadata,
        private CatalogRecordingGroups $groups,
    ) {}

    /** @param array{recording_mbid: string, artist_name: string, artist_mbids: list<string>, artist_names?: array<string, string>, release_mbid: null} $match */
    public function apply(User $user, Listen $listen, array $match): ?int
    {
        $saved = $this->manual->remembered($user, $listen->track_name, $listen->artist_name);
        $groupId = $this->groups->groupId($listen);
        $groupValue = $groupId === null ? null : DB::table('catalog_recording_groups')
            ->where('user_id', $user->id)->where('id', $groupId)->value('recording_match');
        $groupMatch = $groupValue === null ? null : json_decode($groupValue, true, 512, JSON_THROW_ON_ERROR);
        if (($saved !== null && $saved['recording_mbid'] !== $match['recording_mbid'])
            || ($groupMatch !== null && $groupMatch['recording_mbid'] !== $match['recording_mbid'])) {
            return null;
        }
        if ($groupId !== null) {
            $keys = array_fill_keys(DB::table('catalog_recording_members')
                ->where('user_id', $user->id)->where('group_id', $groupId)->pluck('catalog_key')->all(), true);
            foreach (Listen::query()->where('user_id', $user->id)->whereNull('recording_mbid')->lazyById(500) as $candidate) {
                $catalog = $this->groups->native($candidate);
                if ($catalog === null || ! isset($keys[hash('sha256', $candidate->source.':'.$catalog['id'])])) {
                    continue;
                }
                $candidateSaved = $this->manual->remembered($user, $candidate->track_name, $candidate->artist_name);
                if ($candidateSaved !== null && $candidateSaved['recording_mbid'] !== $match['recording_mbid']) {
                    return null;
                }
            }
        }
        $this->metadata->rememberArtistNames($match['artist_names'] ?? []);
        $metadata = $listen->track_metadata;
        $metadata['additional_info']['recording_mbid'] = $match['recording_mbid'];
        $metadata['additional_info']['artist_mbids'] = $match['artist_mbids'];
        $listen->update(['recording_mbid' => $match['recording_mbid'], 'track_metadata' => $metadata]);
        $this->metadata->sync($listen);
        $propagated = $this->groups->recordMatch($user, $listen, $match);
        if ($groupId === null && $this->groups->native($listen) !== null
            && Listen::query()->where('user_id', $user->id)->where('recording_mbid', $match['recording_mbid'])
                ->where('source', '!=', $listen->source)->exists()) {
            $linked = $this->groups->linkMatching($user, $this->manual, [$match['recording_mbid']]);
            $propagated += $linked['matched'];
        }

        return $propagated;
    }
}
