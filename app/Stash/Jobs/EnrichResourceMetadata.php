<?php

namespace App\Stash\Jobs;

use App\Stash\Enums\MetadataStatus;
use App\Stash\Fetch\FetchFailedException;
use App\Stash\Metadata\MetadataEnricher;
use App\Stash\Models\Resource;
use App\Stash\Support\RequestContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Fetch page metadata for a link/article. Dispatched after commit; unique
 * per resource; transient network failures retry with backoff.
 */
class EnrichResourceMetadata implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $resourceId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->resourceId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(MetadataEnricher $enricher, RequestContext $context): void
    {
        $context->asJob('metadata');
        // Only a real queue can retry later. With the sync driver a transient
        // failure must not bubble into (and break) the request that saved it.
        $retryable = $this->job !== null && ! $this->job instanceof SyncJob && $this->attempts() < $this->tries;
        $enricher->enrich($this->resourceId, rethrowTransient: $retryable);
    }

    public function failed(?Throwable $e): void
    {
        $message = $e instanceof FetchFailedException
            ? 'Could not reach the page after several attempts. Retry later or edit details manually.'
            : 'Metadata processing failed. Retry or edit details manually.';

        Resource::withTrashed()->whereKey($this->resourceId)->update([
            'metadata_status' => MetadataStatus::Failed->value,
            'metadata_error' => $message,
        ]);
    }
}
