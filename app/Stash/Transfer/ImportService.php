<?php

namespace App\Stash\Transfer;

use App\Models\User;
use App\Stash\Activity\ActivityLogger;
use App\Stash\Enums\AiRunStatus;
use App\Stash\Enums\FileRole;
use App\Stash\Enums\MetadataStatus;
use App\Stash\Enums\ResourceType;
use App\Stash\Exceptions\StashException;
use App\Stash\Files\FileStore;
use App\Stash\Images\ImageInspector;
use App\Stash\Images\ImageRejected;
use App\Stash\Jobs\AnalyzeImport;
use App\Stash\Jobs\ApplyImport;
use App\Stash\Jobs\ProcessResourceImage;
use App\Stash\Models\AiRun;
use App\Stash\Models\DataImport;
use App\Stash\Models\Project;
use App\Stash\Models\Resource;
use App\Stash\Models\ResourceFile;
use App\Stash\Models\Tag;
use App\Stash\Resources\ResourceFilters;
use App\Stash\Resources\ResourceTagger;
use App\Stash\Support\TagName;
use App\Stash\Support\UrlNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Round-trip import of Stash JSON/ZIP exports into the same owner.
 *
 * Flow: upload → queued analysis (validation, collisions, duplicate
 * warnings) → user confirms a collision strategy → queued apply with
 * per-resource transactions and a partial error report. Existing records
 * are never overwritten: collisions are skipped or imported as copies with
 * new ids; ids owned by other accounts always get new ids.
 */
final class ImportService
{
    public const STRATEGIES = ['skip', 'duplicate'];

    public function __construct(
        private readonly FileStore $files,
        private readonly ImageInspector $inspector,
        private readonly ResourceTagger $tagger,
        private readonly ActivityLogger $activity,
    ) {}

    public function upload(User $user, UploadedFile $file): DataImport
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['json', 'zip'], true)) {
            throw new StashException('invalid_import', 'Upload a Stash .json or .zip export.', 422, ['file' => ['Upload a Stash .json or .zip export.']]);
        }
        if ($file->getSize() > (int) config('stash.imports.max_upload_bytes')) {
            throw new StashException('import_too_large', 'The import file is too large.', 422, ['file' => ['The import file is too large.']]);
        }

        $import = new DataImport([
            'format' => $extension,
            'status' => 'uploaded',
            'disk' => $this->files->diskName(),
            'storage_key' => 'pending',
            'original_filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'size_bytes' => (int) $file->getSize(),
            'expires_at' => now()->addHours((int) config('stash.imports.ttl_hours', 72)),
        ]);
        $import->user_id = $user->id;
        $import->save();

        $key = $this->files->importKey($user->id, $import->id, $extension);
        $this->files->putFile($key, (string) $file->getRealPath());
        $import->update(['storage_key' => $key]);

        $this->activity->record($user->id, 'import.uploaded', 'import', $import->id, ['format' => $extension, 'bytes' => $import->size_bytes]);
        AnalyzeImport::dispatch($import->id);

        return $import;
    }

    public function analyze(DataImport $import): void
    {
        $import->update(['status' => 'analyzing', 'error' => null]);
        $user = User::query()->findOrFail($import->user_id);

        try {
            $loaded = $this->load($import);
            $preview = $this->buildPreview($user, $loaded);
            $import->update(['status' => $preview['valid'] ? 'ready' : 'invalid', 'preview' => $preview]);
        } catch (StashException $e) {
            $import->update(['status' => 'invalid', 'error' => mb_substr($e->getMessage(), 0, 255), 'preview' => null]);
        } finally {
            $this->cleanup($loaded ?? null);
        }
    }

    public function confirm(User $user, DataImport $import, string $strategy): DataImport
    {
        if (! in_array($strategy, self::STRATEGIES, true)) {
            throw new StashException('invalid_strategy', 'Choose skip or duplicate.', 422, ['collision_strategy' => ['Choose skip or duplicate.']]);
        }
        $updated = DataImport::query()->whereKey($import->id)->where('user_id', $user->id)->where('status', 'ready')
            ->update(['status' => 'applying', 'collision_strategy' => $strategy]);
        if ($updated !== 1) {
            throw new StashException('import_not_ready', 'This import is not ready to apply.', 409);
        }

        ApplyImport::dispatch($import->id);

        return $import->refresh();
    }

    public function apply(DataImport $import): void
    {
        $user = User::query()->findOrFail($import->user_id);
        $report = ['created' => ['resources' => 0, 'projects' => 0, 'tags' => 0, 'files' => 0, 'summaries' => 0], 'reused' => ['projects' => 0, 'tags' => 0], 'skipped' => [], 'errors' => []];

        $loaded = null;
        try {
            $loaded = $this->load($import);
            $doc = $loaded['document'];
            $strategy = $import->collision_strategy;

            $projectMap = $this->importProjects($user, $doc['projects'] ?? [], $report);
            $tagMap = $this->importTags($user, $doc['tags'] ?? [], $report);

            foreach ($doc['resources'] ?? [] as $index => $raw) {
                try {
                    $item = $this->validateResource($raw, $index);
                } catch (StashException $e) {
                    $report['errors'][] = ['index' => $index, 'id' => is_array($raw) ? ($raw['id'] ?? null) : null, 'message' => $e->getMessage()];

                    continue;
                }

                try {
                    $outcome = $this->importResource($user, $item, $strategy, $projectMap, $tagMap, $loaded, $report);
                    if ($outcome !== null) {
                        $report['skipped'][] = ['id' => $item['id'], 'reason' => $outcome];
                    }
                } catch (Throwable $e) {
                    $report['errors'][] = ['index' => $index, 'id' => $item['id'], 'message' => $e instanceof StashException ? $e->getMessage() : 'Could not import this resource.'];
                }
            }

            $report['skipped'] = array_slice($report['skipped'], 0, 500);
            $report['errors'] = array_slice($report['errors'], 0, 500);
            $import->update(['status' => 'completed', 'report' => $report]);
            $this->activity->record($user->id, 'import.completed', 'import', $import->id, $report['created']);
        } catch (StashException $e) {
            $import->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 255), 'report' => $report]);
        } finally {
            $this->cleanup($loaded);
        }
    }

    public function discard(User $user, DataImport $import): void
    {
        if ($import->user_id !== $user->id) {
            throw new StashException('not_found', 'Import not found.', 404);
        }
        if ($import->storage_key && $import->storage_key !== 'pending') {
            $this->files->delete($import->disk, $import->storage_key);
        }
        $import->delete();
    }

    /**
     * @return array{document: array<string, mixed>, zip: ?SafeZipReader, manifest: ?array<string, mixed>, temp: ?string, warnings: list<string>}
     */
    private function load(DataImport $import): array
    {
        $temp = tempnam(sys_get_temp_dir(), 'stashi');
        if ($temp === false) {
            throw new StashException('io', 'Unable to stage the import.', 500);
        }
        $this->files->withLocalPath($import->disk, $import->storage_key, fn (string $path) => copy($path, $temp));

        $maxJson = (int) config('stash.imports.max_json_bytes');
        $zip = null;
        $manifest = null;
        $warnings = [];

        if ($import->format === 'zip') {
            $zip = new SafeZipReader($temp);
            $warnings = $zip->warnings;
            if (! $zip->has('manifest.json') || ! $zip->has('data.json')) {
                throw new StashException('invalid_archive', 'This ZIP is not a Stash export (manifest.json and data.json are required). Obsidian snapshots cannot be imported.', 422);
            }
            $manifest = json_decode($zip->read('manifest.json', 1024 * 1024 * 8), true);
            if (! is_array($manifest) || ($manifest['schema'] ?? null) !== 'stash.export.manifest' || ! is_array($manifest['files'] ?? null)) {
                throw new StashException('invalid_manifest', 'The archive manifest is missing or invalid.', 422);
            }
            $json = $zip->read('data.json', $maxJson);
            $expected = collect($manifest['files'])->firstWhere('path', 'data.json');
            if (! $expected || ! hash_equals((string) ($expected['sha256'] ?? ''), hash('sha256', $json))) {
                throw new StashException('checksum_mismatch', 'data.json does not match the manifest checksum.', 422);
            }
        } else {
            if (filesize($temp) > $maxJson) {
                throw new StashException('import_too_large', 'The JSON document is too large.', 422);
            }
            $json = (string) file_get_contents($temp);
        }

        try {
            $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new StashException('invalid_json', 'The export JSON could not be parsed.', 422);
        }

        if (! is_array($document) || ($document['schema'] ?? null) !== ExportDataBuilder::SCHEMA) {
            throw new StashException('invalid_schema', 'This file is not a Stash export.', 422);
        }
        $version = (int) ($document['schema_version'] ?? 0);
        if ($version < 1 || $version > ExportDataBuilder::SCHEMA_VERSION) {
            throw new StashException('unsupported_version', "Unsupported export schema version {$version}.", 422);
        }
        foreach (['projects', 'tags', 'resources'] as $key) {
            if (isset($document[$key]) && ! is_array($document[$key])) {
                throw new StashException('invalid_schema', "The {$key} section is invalid.", 422);
            }
        }

        return ['document' => $document, 'zip' => $zip, 'manifest' => $manifest, 'temp' => $temp, 'warnings' => $warnings];
    }

    /**
     * @param  array{document: array<string, mixed>, zip: ?SafeZipReader, manifest: ?array<string, mixed>, temp: ?string, warnings: list<string>}  $loaded
     * @return array<string, mixed>
     */
    private function buildPreview(User $user, array $loaded): array
    {
        $doc = $loaded['document'];
        $errors = [];
        $valid = [];
        foreach ($doc['resources'] ?? [] as $index => $raw) {
            try {
                $valid[] = $this->validateResource($raw, $index);
            } catch (StashException $e) {
                $errors[] = ['index' => $index, 'id' => is_array($raw) ? ($raw['id'] ?? null) : null, 'message' => $e->getMessage()];
            }
        }

        $ids = array_column($valid, 'id');
        $ownCollisions = Resource::withTrashed()->where('user_id', $user->id)->whereIn('id', $ids)->count();
        $foreignCollisions = Resource::withTrashed()->where('user_id', '!=', $user->id)->whereIn('id', $ids)->count();

        $duplicates = [];
        foreach ($valid as $item) {
            if (count($duplicates) >= 50) {
                break;
            }
            $match = null;
            if ($item['url'] && $item['type'] !== 'image') {
                $hash = UrlNormalizer::normalize($item['url'])['hash'];
                $match = Resource::withTrashed()->where('user_id', $user->id)->where('normalized_url_hash', $hash)->whereKeyNot($item['id'])->first(['id', 'title']);
            }
            if (! $match) {
                foreach ($item['files'] as $file) {
                    $rid = ResourceFile::query()->where('user_id', $user->id)->where('role', 'original')->where('checksum_sha256', $file['sha256'])->where('resource_id', '!=', $item['id'])->value('resource_id');
                    if ($rid) {
                        $match = Resource::withTrashed()->whereKey($rid)->first(['id', 'title']);
                        break;
                    }
                }
            }
            if ($match) {
                $duplicates[] = ['import_id' => $item['id'], 'title' => $item['title'], 'existing_id' => $match->id, 'existing_title' => $match->title];
            }
        }

        $attachmentCount = 0;
        $missingAttachments = 0;
        if ($loaded['zip']) {
            foreach ($valid as $item) {
                foreach ($item['files'] as $file) {
                    $attachmentCount++;
                    if (! $file['path'] || ! $loaded['zip']->has($file['path'])) {
                        $missingAttachments++;
                    }
                }
            }
        }

        $projectIds = array_column(array_filter($doc['projects'] ?? [], 'is_array'), 'id');
        $existingProjects = Project::query()->where('user_id', $user->id)->whereIn('id', array_filter($projectIds, 'is_string'))->count();
        $tagNames = array_map(fn ($t) => TagName::normalize((string) ($t['name'] ?? '')), array_filter($doc['tags'] ?? [], 'is_array'));
        $existingTags = Tag::query()->where('user_id', $user->id)->whereIn('normalized_name', $tagNames)->count();

        return [
            'valid' => true,
            'schema_version' => (int) $doc['schema_version'],
            'exported_at' => $doc['exported_at'] ?? null,
            'counts' => [
                'resources' => count($doc['resources'] ?? []),
                'valid_resources' => count($valid),
                'projects' => count($doc['projects'] ?? []),
                'tags' => count($doc['tags'] ?? []),
                'attachments' => $attachmentCount,
            ],
            'collisions' => [
                'resources_same_owner' => $ownCollisions,
                'resources_other_owner' => $foreignCollisions,
                'projects_existing' => $existingProjects,
                'tags_existing' => $existingTags,
            ],
            'missing_attachments' => $missingAttachments,
            'duplicates' => $duplicates,
            'errors' => array_slice($errors, 0, 100),
            'error_count' => count($errors),
            'warnings' => array_slice($loaded['warnings'], 0, 50),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws StashException
     */
    private function validateResource(mixed $raw, int|string $index): array
    {
        $fail = fn (string $m) => throw new StashException('invalid_resource', "Resource #{$index}: {$m}", 422);

        if (! is_array($raw)) {
            $fail('not an object.');
        }
        $id = $raw['id'] ?? null;
        if (! ResourceFilters::isId($id)) {
            $fail('invalid id.');
        }
        $type = ResourceType::tryFrom((string) ($raw['type'] ?? ''));
        if (! $type || $type === ResourceType::Video) {
            $fail('invalid type.');
        }
        $title = is_string($raw['title'] ?? null) ? trim(mb_substr($raw['title'], 0, 500)) : '';
        if ($title === '') {
            $fail('missing title.');
        }
        $url = $raw['url'] ?? null;
        if ($url !== null && (! is_string($url) || ! UrlNormalizer::isValidHttpUrl($url))) {
            $fail('invalid URL.');
        }
        if ($type->requiresUrl() && ! $url) {
            $fail('links and articles need a URL.');
        }

        $files = [];
        foreach (is_array($raw['files'] ?? null) ? $raw['files'] : [] as $file) {
            if (! is_array($file) || ($file['role'] ?? null) !== 'original') {
                continue;
            }
            $sha = (string) ($file['sha256'] ?? '');
            $path = $file['path'] ?? null;
            if (! preg_match('/^[a-f0-9]{64}$/', $sha)) {
                $fail('invalid file checksum.');
            }
            if ($path !== null && (! is_string($path) || ! preg_match('#^attachments/[0-9a-hjkmnp-tv-z]{26}\.(jpg|jpeg|png|webp|gif)$#i', $path))) {
                $fail('invalid attachment path.');
            }
            $files[] = ['id' => $file['id'] ?? null, 'sha256' => $sha, 'path' => $path];
        }
        if ($type === ResourceType::Image && $files === []) {
            $fail('images need an original file.');
        }

        $date = function (mixed $value, bool $required = false) use ($fail): ?CarbonImmutable {
            if ($value === null || $value === '') {
                if ($required) {
                    $fail('missing timestamp.');
                }

                return null;
            }
            try {
                return CarbonImmutable::parse((string) $value)->utc();
            } catch (Throwable) {
                $fail('invalid timestamp.');
            }
        };

        $isArticle = $type === ResourceType::Article;
        $reading = $isArticle ? (in_array($raw['reading_status'] ?? null, ['unread', 'read'], true) ? $raw['reading_status'] : 'unread') : null;
        $readAt = $reading === 'read' ? ($date($raw['read_at'] ?? null) ?? $date($raw['updated_at'] ?? null) ?? CarbonImmutable::now()) : null;

        return [
            'id' => strtolower((string) $id),
            'type' => $type->value,
            'title' => $title,
            'description' => is_string($raw['description'] ?? null) ? mb_substr($raw['description'], 0, 5000) : null,
            'personal_notes' => is_string($raw['personal_notes'] ?? null) ? mb_substr($raw['personal_notes'], 0, 100000) : null,
            'url' => $url,
            'project_id' => is_string($raw['project_id'] ?? null) ? strtolower($raw['project_id']) : null,
            'tag_ids' => array_values(array_filter((array) ($raw['tag_ids'] ?? []), 'is_string')),
            'read_later' => $isArticle && (bool) ($raw['read_later'] ?? false),
            'reading_status' => $reading,
            'read_at' => $readAt,
            'archived_at' => $date($raw['archived_at'] ?? null),
            'deleted_at' => $date($raw['deleted_at'] ?? null),
            'created_at' => $date($raw['created_at'] ?? null, true),
            'updated_at' => $date($raw['updated_at'] ?? null) ?? $date($raw['created_at'] ?? null),
            'manual_fields' => array_values(array_intersect((array) ($raw['manual_fields'] ?? []), Resource::ENRICHABLE_FIELDS)),
            'metadata' => is_array($raw['metadata'] ?? null) ? array_intersect_key($raw['metadata'], array_flip(['site_name', 'fetched'])) : [],
            'files' => $files,
            'summaries' => array_values(array_filter((array) ($raw['summaries'] ?? []), fn ($s) => is_array($s) && is_string($s['summary'] ?? null))),
        ];
    }

    /**
     * @param  list<mixed>  $projects
     * @param  array<string, mixed>  $report
     * @return array<string, string> export id => local id
     */
    private function importProjects(User $user, array $projects, array &$report): array
    {
        $map = [];
        foreach ($projects as $p) {
            if (! is_array($p) || ! ResourceFilters::isId($p['id'] ?? null) || ! is_string($p['name'] ?? null) || trim($p['name']) === '') {
                continue;
            }
            $id = strtolower($p['id']);
            $own = Project::query()->where('user_id', $user->id)->whereKey($id)->first();
            if ($own) {
                $map[$id] = $own->id;
                $report['reused']['projects']++;

                continue;
            }
            $project = new Project(['name' => mb_substr(trim($p['name']), 0, 120), 'description' => is_string($p['description'] ?? null) ? $p['description'] : null, 'color' => is_string($p['color'] ?? null) ? mb_substr($p['color'], 0, 16) : null, 'icon' => is_string($p['icon'] ?? null) ? mb_substr($p['icon'], 0, 40) : null]);
            $project->user_id = $user->id;
            // Keep the original id unless another owner already uses it.
            $project->id = Project::query()->whereKey($id)->exists() ? Str::lower((string) Str::ulid()) : $id;
            $project->save();
            $map[$id] = $project->id;
            $report['created']['projects']++;
        }

        return $map;
    }

    /**
     * @param  list<mixed>  $tags
     * @param  array<string, mixed>  $report
     * @return array<string, string>
     */
    private function importTags(User $user, array $tags, array &$report): array
    {
        $map = [];
        foreach ($tags as $t) {
            if (! is_array($t) || ! ResourceFilters::isId($t['id'] ?? null) || ! is_string($t['name'] ?? null)) {
                continue;
            }
            $clean = TagName::clean($t['name']);
            if ($clean === '') {
                continue;
            }
            $normalized = TagName::normalize($clean);
            $existing = Tag::query()->where('user_id', $user->id)->where('normalized_name', $normalized)->first();
            if ($existing) {
                $map[strtolower($t['id'])] = $existing->id;
                $report['reused']['tags']++;

                continue;
            }
            $tag = new Tag(['name' => $clean, 'normalized_name' => $normalized, 'color' => is_string($t['color'] ?? null) ? mb_substr($t['color'], 0, 16) : null]);
            $tag->user_id = $user->id;
            $tag->id = Tag::query()->whereKey(strtolower($t['id']))->exists() ? Str::lower((string) Str::ulid()) : strtolower($t['id']);
            $tag->save();
            $map[strtolower($t['id'])] = $tag->id;
            $report['created']['tags']++;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, string>  $projectMap
     * @param  array<string, string>  $tagMap
     * @param  array<string, mixed>  $loaded
     * @param  array<string, mixed>  $report
     * @return string|null skip reason
     */
    private function importResource(User $user, array $item, string $strategy, array $projectMap, array $tagMap, array $loaded, array &$report): ?string
    {
        $id = $item['id'];
        $ownExisting = Resource::withTrashed()->where('user_id', $user->id)->whereKey($id)->exists();
        if ($ownExisting && $strategy === 'skip') {
            return 'id_exists';
        }
        if ($ownExisting || Resource::withTrashed()->whereKey($id)->exists()) {
            $id = Str::lower((string) Str::ulid());
        }

        // Validate and stage attachments before touching the database.
        $staged = [];
        $stored = [];
        try {
            foreach ($item['files'] as $file) {
                if (! $loaded['zip'] || ! $file['path'] || ! $loaded['zip']->has($file['path'])) {
                    if ($item['type'] === 'image') {
                        return 'missing_attachment';
                    }

                    continue;
                }
                $tmp = $loaded['zip']->extractToTemp($file['path'], (int) config('stash.uploads.max_bytes'));
                $staged[] = $tmp;
                $manifestFiles = $loaded['manifest']['files'] ?? [];
                $expected = collect(is_array($manifestFiles) ? $manifestFiles : [])->firstWhere('path', $file['path']);
                $actual = (string) hash_file('sha256', $tmp);
                if (! hash_equals($file['sha256'], $actual) || ($expected && ! hash_equals((string) ($expected['sha256'] ?? ''), $actual))) {
                    throw new StashException('checksum_mismatch', 'Attachment checksum mismatch.', 422);
                }
                try {
                    $info = $this->inspector->inspect($tmp);
                } catch (ImageRejected $e) {
                    throw new StashException('invalid_attachment', 'Attachment rejected: '.$e->getMessage(), 422);
                }
                $key = $this->files->resourceKey($user->id, $id, FileRole::Original->value, $info->extension);
                $this->files->putFile($key, $tmp);
                $stored[] = ['key' => $key, 'info' => $info];
            }

            DB::transaction(function () use ($user, $item, $id, $projectMap, $tagMap, $stored, &$report) {
                $r = new Resource;
                $r->id = $id;
                $r->user_id = $user->id;
                $r->type = ResourceType::from($item['type']);
                $r->title = $item['title'];
                $r->description = $item['description'];
                $r->personal_notes = $item['personal_notes'];
                if ($item['url']) {
                    $n = UrlNormalizer::normalize($item['url']);
                    $r->original_url = $item['url'];
                    $r->source_host = $n['host'];
                    if ($item['type'] !== 'image') {
                        $r->normalized_url = $n['normalized'];
                        $r->normalized_url_hash = $n['hash'];
                    }
                }
                $r->project_id = $item['project_id'] ? ($projectMap[$item['project_id']] ?? null) : null;
                $r->read_later = $item['read_later'];
                $r->reading_status = $item['reading_status'];
                $r->read_at = $item['read_at'];
                $r->archived_at = $item['archived_at'];
                $r->deleted_at = $item['deleted_at'];
                $r->manual_fields = $item['manual_fields'];
                $r->metadata = $item['metadata'] ?: [];
                $r->metadata_status = $stored !== [] ? MetadataStatus::Queued : (empty($item['metadata']['fetched']) ? MetadataStatus::None : MetadataStatus::Ready);
                $r->created_at = $item['created_at'];
                $r->updated_at = $item['updated_at'] ?? $item['created_at'];
                $r->timestamps = false;
                $r->save();

                foreach ($stored as $s) {
                    ResourceFile::query()->create([
                        'resource_id' => $r->id, 'user_id' => $user->id, 'role' => FileRole::Original,
                        'disk' => $this->files->diskName(), 'storage_key' => $s['key'], 'mime_type' => $s['info']->mime,
                        'extension' => $s['info']->extension, 'size_bytes' => $s['info']->size, 'width' => $s['info']->width,
                        'height' => $s['info']->height, 'checksum_sha256' => $s['info']->sha256, 'status' => 'ready',
                    ]);
                    $report['created']['files']++;
                }

                $tagIds = array_values(array_unique(array_filter(array_map(fn ($tid) => $tagMap[strtolower($tid)] ?? null, $item['tag_ids']))));
                if ($tagIds !== []) {
                    $this->tagger->sync($r->id, $tagIds);
                }

                foreach ($item['summaries'] as $s) {
                    $run = new AiRun([
                        'resource_id' => $r->id, 'task' => 'summary', 'provider' => mb_substr((string) ($s['provider'] ?? 'import'), 0, 32),
                        'model' => mb_substr((string) ($s['model'] ?? 'unknown'), 0, 64), 'prompt_version' => mb_substr((string) ($s['prompt_version'] ?? 'import'), 0, 16),
                        'status' => AiRunStatus::Succeeded, 'source_kind' => in_array($s['source_kind'] ?? null, ['article', 'pasted', 'metadata'], true) ? $s['source_kind'] : 'article',
                        'input_hash' => hash('sha256', 'import:'.$s['summary']), 'input_chars' => 0, 'reserved_tokens' => 0, 'total_tokens' => 0,
                        'result' => ['summary' => mb_substr($s['summary'], 0, 2000), 'key_points' => array_slice(array_values(array_filter((array) ($s['key_points'] ?? []), 'is_string')), 0, 8), 'imported' => true],
                        'finished_at' => now(),
                    ]);
                    $run->user_id = $user->id;
                    $run->save();
                    $report['created']['summaries']++;
                }

                if ($stored !== []) {
                    ProcessResourceImage::dispatch($r->id);
                }
                $report['created']['resources']++;
            });
        } catch (Throwable $e) {
            foreach ($stored as $s) {
                $this->files->delete($this->files->diskName(), $s['key']);
            }
            throw $e;
        } finally {
            foreach ($staged as $tmp) {
                @unlink($tmp);
            }
        }

        return null;
    }

    /** @param array<string, mixed>|null $loaded */
    private function cleanup(?array $loaded): void
    {
        if ($loaded) {
            $loaded['zip']?->close();
            if ($loaded['temp']) {
                @unlink($loaded['temp']);
            }
        }
    }

    public function prune(): int
    {
        $count = 0;
        DataImport::query()->where('expires_at', '<', now())->each(function (DataImport $import) use (&$count) {
            if ($import->storage_key && $import->storage_key !== 'pending') {
                $this->files->delete($import->disk, $import->storage_key);
            }
            $import->delete();
            $count++;
        });

        return $count;
    }
}
