<?php

namespace App\Stash\Exceptions;

use RuntimeException;

/**
 * Domain error with a stable machine-readable code and an HTTP status.
 * Messages must be safe to show to the user.
 */
class StashException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
