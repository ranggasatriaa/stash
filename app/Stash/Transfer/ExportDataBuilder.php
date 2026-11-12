<?php

namespace App\Stash\Transfer;

use App\Models\User;
use App\Stash\Enums\AiRunStatus;
use App\Stash\Enums\FileRole;
use App\Stash\Models\AiRun;
use App\Stash\Models\Project;
use App\Stash\Models\Resource;
use App\Stash\Models\ResourceFile;
use App\Stash\Models\SavedView;
use App\Stash\Models\Tag;
use Illuminate\Support\Facades\DB;

/**
 * Builds the owner-scoped, versioned export document. Never includes
 * secrets, tokens, sessions, password hashes or other owners' data.
 */
final class ExportDataBuilder
{
    public const SCHEMA = 'stash.export';

    public const SCHEMA_VERSION = 1;

    /**
     * @return array{document: array{projects: array<int, array<string, mixed>>, tags: array<int, array<string, mixed>>, resources: array<int, array<string, mixed>>, exported_at: string, counts: array{resources: int, projects: int, tags: int, attachments: int}, ...}, attachments: list<ResourceFile>}
     */
    public function build(User $user, bool $includeTrash, bool $withAttachments): array
    {
        $projects = Project::query()->where('user_id', $user->id)->orderBy('id')->get()->map(fn (Project $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'color' => $p->color,
            'icon' => $p->icon,
            'created_at' => $p->created_at?->toIso8601ZuluString(),
            'updated_at' => $p->updated_at?->toIso8601ZuluString(),
        ])->all();

        $tags = Tag::query()->where('user_id', $user->id)->orderBy('normalized_name')->get()->map(fn (Tag $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'color' => $t->color,
            'created_at' => $t->created_at?->toIso8601ZuluString(),
            'updated_at' => $t->updated_at?->toIso8601ZuluString(),
        ])->all();

        $views = SavedView::query()->where('user_id', $user->id)->orderBy('position')->get()->map(fn (SavedView $v) => [
            'id' => $v->id, 'name' => $v->name, 'section' => $v->section, 'filters' => $v->filters, 'sort' => $v->sort, 'position' => $v->position,
        ])->all();

        $query = Resource::query()->where('user_id', $user->id)->orderBy('id');
        if ($includeTrash) {
            $query->withTrashed();
        }

        $tagMap = DB::table('resource_tag')
            ->join('resources', 'resources.id', '=', 'resource_tag.resource_id')
            ->where('resources.user_id', $user->id)
            ->get(['resource_tag.resource_id', 'resource_tag.tag_id'])
            ->groupBy('resource_id')
            ->map(fn ($rows) => $rows->pluck('tag_id')->sort()->values()->all());

        $resources = [];
        $attachments = [];
        foreach ($query->with('files')->lazyById(200) as $r) {
            /** @var resource $r */
            $files = [];
            foreach ($r->files as $file) {
                if ($file->role !== FileRole::Original || $file->status !== 'ready') {
                    continue;
                }
                $files[] = [
                    'id' => $file->id,
                    'role' => 'original',
                    'mime_type' => $file->mime_type,
                    'extension' => $file->extension,
                    'size_bytes' => $file->size_bytes,
                    'width' => $file->width,
                    'height' => $file->height,
                    'sha256' => $file->checksum_sha256,
                    'path' => $withAttachments ? self::attachmentPath($file) : null,
                ];
                if ($withAttachments) {
                    $attachments[] = $file;
                }
            }

            $summaries = AiRun::query()->where('user_id', $user->id)->where('resource_id', $r->id)
                ->where('status', AiRunStatus::Succeeded->value)->whereNotNull('result')->orderBy('created_at')->get()
                ->map(fn (AiRun $run) => [
                    'id' => $run->id,
                    'provider' => $run->provider,
                    'model' => $run->model,
                    'prompt_version' => $run->prompt_version,
                    'source_kind' => $run->source_kind,
                    'summary' => $run->result['summary'] ?? null,
                    'key_points' => $run->result['key_points'] ?? [],
                    'created_at' => $run->created_at->toIso8601ZuluString(),
                ])->all();

            $resources[] = [
                'id' => $r->id,
                'type' => $r->type->value,
                'title' => $r->title,
                'description' => $r->description,
                'personal_notes' => $r->personal_notes,
                'url' => $r->original_url,
                'project_id' => $r->project_id,
                'tag_ids' => $tagMap[$r->id] ?? [],
                'read_later' => $r->read_later,
                'reading_status' => $r->reading_status?->value,
                'read_at' => $r->read_at?->toIso8601ZuluString(),
                'archived_at' => $r->archived_at?->toIso8601ZuluString(),
                'deleted_at' => $r->deleted_at?->toIso8601ZuluString(),
                'created_at' => $r->created_at->toIso8601ZuluString(),
                'updated_at' => $r->updated_at->toIso8601ZuluString(),
                'manual_fields' => $r->manual_fields ?? [],
                'metadata' => array_intersect_key($r->metadata ?? [], array_flip(['site_name', 'fetched'])),
                'files' => $files,
                'summaries' => $summaries,
            ];
        }

        return [
            'document' => [
                'schema' => self::SCHEMA,
                'schema_version' => self::SCHEMA_VERSION,
                'generator' => 'Stash',
                'exported_at' => now()->toIso8601ZuluString(),
                'include_trash' => $includeTrash,
                'owner' => ['timezone' => $user->timezone],
                'counts' => ['resources' => count($resources), 'projects' => count($projects), 'tags' => count($tags), 'attachments' => count($attachments)],
                'projects' => $projects,
                'tags' => $tags,
                'saved_views' => $views,
                'resources' => $resources,
            ],
            'attachments' => $attachments,
        ];
    }

    public static function attachmentPath(ResourceFile $file): string
    {
        return 'attachments/'.$file->id.'.'.$file->extension;
    }
}
