<?php

namespace App\Stash\Resources;

use App\Models\User;
use App\Stash\Activity\ActivityLogger;
use App\Stash\Exceptions\StashException;
use App\Stash\Jobs\DeleteStoredFiles;
use App\Stash\Models\Resource;
use App\Stash\Taxonomy\ProjectService;
use App\Stash\Taxonomy\TagService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Validated multi-resource operations. Every id is checked against the
 * owner; unknown/foreign ids are reported as skipped (`not_found`) and never
 * touched. Ineligible items are skipped with a reason. Rows are locked in id
 * order inside one transaction and versions are bumped on change.
 */
final class BulkActionService
{
    public const MAX_IDS = 500;

    public const OPERATIONS = [
        'add_tags', 'remove_tags', 'set_project', 'mark_read', 'mark_unread', 'read_later_on',
        'read_later_off', 'archive', 'unarchive', 'trash', 'restore', 'delete_permanently',
    ];

    public function __construct(
        private readonly TagService $tags,
        private readonly ProjectService $projects,
        private readonly ResourceTagger $tagger,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $params  tags (names), tag_ids, project_id
     * @return array{operation: string, requested: int, changed: list<string>, skipped: list<array{id: string, reason: string}>, undo: array<string, mixed>|null}
     */
    public function run(User $user, string $operation, array $ids, array $params = []): array
    {
        if (! in_array($operation, self::OPERATIONS, true)) {
            throw new StashException('invalid_operation', 'Unknown bulk operation.', 422, ['operation' => ['Unknown bulk operation.']]);
        }

        $ids = array_values(array_unique(array_filter($ids, fn ($id) => ResourceFilters::isId($id))));
        if ($ids === []) {
            throw new StashException('no_ids', 'Select at least one resource.', 422, ['ids' => ['Select at least one resource.']]);
        }
        if (count($ids) > self::MAX_IDS) {
            throw new StashException('too_many_ids', 'Select at most '.self::MAX_IDS.' resources at a time.', 422, ['ids' => ['Too many resources selected.']]);
        }

        return DB::transaction(function () use ($user, $operation, $ids, $params) {
            /** @var Collection<int, Resource> $rows */
            $rows = Resource::withTrashed()->where('user_id', $user->id)->whereIn('id', $ids)
                ->orderBy('id')->lockForUpdate()->get(['id', 'type', 'reading_status', 'read_later', 'archived_at', 'deleted_at', 'project_id']);

            $skipped = [];
            foreach (array_diff($ids, $rows->pluck('id')->all()) as $missing) {
                $skipped[] = ['id' => $missing, 'reason' => 'not_found'];
            }

            [$changed, $moreSkipped, $undo] = $this->apply($user, $operation, $rows, $params);
            $skipped = [...$skipped, ...$moreSkipped];

            $this->activity->record($user->id, 'resource.bulk.'.$operation, 'resource', null, [
                'requested' => count($ids), 'changed' => count($changed), 'skipped' => count($skipped),
            ]);

            return [
                'operation' => $operation,
                'requested' => count($ids),
                'changed' => $changed,
                'skipped' => $skipped,
                'undo' => $changed === [] ? null : $undo,
            ];
        });
    }

    /**
     * @param  Collection<int, Resource>  $rows
     * @param  array<string, mixed>  $params
     * @return array{0: list<string>, 1: list<array{id: string, reason: string}>, 2: array<string, mixed>|null}
     */
    private function apply(User $user, string $operation, Collection $rows, array $params): array
    {
        $skipped = [];
        $eligible = [];

        $filter = function (callable $rule) use ($rows, &$skipped, &$eligible) {
            foreach ($rows as $r) {
                $reason = $rule($r);
                if ($reason === null) {
                    $eligible[] = $r->id;
                } else {
                    $skipped[] = ['id' => $r->id, 'reason' => $reason];
                }
            }
        };

        switch ($operation) {
            case 'archive':
                $filter(fn (Resource $r) => $r->trashed() ? 'in_trash' : ($r->archived_at ? 'already_archived' : null));
                $this->touch($eligible, ['archived_at' => now()]);

                return [$eligible, $skipped, ['operation' => 'unarchive', 'ids' => $eligible]];

            case 'unarchive':
                $filter(fn (Resource $r) => $r->archived_at ? null : 'not_archived');
                $this->touch($eligible, ['archived_at' => null]);

                return [$eligible, $skipped, ['operation' => 'archive', 'ids' => $eligible]];

            case 'trash':
                $filter(fn (Resource $r) => $r->trashed() ? 'already_in_trash' : null);
                $this->touch($eligible, ['deleted_at' => now()]);

                return [$eligible, $skipped, ['operation' => 'restore', 'ids' => $eligible]];

            case 'restore':
                $filter(fn (Resource $r) => $r->trashed() ? null : 'not_in_trash');
                $this->touch($eligible, ['deleted_at' => null]);

                return [$eligible, $skipped, ['operation' => 'trash', 'ids' => $eligible]];

            case 'mark_read':
            case 'mark_unread':
                $target = $operation === 'mark_read' ? 'read' : 'unread';
                $filter(fn (Resource $r) => ! $r->isArticle() ? 'not_article' : ($r->reading_status?->value === $target ? 'unchanged' : null));
                $this->touch($eligible, ['reading_status' => $target, 'read_at' => $target === 'read' ? now() : null]);

                return [$eligible, $skipped, ['operation' => $target === 'read' ? 'mark_unread' : 'mark_read', 'ids' => $eligible]];

            case 'read_later_on':
            case 'read_later_off':
                $value = $operation === 'read_later_on';
                $filter(fn (Resource $r) => ! $r->isArticle() ? 'not_article' : ($r->read_later === $value ? 'unchanged' : null));
                $this->touch($eligible, ['read_later' => $value]);

                return [$eligible, $skipped, ['operation' => $value ? 'read_later_off' : 'read_later_on', 'ids' => $eligible]];

            case 'add_tags':
                $tagIds = $this->resolveTagIds($user, $params);
                $changed = $this->tagger->attach(array_values($rows->pluck('id')->all()), $tagIds);
                foreach (array_diff($rows->pluck('id')->all(), $changed) as $id) {
                    $skipped[] = ['id' => $id, 'reason' => 'unchanged'];
                }

                return [$changed, $skipped, ['operation' => 'remove_tags', 'ids' => $changed, 'tag_ids' => $tagIds]];

            case 'remove_tags':
                $tagIds = $this->resolveTagIds($user, $params, create: false);
                $changed = $this->tagger->detach(array_values($rows->pluck('id')->all()), $tagIds);
                foreach (array_diff($rows->pluck('id')->all(), $changed) as $id) {
                    $skipped[] = ['id' => $id, 'reason' => 'unchanged'];
                }

                return [$changed, $skipped, ['operation' => 'add_tags', 'ids' => $changed, 'tag_ids' => $tagIds]];

            case 'set_project':
                $projectId = ! empty($params['project_id']) ? $this->projects->lockOwned($user, (string) $params['project_id'])->id : null;
                $filter(fn (Resource $r) => $r->project_id === $projectId ? 'unchanged' : null);
                $this->touch($eligible, ['project_id' => $projectId]);

                return [$eligible, $skipped, null];

            case 'delete_permanently':
                $filter(fn (Resource $r) => $r->trashed() ? null : 'not_in_trash');
                if ($eligible !== []) {
                    $files = DB::table('resource_files')->whereIn('resource_id', $eligible)->where('user_id', $user->id)
                        ->get(['disk', 'storage_key'])->map(fn ($f) => [$f->disk, $f->storage_key])->all();
                    DB::table('ai_runs')->whereIn('resource_id', $eligible)->update(['result' => null, 'input_text' => null]);
                    DB::table('resources')->where('user_id', $user->id)->whereIn('id', $eligible)->delete();
                    if ($files !== []) {
                        DeleteStoredFiles::dispatch(array_values($files));
                    }
                }

                return [$eligible, $skipped, null];
        }

        return [[], $skipped, null];
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $values
     */
    private function touch(array $ids, array $values): void
    {
        if ($ids === []) {
            return;
        }
        DB::table('resources')->whereIn('id', $ids)->update($values + [
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<string>
     */
    private function resolveTagIds(User $user, array $params, bool $create = true): array
    {
        $ids = [];
        if (! empty($params['tag_ids'])) {
            $ids = $this->tags->ownedByIds($user, array_values((array) $params['tag_ids']))->pluck('id')->all();
        }
        if ($create && ! empty($params['tags'])) {
            $ids = [...$ids, ...$this->tags->resolveNames($user, (array) $params['tags'])->pluck('id')->all()];
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            throw new StashException('no_tags', 'Choose at least one tag.', 422, ['tags' => ['Choose at least one tag.']]);
        }

        return $ids;
    }
}
