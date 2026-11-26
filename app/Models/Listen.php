<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Listen extends Model
{
    protected $fillable = [
        'user_id', 'source', 'fingerprint', 'listened_at', 'track_name',
        'artist_name', 'release_name', 'played_ms', 'recording_mbid', 'track_metadata',
    ];

    protected function casts(): array
    {
        return [
            'listened_at' => 'integer',
            'played_ms' => 'integer',
            'track_metadata' => 'array',
        ];
    }
}
