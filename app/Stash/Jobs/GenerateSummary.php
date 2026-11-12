<?php

namespace App\Stash\Jobs;

use App\Stash\Ai\ProviderError;
use App\Stash\Ai\SummaryService;
use App\Stash\Enums\AiRunStatus;
use App\Stash\Models\AiRun;
use App\Stash\Support\RequestContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Executes a reserved summary run. Retries only explicit "not processed"
 * provider responses (429/503) with backoff, bounded by stash.ai.max_attempts.
 */
class GenerateSummary implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 150;

    public function __construct(public readonly string $runId)
    {
        $this->onQueue('ai');
    }

    public function handle(SummaryService $service, RequestContext $context): void
    {
        $context->asJob('ai');
        $run = AiRun::query()->find($this->runId);
        if (! $run || ! $run->status->isActive()) {
            return;
        }

        if ($run->status === AiRunStatus::Running) {
            // A previous attempt died mid-request (worker killed/timeout): the
            // provider may already have billed it, so never call it again.
            $service->finishFailed($run, new ProviderError('timeout', 'The previous attempt was interrupted; not retried to avoid double spending.'));

            return;
        }

        $retryIn = $service->execute($run);
        if ($retryIn !== null) {
            $this->release($retryIn);
        }
    }

    public function failed(?Throwable $e): void
    {
        $run = AiRun::query()->find($this->runId);
        if ($run && $run->status->isActive()) {
            app(SummaryService::class)->finishFailed($run, new ProviderError('unavailable', 'The summary job failed. Your data is unchanged; try again later.'));
        }
        if ($run && $run->status === AiRunStatus::Failed && $run->total_tokens === null) {
            $run->total_tokens = 0;
            $run->save();
        }
    }
}
