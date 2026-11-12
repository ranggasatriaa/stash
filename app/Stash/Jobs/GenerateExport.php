<?php

namespace App\Stash\Jobs;

use App\Stash\Models\DataExport;
use App\Stash\Support\RequestContext;
use App\Stash\Transfer\ExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateExport implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public readonly string $exportId)
    {
        $this->onQueue('default');
    }

    public function handle(ExportService $service, RequestContext $context): void
    {
        $context->asJob('export');
        $export = DataExport::query()->find($this->exportId);
        if (! $export || $export->status === 'ready') {
            return;
        }
        $service->generate($export);
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('export.failed', ['export_id' => $this->exportId, 'error' => $e ? get_class($e) : null]);
        DataExport::query()->whereKey($this->exportId)->update(['status' => 'failed', 'error' => 'Export failed. Please try again.']);
    }
}
