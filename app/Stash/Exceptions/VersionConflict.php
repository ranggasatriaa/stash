<?php

namespace App\Stash\Exceptions;

use App\Stash\Models\Resource;
use RuntimeException;

/** The client's version precondition no longer matches (HTTP 409). */
final class VersionConflict extends RuntimeException
{
    public function __construct(public readonly Resource $current, public readonly int $expected)
    {
        parent::__construct('This resource was changed elsewhere. Reload it and apply your edits again.');
    }
}
