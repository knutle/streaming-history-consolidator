<?php

namespace App\Exceptions;

use RuntimeException;

class MusicBrainzTemporaryException extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfter = 0)
    {
        parent::__construct($message);
    }
}
