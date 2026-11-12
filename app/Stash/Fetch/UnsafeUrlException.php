<?php

namespace App\Stash\Fetch;

use RuntimeException;

/** The URL (or a redirect target) points somewhere Stash must never fetch. */
final class UnsafeUrlException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : "Blocked URL ({$reason}).");
    }
}
