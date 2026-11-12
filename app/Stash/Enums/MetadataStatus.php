<?php

namespace App\Stash\Enums;

enum MetadataStatus: string
{
    case None = 'none';
    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function isPending(): bool
    {
        return $this === self::Queued || $this === self::Processing;
    }
}
