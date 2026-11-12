<?php

namespace App\Http\Resources;

use App\Stash\Enums\FileRole;
use App\Stash\Models\Resource;
use App\Stash\Models\ResourceFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of a resource, shared by the web UI (Inertia props)
 * and /api/v1. `detail` adds notes, files and source proposals.
 *
 * @mixin Resource
 */
class ResourceJson extends JsonResource
{
    public bool $detail = false;

    public function withDetail(bool $detail = true): static
    {
        $this->detail = $detail;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var resource $r */
        $r = $this->resource;
        $api = $request->is('api/*');

        $data = [
            'id' => $r->id,
            'type' => $r->type->value,
            'title' => $r->title,
            'description' => $r->description,
            'url' => $r->original_url,
            'host' => $r->source_host,
            'site_name' => $r->metadata['site_name'] ?? null,
            'project' => $r->relationLoaded('project') && $r->project
                ? ['id' => $r->project->id, 'name' => $r->project->name, 'color' => $r->project->color, 'icon' => $r->project->icon]
                : null,
            'project_id' => $r->project_id,
            'tags' => $r->relationLoaded('tags')
                ? $r->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])->values()->all()
                : [],
            'read_later' => $r->read_later,
            'reading_status' => $r->reading_status?->value,
            'read_at' => $r->read_at?->toIso8601ZuluString(),
            'processing' => [
                'status' => $r->metadata_status->value,
                'error' => $r->metadata_error,
            ],
            'archived_at' => $r->archived_at?->toIso8601ZuluString(),
            'deleted_at' => $r->deleted_at?->toIso8601ZuluString(),
            'created_at' => $r->created_at->toIso8601ZuluString(),
            'updated_at' => $r->updated_at->toIso8601ZuluString(),
            'version' => $r->version,
            'thumbnail' => $this->file($r->cardImage(), $api),
        ];

        if ($this->detail) {
            $data['personal_notes'] = $r->personal_notes;
            $data['manual_fields'] = $r->manual_fields ?? [];
            $data['preview'] = $this->file($r->file(FileRole::Preview), $api);
            $data['original'] = $this->file($r->file(FileRole::Original), $api, includeDownload: true);
            $data['source_proposals'] = $this->proposals($r);
            $data['fetched_at'] = $r->metadata['fetched']['fetched_at'] ?? null;
        }

        return $data;
    }

    /** @return array<string, mixed>|null */
    private function file(?ResourceFile $file, bool $api, bool $includeDownload = false): ?array
    {
        if (! $file || $file->status !== 'ready') {
            return null;
        }

        $route = $api ? 'api.v1.files.show' : 'files.show';
        $out = [
            'id' => $file->id,
            'role' => $file->role->value,
            'url' => route($route, $file, absolute: $api),
            'mime_type' => $file->mime_type,
            'size_bytes' => $file->size_bytes,
            'width' => $file->width,
            'height' => $file->height,
        ];
        if ($includeDownload) {
            $out['download_url'] = route($route, ['file' => $file, 'download' => 1], absolute: $api);
            $out['checksum_sha256'] = $file->checksum_sha256;
        }

        return $out;
    }

    /**
     * Fetched source values that differ from manually edited fields; the UI
     * shows these before an explicit "replace from source".
     *
     * @return array<string, string>
     */
    private function proposals(Resource $r): array
    {
        $fetched = (array) ($r->metadata['fetched'] ?? []);
        $out = [];
        foreach (Resource::ENRICHABLE_FIELDS as $field) {
            $value = $fetched[$field] ?? null;
            if (is_string($value) && $value !== '' && $r->isManual($field) && $value !== $r->{$field}) {
                $out[$field] = $value;
            }
        }

        return $out;
    }
}
