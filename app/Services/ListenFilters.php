<?php

namespace App\Services;

use App\Models\Listen;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ListenFilters
{
    /** @return array<string, string|null> */
    public function validate(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', Rule::in(['spotify', 'apple'])],
            'match' => ['nullable', Rule::in(['matched', 'unmatched'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['listened_at', 'track_name', 'artist_name', 'release_name'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
    }

    /** @param array<string, string|null> $filters
     * @return Builder<Listen>
     */
    public function query(int $userId, array $filters): Builder
    {
        $query = Listen::query()->where('user_id', $userId);
        if (! empty($filters['search'])) {
            $search = mb_strtolower($filters['search']);
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(function ($query) use ($pattern): void {
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

        return $query;
    }

    /** @param array<string, string|null> $filters */
    public function selectionHash(array $filters): string
    {
        return hash('sha256', json_encode(array_map(
            fn (string $key) => $filters[$key] ?? '',
            ['search', 'source', 'match', 'from', 'to'],
        ), JSON_THROW_ON_ERROR));
    }
}
