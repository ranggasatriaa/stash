<?php

namespace App\Stash\Jobs;

use App\Stash\Models\DataImport;
use App\Stash\Support\RequestContext;
use App\Stash\Transfer\ImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Applies a confirmed import once. Not retried automatically: a partial run
 * already reports per-item outcomes, and re-running with "skip" is safe.
 */
class ApplyImport implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly string $importId)
    {
        $this->onQueue('default');
    }

    public function handle(ImportService $service, RequestContext $context): void
    {
        $context->asJob('import');
        $import = DataImport::query()->find($this->importId);
        if ($import && $import->status === 'applying') {
            $service->apply($import);
        }
    }

    public function failed(?Throwable $e): void
    {
        DataImport::query()->whereKey($this->importId)->where('status', 'applying')
            ->update(['status' => 'failed', 'error' => 'The import stopped unexpectedly. Items already imported were kept; re-run with "skip" to continue.']);
    }
}
