<?php

namespace App\Stash\Enums;

enum AiRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
