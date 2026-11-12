<?php

namespace App\Stash\Jobs;

use App\Stash\Enums\FileRole;
use App\Stash\Enums\MetadataStatus;
use App\Stash\Files\FileStore;
use App\Stash\Images\ImageProcessor;
use App\Stash\Models\Resource;
use App\Stash\Models\ResourceFile;
use App\Stash\Support\RequestContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generate thumbnail + preview variants for an uploaded original. Idempotent:
 * re-running replaces previous variants and schedules their deletion.
 */
class ProcessResourceImage implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 600;

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
        return [20];
    }

    public function handle(FileStore $files, ImageProcessor $processor, RequestContext $context): void
    {
        $context->asJob('image');
        $resource = Resource::withTrashed()->find($this->resourceId);
        $original = ResourceFile::query()->where('resource_id', $this->resourceId)->where('role', FileRole::Original->value)->first();
        if (! $resource || ! $original) {
            return;
        }

        Resource::withTrashed()->whereKey($this->resourceId)->update(['metadata_status' => MetadataStatus::Processing->value]);

        $variants = $files->withLocalPath($original->disk, $original->storage_key, fn (string $path) => $processor->variants($path));

        $written = [];
        try {
            foreach ([FileRole::Thumbnail->value => $variants['thumbnail'], FileRole::Preview->value => $variants['preview']] as $role => $variant) {
                $key = $files->resourceKey($resource->user_id, $resource->id, $role, 'webp');
                $files->put($key, $variant['bytes']);
                $written[$role] = [
                    'disk' => $files->diskName(),
                    'storage_key' => $key,
                    'mime_type' => 'image/webp',
                    'extension' => 'webp',
                    'size_bytes' => strlen($variant['bytes']),
                    'width' => $variant['width'],
                    'height' => $variant['height'],
                    'checksum_sha256' => hash('sha256', $variant['bytes']),
                    'status' => 'ready',
                ];
            }

            $obsolete = [];
            DB::transaction(function () use ($resource, $written, &$obsolete) {
                $r = Resource::withTrashed()->whereKey($resource->id)->lockForUpdate()->first();
                if (! $r) {
                    foreach ($written as $variant) {
                        $obsolete[] = [$variant['disk'], $variant['storage_key']];
                    }

                    return;
                }
                foreach ($written as $role => $variant) {
                    $existing = ResourceFile::query()->where('resource_id', $r->id)->where('role', $role)->first();
                    if ($existing) {
                        $obsolete[] = [$existing->disk, $existing->storage_key];
                        $existing->delete();
                    }
                    ResourceFile::query()->create($variant + ['resource_id' => $r->id, 'user_id' => $r->user_id, 'role' => $role]);
                }
                $r->metadata_status = MetadataStatus::Ready;
                $r->metadata_error = null;
                $r->save();
            });

            if ($obsolete !== []) {
                DeleteStoredFiles::dispatch($obsolete);
            }
        } catch (Throwable $e) {
            foreach ($written as $variant) {
                $files->delete($variant['disk'], $variant['storage_key']);
            }
            throw $e;
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('image.process_failed', ['resource_id' => $this->resourceId, 'error' => $e ? get_class($e) : null]);
        Resource::withTrashed()->whereKey($this->resourceId)->update([
            'metadata_status' => MetadataStatus::Failed->value,
            'metadata_error' => 'Preview generation failed. The original is kept; retry processing.',
        ]);
    }
}
