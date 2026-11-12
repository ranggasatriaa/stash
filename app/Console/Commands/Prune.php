<?php

namespace App\Console\Commands;

use App\Stash\Idempotency\IdempotencyService;
use App\Stash\Transfer\ExportService;
use App\Stash\Transfer\ImportService;
use Illuminate\Console\Command;

/**
 * Housekeeping (scheduled hourly). Never touches resources: Trash has no
 * automatic purge in this release.
 */
class Prune extends Command
{
    protected $signature = 'stash:prune';

    protected $description = 'Remove expired exports, stale imports and idempotency records';

    public function handle(ExportService $exports, ImportService $imports, IdempotencyService $idempotency): int
    {
        $this->line('Expired exports removed: '.$exports->prune());
        $this->line('Stale imports removed: '.$imports->prune());
        $this->line('Idempotency records pruned: '.$idempotency->prune());

        return self::SUCCESS;
    }
}
