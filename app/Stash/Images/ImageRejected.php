<?php

namespace App\Stash\Images;

use RuntimeException;

/** An uploaded or fetched file failed image validation (actionable message). */
final class ImageRejected extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
