<?php

namespace App\Stash\Resources;

use App\Models\User;
use App\Stash\Activity\ActivityLogger;
use App\Stash\Enums\FileRole;
use App\Stash\Enums\MetadataStatus;
use App\Stash\Enums\ReadingStatus;
use App\Stash\Enums\ResourceType;
use App\Stash\Exceptions\StashException;
use App\Stash\Exceptions\VersionConflict;
use App\Stash\Files\FileStore;
use App\Stash\Images\ImageInspector;
use App\Stash\Images\ImageProcessor;
use App\Stash\Images\ImageRejected;
use App\Stash\Images\VideoInspector;
use App\Stash\Jobs\DeleteStoredFiles;
use App\Stash\Jobs\EnrichResourceMetadata;
use App\Stash\Jobs\ProcessResourceImage;
use App\Stash\Models\Resource;
use App\Stash\Models\ResourceFile;
use App\Stash\Support\UrlNormalizer;
use App\Stash\Taxonomy\ProjectService;
use App\Stash\Taxonomy\TagService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Application service for resources. Both the Inertia controllers and the
 * /api/v1 controllers call this class; it owns every invariant:
 * ownership, reading-state rules, manual-field provenance, version
 * increments and queued work after commit.
 */
final class ResourceService
{
    public const MAX_TITLE = 500;

    public const MAX_DESCRIPTION = 5000;

    public const MAX_NOTES = 100_000;

    public function __construct(
        private readonly TagService $tags,
        private readonly ProjectService $projects,
        private readonly ResourceTagger $tagger,
        private readonly DuplicateDetector $duplicates,
        private readonly ActivityLogger $activity,
        private readonly FileStore $files,
        private readonly ImageInspector $inspector,
        private readonly VideoInspector $videoInspector,
        private readonly ImageProcessor $imageProcessor,
    ) {}

    /**
     * Save a URL (link or article). Returns immediately; metadata is queued
     * after the transaction commits.
     *
     * @param  array<string, mixed>  $data
     * @return array{resource: resource, duplicates: list<array<string, mixed>>}
     */
    public function createFromUrl(User $user, array $data): array
    {
        $type = ResourceType::tryFrom((string) ($data['type'] ?? 'link'));
        if ($type === null || $type === ResourceType::Image) {
            throw new StashException('invalid_type', 'URL resources must be a link or an article.', 422, ['type' => ['Choose link or article.']]);
        }

        $url = trim((string) ($data['url'] ?? ''));
        if (! UrlNormalizer::isValidHttpUrl($url)) {
            throw new StashException('invalid_url', 'Enter a full http(s) URL without credentials.', 422, ['url' => ['Enter a full http(s) URL, e.g. https://example.com/page.']]);
        }

        $duplicates = $this->duplicates->forUrl($user, $url);
        $normalized = UrlNormalizer::normalize($url);

        $resource = DB::transaction(function () use ($user, $data, $type, $url, $normalized) {
            $resource = new Resource;
            $resource->user_id = $user->id;
            $resource->type = $type;
            $resource->original_url = $url;
            $resource->normalized_url = $normalized['normalized'];
            $resource->normalized_url_hash = $normalized['hash'];
            $resource->source_host = $normalized['host'];

            $manual = [];
            $title = $this->cleanText($data['title'] ?? null, self::MAX_TITLE);
            if ($title !== null) {
                $resource->title = $title;
                $manual[] = 'title';
            } else {
                $resource->title = UrlNormalizer::provisionalTitle($url);
            }

            $description = $this->cleanText($data['description'] ?? null, self::MAX_DESCRIPTION);
            if ($description !== null) {
                $resource->description = $description;
                $manual[] = 'description';
            }

            $resource->personal_notes = $this->cleanNotes($data['personal_notes'] ?? null);
            $resource->manual_fields = $manual;
            $this->applyInitialReading($resource, $data);

            if (! empty($data['project_id'])) {
                $resource->project_id = $this->projects->lockOwned($user, (string) $data['project_id'])->id;
            }

            $resource->metadata_status = MetadataStatus::Queued;
            $resource->save();

            if (! empty($data['tags'])) {
                $this->tagger->sync($resource->id, array_values($this->tags->resolveNames($user, (array) $data['tags'])->pluck('id')->all()));
            }

            $this->activity->record($user->id, 'resource.created', 'resource', $resource->id, ['type' => $type->value, 'source' => 'url']);
            EnrichResourceMetadata::dispatch($resource->id);

            return $resource;
        });

        return ['resource' => $this->reload($resource), 'duplicates' => $duplicates];
    }

    /**
     * Save an uploaded image or video. Bytes are validated before anything
     * expensive. Image variants are generated by a queued job after commit; a
     * video keeps its original and uses the optional client-captured poster
     * frame (validated and re-encoded here) as its thumbnail.
     *
     * @param  array<string, mixed>  $data
     * @return array{resource: resource, duplicates: list<array<string, mixed>>}
     */
    public function createFromUpload(User $user, UploadedFile $upload, array $data, ?UploadedFile $poster = null): array
    {
        if (! $upload->isValid()) {
            throw new StashException('upload_failed', 'The upload did not complete. Please try again.', 422, ['file' => ['The upload did not complete. Please try again.']]);
        }

        $isVideo = VideoInspector::isVideo((string) $upload->getRealPath());
        try {
            $info = $isVideo
                ? $this->videoInspector->inspect((string) $upload->getRealPath())
                : $this->inspector->inspect((string) $upload->getRealPath());
        } catch (ImageRejected $e) {
            throw new StashException($isVideo ? 'invalid_video' : 'invalid_image', $e->getMessage(), 422, ['file' => [$e->getMessage()]]);
        }
        $posterVariants = $isVideo && $poster ? $this->posterVariants($poster) : null;

        $attribution = trim((string) ($data['url'] ?? $data['attribution_url'] ?? ''));
        if ($attribution !== '' && ! UrlNormalizer::isValidHttpUrl($attribution)) {
            throw new StashException('invalid_url', 'The attribution URL must be a full http(s) URL.', 422, ['url' => ['Enter a full http(s) URL or leave it empty.']]);
        }

        $duplicates = $this->duplicates->forChecksum($user, $info->sha256);

        $resourceId = Str::lower((string) Str::ulid());
        $key = $this->files->resourceKey($user->id, $resourceId, FileRole::Original->value, $info->extension);
        $this->files->putFile($key, (string) $upload->getRealPath());
        $posterRows = [];
        $writtenKeys = [$key];
        foreach ($posterVariants ?? [] as $role => $variant) {
            $posterKey = $this->files->resourceKey($user->id, $resourceId, $role, 'webp');
            $this->files->put($posterKey, $variant['bytes']);
            $writtenKeys[] = $posterKey;
            $posterRows[$role] = [
                'disk' => $this->files->diskName(),
                'storage_key' => $posterKey,
                'mime_type' => 'image/webp',
                'extension' => 'webp',
                'size_bytes' => strlen($variant['bytes']),
                'width' => $variant['width'],
                'height' => $variant['height'],
                'checksum_sha256' => hash('sha256', $variant['bytes']),
                'status' => 'ready',
            ];
        }

        try {
            $resource = DB::transaction(function () use ($user, $data, $info, $key, $resourceId, $attribution, $upload, $isVideo, $posterRows) {
                $resource = new Resource;
                $resource->id = $resourceId;
                $resource->user_id = $user->id;
                $resource->type = $isVideo ? ResourceType::Video : ResourceType::Image;

                $manual = [];
                $title = $this->cleanText($data['title'] ?? null, self::MAX_TITLE);
                if ($title !== null) {
                    $manual[] = 'title';
                }
                $resource->title = $title ?? $this->titleFromFilename($upload->getClientOriginalName(), $isVideo ? 'Video' : 'Image');
                $description = $this->cleanText($data['description'] ?? null, self::MAX_DESCRIPTION);
                if ($description !== null) {
                    $resource->description = $description;
                    $manual[] = 'description';
                }
                $resource->manual_fields = $manual;
                $resource->personal_notes = $this->cleanNotes($data['personal_notes'] ?? null);

                if ($attribution !== '') {
                    $normalized = UrlNormalizer::normalize($attribution);
                    $resource->original_url = $attribution;
                    $resource->source_host = $normalized['host'];
                }

                if (! empty($data['project_id'])) {
                    $resource->project_id = $this->projects->lockOwned($user, (string) $data['project_id'])->id;
                }

                $resource->metadata_status = $isVideo ? MetadataStatus::Ready : MetadataStatus::Queued;
                $resource->save();

                ResourceFile::query()->create([
                    'resource_id' => $resource->id,
                    'user_id' => $user->id,
                    'role' => FileRole::Original,
                    'disk' => $this->files->diskName(),
                    'storage_key' => $key,
                    'mime_type' => $info->mime,
                    'extension' => $info->extension,
                    'size_bytes' => $info->size,
                    'width' => $info->width,
                    'height' => $info->height,
                    'checksum_sha256' => $info->sha256,
                    'status' => 'ready',
                ]);

                foreach ($posterRows as $role => $row) {
                    ResourceFile::query()->create($row + ['resource_id' => $resource->id, 'user_id' => $user->id, 'role' => $role]);
                }

                if (! empty($data['tags'])) {
                    $this->tagger->sync($resource->id, array_values($this->tags->resolveNames($user, (array) $data['tags'])->pluck('id')->all()));
                }

                $this->activity->record($user->id, 'resource.created', 'resource', $resource->id, ['type' => $isVideo ? 'video' : 'image', 'source' => 'upload', 'bytes' => $info->size]);
                if (! $isVideo) {
                    ProcessResourceImage::dispatch($resource->id);
                }

                return $resource;
            });
        } catch (Throwable $e) {
            foreach ($writtenKeys as $written) {
                $this->files->delete($this->files->diskName(), $written);
            }
            throw $e;
        }

        return ['resource' => $this->reload($resource), 'duplicates' => $duplicates];
    }

    /**
     * Validate and re-encode a client-captured poster frame. A bad poster
     * never fails the video upload; the card just shows the fallback.
     *
     * @return array<string, array{bytes: string, width: int, height: int}>|null
     */
    private function posterVariants(UploadedFile $poster): ?array
    {
        try {
            if (! $poster->isValid()) {
                return null;
            }
            $path = (string) $poster->getRealPath();
            $this->inspector->inspect($path, ['image/jpeg', 'image/png', 'image/webp'], (int) config('stash.videos.poster_max_bytes'));
            $variants = $this->imageProcessor->variants($path);

            return [FileRole::Thumbnail->value => $variants['thumbnail'], FileRole::Preview->value => $variants['preview']];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Partial update with an optional optimistic-concurrency precondition.
     * Recognised keys: type, url, title, description, personal_notes, tags
     * (full replacement by name), project_id, read_later, reading_status.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws VersionConflict
     */
    public function update(User $user, Resource $resource, array $data, ?int $expectedVersion = null): Resource
    {
        $queueEnrichment = false;

        $updated = DB::transaction(function () use ($user, $resource, $data, $expectedVersion, &$queueEnrichment) {
            $r = $this->lock($user, $resource->id);
            $this->assertVersion($r, $expectedVersion);

            $changed = [];
            $manual = $r->manual_fields ?? [];

            if (array_key_exists('url', $data)) {
                $url = trim((string) $data['url']);
                if ($url === '' && $r->type->isUpload()) {
                    if ($r->original_url !== null) {
                        $r->original_url = null;
                        $r->normalized_url = null;
                        $r->normalized_url_hash = null;
                        $r->source_host = null;
                        $changed[] = 'url';
                    }
                } elseif ($url !== ($r->original_url ?? '')) {
                    if (! UrlNormalizer::isValidHttpUrl($url)) {
                        throw new StashException('invalid_url', 'Enter a full http(s) URL without credentials.', 422, ['url' => ['Enter a full http(s) URL.']]);
                    }
                    $normalized = UrlNormalizer::normalize($url);
                    $r->original_url = $url;
                    $r->source_host = $normalized['host'];
                    if (! $r->type->isUpload()) {
                        $r->normalized_url = $normalized['normalized'];
                        $r->normalized_url_hash = $normalized['hash'];
                        $queueEnrichment = true;
                    }
                    $changed[] = 'url';
                }
            }

            if (array_key_exists('type', $data)) {
                $type = ResourceType::tryFrom((string) $data['type']);
                if ($type === null) {
                    throw new StashException('invalid_type', 'Unknown resource type.', 422, ['type' => ['Choose link, image or article.']]);
                }
                if ($type !== $r->type) {
                    $queueEnrichment = $this->changeType($r, $type) || $queueEnrichment;
                    $changed[] = 'type';
                }
            }

            if (array_key_exists('title', $data)) {
                $title = $this->cleanText($data['title'], self::MAX_TITLE);
                if ($title === null) {
                    throw new StashException('invalid_title', 'Title is required.', 422, ['title' => ['Title is required.']]);
                }
                if ($title !== $r->title) {
                    $r->title = $title;
                    $manual[] = 'title';
                    $changed[] = 'title';
                }
            }

            if (array_key_exists('description', $data)) {
                $description = $this->cleanText($data['description'], self::MAX_DESCRIPTION);
                if ($description !== $r->description) {
                    $r->description = $description;
                    $manual[] = 'description';
                    $changed[] = 'description';
                }
            }

            if (array_key_exists('personal_notes', $data)) {
                $notes = $this->cleanNotes($data['personal_notes']);
                if ($notes !== $r->personal_notes) {
                    $r->personal_notes = $notes;
                    $changed[] = 'personal_notes';
                }
            }

            if (array_key_exists('reading_status', $data) && $data['reading_status'] !== null) {
                if ($this->setReadingStatus($r, (string) $data['reading_status'])) {
                    $changed[] = 'reading_status';
                }
            }

            if (array_key_exists('read_later', $data)) {
                if ($this->setReadLater($r, (bool) $data['read_later'])) {
                    $changed[] = 'read_later';
                }
            }

            if (array_key_exists('project_id', $data)) {
                $projectId = $data['project_id'] ? (string) $data['project_id'] : null;
                if ($projectId !== null) {
                    $projectId = $this->projects->lockOwned($user, $projectId)->id;
                }
                if ($projectId !== $r->project_id) {
                    $r->project_id = $projectId;
                    $changed[] = 'project';
                }
            }

            if (array_key_exists('tags', $data)) {
                $tagIds = array_values($this->tags->resolveNames($user, (array) ($data['tags'] ?? []))->pluck('id')->all());
                if ($this->tagger->sync($r->id, $tagIds)) {
                    $changed[] = 'tags';
                }
            }

            if ($changed === []) {
                return $r;
            }

            $r->manual_fields = array_values(array_unique($manual));
            if ($queueEnrichment) {
                $r->metadata_status = MetadataStatus::Queued;
                $r->metadata_error = null;
            }
            $r->version = $r->version + 1;
            $r->save();

            $this->activity->record($user->id, 'resource.updated', 'resource', $r->id, ['fields' => array_values(array_unique($changed))]);

            if ($queueEnrichment) {
                EnrichResourceMetadata::dispatch($r->id);
            }

            return $r;
        });

        return $this->reload($updated);
    }

    public function archive(User $user, Resource $resource, ?int $expectedVersion = null): Resource
    {
        return $this->lifecycle($user, $resource, $expectedVersion, 'resource.archived', function (Resource $r) {
            if ($r->trashed()) {
                throw new StashException('in_trash', 'Restore this resource from Trash before archiving it.', 409);
            }
            if ($r->archived_at !== null) {
                return false;
            }
            $r->archived_at = now();

            return true;
        });
    }

    public function unarchive(User $user, Resource $resource, ?int $expectedVersion = null): Resource
    {
        return $this->lifecycle($user, $resource, $expectedVersion, 'resource.unarchived', function (Resource $r) {
            if ($r->archived_at === null) {
                return false;
            }
            $r->archived_at = null;

            return true;
        });
    }

    /** Move to Trash (soft delete). Archive state is preserved for restore. */
    public function trash(User $user, Resource $resource, ?int $expectedVersion = null): Resource
    {
        return $this->lifecycle($user, $resource, $expectedVersion, 'resource.trashed', function (Resource $r) {
            if ($r->trashed()) {
                return false;
            }
            $r->deleted_at = now();

            return true;
        });
    }

    public function restore(User $user, Resource $resource, ?int $expectedVersion = null): Resource
    {
        return $this->lifecycle($user, $resource, $expectedVersion, 'resource.restored', function (Resource $r) {
            if (! $r->trashed()) {
                return false;
            }
            $r->deleted_at = null;

            return true;
        });
    }

    /**
     * Permanently delete a trashed resource and its exclusively owned files.
     * Files are removed by a retrying job after commit; missing files are
     * tolerated.
     */
    public function forceDelete(User $user, Resource $resource): void
    {
        DB::transaction(function () use ($user, $resource) {
            $r = $this->lock($user, $resource->id);
            if (! $r->trashed()) {
                throw new StashException('not_in_trash', 'Move the resource to Trash before deleting it permanently.', 409);
            }

            $files = ResourceFile::query()->where('resource_id', $r->id)->where('user_id', $user->id)
                ->get(['disk', 'storage_key'])->map(fn ($f) => [$f->disk, $f->storage_key])->all();

            // Keep token accounting, drop any content derived from the resource.
            DB::table('ai_runs')->where('resource_id', $r->id)->update(['result' => null, 'input_text' => null]);

            $r->forceDelete();
            $this->activity->record($user->id, 'resource.deleted_permanently', 'resource', $r->id, ['files' => count($files)]);

            if ($files !== []) {
                DeleteStoredFiles::dispatch(array_values($files));
            }
        });
    }

    /** Re-queue metadata enrichment (links/articles) or image processing. */
    public function refresh(User $user, Resource $resource): Resource
    {
        $r = DB::transaction(function () use ($user, $resource) {
            $r = $this->lock($user, $resource->id);
            if ($r->trashed()) {
                throw new StashException('in_trash', 'Restore this resource before refreshing it.', 409);
            }
            $r->metadata_status = MetadataStatus::Queued;
            $r->metadata_error = null;
            $r->save();

            if ($r->type === ResourceType::Video) {
                $r->metadata_status = MetadataStatus::Ready;
                $r->save();
            } elseif ($r->type === ResourceType::Image) {
                ProcessResourceImage::dispatch($r->id);
            } elseif ($r->original_url) {
                EnrichResourceMetadata::dispatch($r->id);
            }
            $this->activity->record($user->id, 'resource.refresh_requested', 'resource', $r->id);

            return $r;
        });

        return $this->reload($r);
    }

    /**
     * Explicit "replace from source": copy previously fetched values over
     * manually edited fields and clear their manual flag.
     *
     * @param  list<string>  $fields
     */
    public function applySourceValues(User $user, Resource $resource, array $fields, ?int $expectedVersion = null): Resource
    {
        $r = DB::transaction(function () use ($user, $resource, $fields, $expectedVersion) {
            $r = $this->lock($user, $resource->id);
            $this->assertVersion($r, $expectedVersion);
            $fetched = (array) ($r->metadata['fetched'] ?? []);
            $manual = $r->manual_fields ?? [];
            $changed = [];

            foreach (array_intersect($fields, Resource::ENRICHABLE_FIELDS) as $field) {
                $value = $fetched[$field] ?? null;
                if (! is_string($value) || $value === '') {
                    continue;
                }
                $value = $field === 'title' ? mb_substr($value, 0, self::MAX_TITLE) : mb_substr($value, 0, self::MAX_DESCRIPTION);
                if ($r->{$field} !== $value) {
                    $r->{$field} = $value;
                    $changed[] = $field;
                }
                $manual = array_values(array_diff($manual, [$field]));
            }

            $r->manual_fields = $manual;
            if ($changed !== []) {
                $r->version = $r->version + 1;
            }
            $r->save();
            $this->activity->record($user->id, 'resource.source_applied', 'resource', $r->id, ['fields' => $changed]);

            return $r;
        });

        return $this->reload($r);
    }

    public function reload(Resource $resource): Resource
    {
        return Resource::withTrashed()
            ->with(['tags:id,name,normalized_name,color', 'project:id,name,color,icon', 'files'])
            ->findOrFail($resource->id);
    }

    public function lock(User $user, string $id): Resource
    {
        /** @var resource|null $r */
        $r = Resource::withTrashed()->where('user_id', $user->id)->whereKey($id)->lockForUpdate()->first();
        if (! $r) {
            throw new StashException('not_found', 'Resource not found.', 404);
        }

        return $r;
    }

    public function assertVersion(Resource $r, ?int $expected): void
    {
        if ($expected !== null && $r->version !== $expected) {
            throw new VersionConflict($this->reload($r), $expected);
        }
    }

    /**
     * Apply a reading-status change. Articles only.
     */
    public function setReadingStatus(Resource $r, string $status): bool
    {
        $status = ReadingStatus::tryFrom($status);
        if ($status === null) {
            throw new StashException('invalid_reading_status', 'Reading status must be unread or read.', 422, ['reading_status' => ['Choose unread or read.']]);
        }
        if (! $r->isArticle()) {
            throw new StashException('not_article', 'Reading status applies to articles only.', 422, ['reading_status' => ['Reading status applies to articles only.']]);
        }
        if ($r->reading_status === $status) {
            return false;
        }

        $r->reading_status = $status;
        $r->read_at = $status === ReadingStatus::Read ? now() : null;

        return true;
    }

    public function setReadLater(Resource $r, bool $value): bool
    {
        if ($value && ! $r->isArticle()) {
            throw new StashException('not_article', 'Read later is available for articles only.', 422, ['read_later' => ['Read later is available for articles only.']]);
        }
        if ($r->read_later === $value) {
            return false;
        }
        $r->read_later = $value;

        return true;
    }

    /**
     * @param  callable(resource): bool  $mutate
     */
    private function lifecycle(User $user, Resource $resource, ?int $expectedVersion, string $operation, callable $mutate): Resource
    {
        $r = DB::transaction(function () use ($user, $resource, $expectedVersion, $operation, $mutate) {
            $r = $this->lock($user, $resource->id);
            $this->assertVersion($r, $expectedVersion);
            if ($mutate($r)) {
                $r->version = $r->version + 1;
                $r->save();
                $this->activity->record($user->id, $operation, 'resource', $r->id);
            }

            return $r;
        });

        return $this->reload($r);
    }

    /** @param array<string, mixed> $data */
    private function applyInitialReading(Resource $r, array $data): void
    {
        if ($r->type === ResourceType::Article) {
            $status = ReadingStatus::tryFrom((string) ($data['reading_status'] ?? 'unread')) ?? ReadingStatus::Unread;
            $r->reading_status = $status;
            $r->read_at = $status === ReadingStatus::Read ? now() : null;
            $r->read_later = (bool) ($data['read_later'] ?? false);

            return;
        }

        if (! empty($data['read_later'])) {
            throw new StashException('not_article', 'Read later is available for articles only.', 422, ['read_later' => ['Read later is available for articles only.']]);
        }
        if (! empty($data['reading_status'])) {
            throw new StashException('not_article', 'Reading status applies to articles only.', 422, ['reading_status' => ['Reading status applies to articles only.']]);
        }
        $r->reading_status = null;
        $r->read_at = null;
        $r->read_later = false;
    }

    /**
     * Validate and apply a type change. Returns true when metadata should be
     * (re)fetched.
     */
    private function changeType(Resource $r, ResourceType $type): bool
    {
        if (($type === ResourceType::Video || $r->type === ResourceType::Video) && $type !== $r->type) {
            throw new StashException('video_locked', 'Uploaded videos keep the video type, and other resources cannot become videos.', 422, ['type' => ['Videos keep the video type.']]);
        }

        if ($type->requiresUrl() && ! $r->original_url) {
            throw new StashException('url_required', 'Links and articles need a source URL.', 422, ['url' => ['Add a source URL before changing the type.']]);
        }

        if ($type === ResourceType::Image && ! $r->files()->where('role', FileRole::Original->value)->exists()) {
            throw new StashException('image_required', 'Only resources with an uploaded image can become images.', 422, ['type' => ['Upload an image to use the image type.']]);
        }

        $wasImage = $r->type === ResourceType::Image;
        $r->type = $type;

        if ($type === ResourceType::Article) {
            // Converting to an article initializes unread status.
            $r->reading_status = ReadingStatus::Unread;
            $r->read_at = null;
            $r->read_later = false;
        } else {
            $r->reading_status = null;
            $r->read_at = null;
            $r->read_later = false;
        }

        if ($type === ResourceType::Image) {
            $r->normalized_url = null;
            $r->normalized_url_hash = null;

            return false;
        }

        $normalized = UrlNormalizer::normalize((string) $r->original_url);
        $r->normalized_url = $normalized['normalized'];
        $r->normalized_url_hash = $normalized['hash'];

        return $wasImage;
    }

    private function cleanText(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function cleanNotes(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = str_replace("\r\n", "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

        return trim($value) === '' ? null : mb_substr($value, 0, self::MAX_NOTES);
    }

    private function titleFromFilename(string $name, string $fallback = 'Image'): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = preg_replace('/[^\p{L}\p{N} ._()\-]+/u', ' ', $base) ?? '';
        $base = trim(preg_replace('/[\s_]+/u', ' ', $base) ?? '');

        return $base !== '' ? mb_substr($base, 0, 120) : $fallback.' '.now()->format('Y-m-d H:i');
    }
}
