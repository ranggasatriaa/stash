<?php

namespace App\Stash\Metadata;

use App\Stash\Enums\FileRole;
use App\Stash\Enums\MetadataStatus;
use App\Stash\Fetch\FetchFailedException;
use App\Stash\Fetch\SafeFetcher;
use App\Stash\Fetch\UnsafeUrlException;
use App\Stash\Files\FileStore;
use App\Stash\Images\ImageInspector;
use App\Stash\Images\ImageProcessor;
use App\Stash\Jobs\DeleteStoredFiles;
use App\Stash\Models\Resource;
use App\Stash\Models\ResourceFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches and applies basic page metadata. Never uses an LLM.
 *
 * Proposed values are stored in metadata.fetched. They are only written to
 * title/description when the field is not in manual_fields *at apply time*
 * (checked under a row lock), so manual edits made while the job runs are
 * never clobbered.
 */
class MetadataEnricher
{
    public function __construct(
        private readonly SafeFetcher $fetcher,
        private readonly HtmlMetadataParser $parser,
        private readonly FileStore $files,
        private readonly ImageInspector $inspector,
        private readonly ImageProcessor $images,
    ) {}

    /**
     * @return string outcome: ready|failed|skipped
     *
     * @throws FetchFailedException for transient failures when $rethrowTransient is true
     */
    public function enrich(string $resourceId, bool $rethrowTransient = false): string
    {
        $resource = Resource::withTrashed()->find($resourceId);
        if (! $resource || ! $resource->original_url || $resource->type->isUpload()) {
            return 'skipped';
        }

        Resource::withTrashed()->whereKey($resourceId)->update(['metadata_status' => MetadataStatus::Processing->value]);

        try {
            $page = $this->fetcher->fetch($resource->original_url, SafeFetcher::PROFILE_HTML);
            $parsed = $this->parser->parse($page->body, $page->url, $page->charset);
        } catch (UnsafeUrlException $e) {
            $this->fail($resourceId, 'Blocked: this address is not allowed for automatic fetching. Edit details manually.');

            return 'failed';
        } catch (FetchFailedException $e) {
            if ($rethrowTransient && in_array($e->category, ['timeout', 'connection', 'dns'], true)) {
                Resource::withTrashed()->whereKey($resourceId)->update(['metadata_status' => MetadataStatus::Queued->value]);
                throw $e;
            }
            $this->fail($resourceId, $this->message($e));

            return 'failed';
        } catch (Throwable $e) {
            Log::warning('metadata.enrich_failed', ['resource_id' => $resourceId, 'error' => get_class($e)]);
            $this->fail($resourceId, 'Metadata could not be read from this page. Edit details manually.');

            return 'failed';
        }

        $fetched = [
            'title' => $parsed['og_title'] ?? $parsed['title'],
            'description' => $parsed['og_description'] ?? $parsed['description'],
            'site_name' => $parsed['site_name'],
            'image_url' => $parsed['og_image'],
            'favicon_url' => $this->safeUrl($parsed['favicon']),
            'final_url' => $page->url,
            'fetched_at' => now()->toIso8601ZuluString(),
        ];

        $preview = $this->buildPreview($resource, array_merge(
            $parsed['image_candidates'],
            VideoThumbnails::candidates($page->url),
            VideoThumbnails::candidates($resource->original_url),
        ));

        $obsolete = [];
        DB::transaction(function () use ($resourceId, $fetched, $preview, &$obsolete) {
            /** @var resource|null $r */
            $r = Resource::withTrashed()->whereKey($resourceId)->lockForUpdate()->first();
            if (! $r) {
                return;
            }

            $changed = false;
            foreach (Resource::ENRICHABLE_FIELDS as $field) {
                $value = $fetched[$field] ?? null;
                if (is_string($value) && $value !== '' && ! $r->isManual($field) && $r->{$field} !== $value) {
                    $r->{$field} = $value;
                    $changed = true;
                }
            }

            $metadata = $r->metadata ?? [];
            $metadata['fetched'] = $fetched;
            $metadata['site_name'] = $fetched['site_name'];
            $r->metadata = $metadata;
            $r->metadata_status = MetadataStatus::Ready;
            $r->metadata_error = null;

            if ($preview !== null && ! $r->files()->where('role', FileRole::Original->value)->exists()) {
                foreach ($preview as $role => $variant) {
                    $existing = ResourceFile::query()->where('resource_id', $r->id)->where('role', $role)->first();
                    if ($existing) {
                        $obsolete[] = [$existing->disk, $existing->storage_key];
                        $existing->delete();
                    }
                    ResourceFile::query()->create($variant + ['resource_id' => $r->id, 'user_id' => $r->user_id, 'role' => $role]);
                }
                $changed = true;
            } elseif ($preview !== null) {
                foreach ($preview as $variant) {
                    $obsolete[] = [$variant['disk'], $variant['storage_key']];
                }
            }

            if ($changed) {
                $r->version = $r->version + 1;
            }
            $r->save();
        });

        if ($obsolete !== []) {
            DeleteStoredFiles::dispatch($obsolete);
        }

        return 'ready';
    }

    /**
     * Try each candidate image URL in order and keep the first that downloads
     * and validates. Any failure simply means "no preview".
     *
     * @param  list<string>  $imageUrls
     * @return array<string, array<string, mixed>>|null
     */
    private function buildPreview(Resource $resource, array $imageUrls): ?array
    {
        foreach (array_slice(array_values(array_unique($imageUrls)), 0, 6) as $imageUrl) {
            $preview = $this->buildPreviewFrom($resource, $imageUrl);
            if ($preview !== null) {
                return $preview;
            }
        }

        return null;
    }

    /** @return array<string, array<string, mixed>>|null */
    private function buildPreviewFrom(Resource $resource, string $imageUrl): ?array
    {
        $tmp = null;
        try {
            $image = $this->fetcher->fetch($imageUrl, SafeFetcher::PROFILE_IMAGE);
            $tmp = tempnam(sys_get_temp_dir(), 'stashog');
            if ($tmp === false) {
                return null;
            }
            file_put_contents($tmp, $image->body);
            $this->inspector->inspect($tmp, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], (int) config('stash.fetch.max_image_bytes'));
            $variants = $this->images->variants($tmp);

            $out = [];
            foreach ([FileRole::Thumbnail->value => $variants['thumbnail'], FileRole::Preview->value => $variants['preview']] as $role => $variant) {
                $key = $this->files->resourceKey($resource->user_id, $resource->id, $role, 'webp');
                $this->files->put($key, $variant['bytes']);
                $out[$role] = [
                    'disk' => $this->files->diskName(),
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

            return $out;
        } catch (Throwable) {
            return null;
        } finally {
            if ($tmp) {
                @unlink($tmp);
            }
        }
    }

    private function safeUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        try {
            $this->fetcher->validate($url);

            return $url;
        } catch (Throwable) {
            return null;
        }
    }

    private function fail(string $resourceId, string $message): void
    {
        Resource::withTrashed()->whereKey($resourceId)->update([
            'metadata_status' => MetadataStatus::Failed->value,
            'metadata_error' => mb_substr($message, 0, 255),
        ]);
    }

    private function message(FetchFailedException $e): string
    {
        return match ($e->category) {
            'timeout' => 'The page took too long to respond. Retry later or edit details manually.',
            'dns' => 'The site could not be found. Check the URL.',
            'http_status' => 'The page responded with HTTP '.($e->status ?? 'error').'. Edit details manually.',
            'content_type' => 'This URL is not an HTML page, so no metadata was read.',
            'too_large' => 'The page is too large to read metadata from.',
            'too_many_redirects' => 'The page redirected too many times.',
            'tls' => 'The TLS certificate of this site could not be verified, so it was not fetched.',
            default => 'Could not reach the page. Retry later or edit details manually.',
        };
    }
}
