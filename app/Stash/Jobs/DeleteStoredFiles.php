<?php

namespace App\Stash\Jobs;

use App\Stash\Files\FileStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Delete stored files after their rows are gone. Already-missing files count
 * as deleted, so retries never fail on partial progress.
 */
class DeleteStoredFiles implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @param list<array{0: string, 1: string}> $files [disk, key] pairs */
    public function __construct(public readonly array $files)
    {
        $this->onQueue('default');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(FileStore $store): void
    {
        $failed = [];
        foreach ($this->files as [$disk, $key]) {
            if (! $store->delete($disk, $key)) {
                $failed[] = [$disk, $key];
            }
        }

        if ($failed !== []) {
            throw new RuntimeException(count($failed).' stored file(s) could not be deleted yet.');
        }
    }
}
