<?php

namespace App\Stash\Taxonomy;

use App\Models\User;
use App\Stash\Activity\ActivityLogger;
use App\Stash\Exceptions\StashException;
use App\Stash\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Project lifecycle. Deleting a project unassigns its resources (with a
 * version bump) inside the same transaction; resources are never deleted.
 */
final class ProjectService
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /** @param array{name: string, description?: ?string, color?: ?string, icon?: ?string} $data */
    public function create(User $user, array $data): Project
    {
        $project = new Project([
            'name' => trim($data['name']),
            'description' => $this->nullable($data['description'] ?? null),
            'color' => $data['color'] ?? null,
            'icon' => $data['icon'] ?? null,
        ]);
        $project->user_id = $user->id;
        $project->save();

        $this->activity->record($user->id, 'project.created', 'project', $project->id);

        return $project;
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, Project $project, array $data): Project
    {
        $project = Project::query()->where('user_id', $user->id)->findOrFail($project->id);
        if (array_key_exists('name', $data)) {
            $project->name = trim((string) $data['name']);
        }
        foreach (['description', 'color', 'icon'] as $field) {
            if (array_key_exists($field, $data)) {
                $project->{$field} = $field === 'description' ? $this->nullable($data[$field]) : $data[$field];
            }
        }
        $project->save();
        $this->activity->record($user->id, 'project.updated', 'project', $project->id, ['fields' => array_keys($data)]);

        return $project;
    }

    /** @return int number of resources unassigned */
    public function delete(User $user, Project $project): int
    {
        return DB::transaction(function () use ($user, $project) {
            /** @var Project $project */
            $project = Project::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($project->id);

            $unassigned = DB::table('resources')
                ->where('user_id', $user->id)
                ->where('project_id', $project->id)
                ->update(['project_id' => null, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);

            $project->delete();
            $this->activity->record($user->id, 'project.deleted', 'project', $project->id, ['unassigned' => $unassigned]);

            return $unassigned;
        });
    }

    /**
     * Ownership-checked lookup that holds a share lock until the surrounding
     * transaction ends, so a concurrent delete cannot orphan the assignment.
     */
    public function lockOwned(User $user, string $projectId): Project
    {
        $project = Project::query()->where('user_id', $user->id)->whereKey($projectId)->sharedLock()->first();
        if (! $project) {
            throw new StashException('project_not_found', 'The selected project does not exist.', 422, ['project_id' => ['The selected project does not exist.']]);
        }

        return $project;
    }

    private function nullable(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
