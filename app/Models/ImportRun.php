<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property list<array{name: string, path: string}> $files */
class ImportRun extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'user_id', 'status', 'files', 'result', 'error'];

    protected function casts(): array
    {
        return ['files' => 'array', 'result' => 'array'];
    }
}
