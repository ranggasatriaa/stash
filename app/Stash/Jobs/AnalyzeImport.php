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

class AnalyzeImport implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $importId)
    {
        $this->onQueue('default');
    }

    public function handle(ImportService $service, RequestContext $context): void
    {
        $context->asJob('import');
        $import = DataImport::query()->find($this->importId);
        if ($import && in_array($import->status, ['uploaded', 'analyzing'], true)) {
            $service->analyze($import);
        }
    }

    public function failed(?Throwable $e): void
    {
        DataImport::query()->whereKey($this->importId)->update(['status' => 'invalid', 'error' => 'The import could not be analyzed.']);
    }
}
