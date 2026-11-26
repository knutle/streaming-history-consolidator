<?php

namespace App\Services;

class Isrc
{
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (! preg_match('/^[a-z]{2}-?[a-z0-9]{3}-?[0-9]{2}-?[0-9]{5}$/iD', $value)) {
            return null;
        }

        return strtoupper(str_replace('-', '', $value));
    }
}
