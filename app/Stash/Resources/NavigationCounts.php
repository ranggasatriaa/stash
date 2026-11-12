<?php

namespace App\Stash\Resources;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sidebar counts. Each count uses exactly the same predicate as the
 * corresponding view in ResourceQuery::applySection().
 */
final class NavigationCounts
{
    /** @return array<string, int> */
    public function sections(User $user): array
    {
        $row = DB::selectOne(
            "SELECT
                count(*) FILTER (WHERE deleted_at IS NULL AND archived_at IS NULL) AS \"all\",
                count(*) FILTER (WHERE deleted_at IS NULL AND archived_at IS NULL AND project_id IS NULL
                    AND NOT EXISTS (SELECT 1 FROM resource_tag rt WHERE rt.resource_id = resources.id)) AS inbox,
                count(*) FILTER (WHERE deleted_at IS NULL AND archived_at IS NULL AND type IN ('link','article')) AS bookmarks,
                count(*) FILTER (WHERE deleted_at IS NULL AND archived_at IS NULL AND type = 'image') AS screenshots,
                count(*) FILTER (WHERE deleted_at IS NULL AND archived_at IS NULL AND type = 'article' AND read_later = true) AS read_later,
                count(*) FILTER (WHERE deleted_at IS NULL AND archived_at IS NOT NULL) AS archive,
                count(*) FILTER (WHERE deleted_at IS NOT NULL) AS trash
             FROM resources WHERE user_id = ?",
            [$user->id],
        );

        return [
            'all' => (int) $row->all,
            'inbox' => (int) $row->inbox,
            'bookmarks' => (int) $row->bookmarks,
            'screenshots' => (int) $row->screenshots,
            'read-later' => (int) $row->read_later,
            'archive' => (int) $row->archive,
            'trash' => (int) $row->trash,
        ];
    }

    /**
     * Projects with active-resource counts (same rule as the project view).
     *
     * @return list<array{id: string, name: string, color: ?string, icon: ?string, description: ?string, count: int, total: int}>
     */
    public function projects(User $user): array
    {
        $rows = DB::select(
            'SELECT p.id, p.name, p.color, p.icon, p.description,
                count(r.id) FILTER (WHERE r.deleted_at IS NULL AND r.archived_at IS NULL) AS active_count,
                count(r.id) AS total_count
             FROM projects p
             LEFT JOIN resources r ON r.project_id = p.id AND r.user_id = p.user_id
             WHERE p.user_id = ?
             GROUP BY p.id
             ORDER BY lower(p.name), p.id',
            [$user->id],
        );

        return array_values(array_map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'color' => $r->color,
            'icon' => $r->icon,
            'description' => $r->description,
            'count' => (int) $r->active_count,
            'total' => (int) $r->total_count,
        ], $rows));
    }

    /**
     * Tags with active-resource counts and total associations.
     *
     * @return list<array{id: string, name: string, color: ?string, count: int, total: int}>
     */
    public function tags(User $user): array
    {
        $rows = DB::select(
            'SELECT t.id, t.name, t.color,
                count(r.id) FILTER (WHERE r.deleted_at IS NULL AND r.archived_at IS NULL) AS active_count,
                count(r.id) AS total_count
             FROM tags t
             LEFT JOIN resource_tag rt ON rt.tag_id = t.id
             LEFT JOIN resources r ON r.id = rt.resource_id AND r.user_id = t.user_id
             WHERE t.user_id = ?
             GROUP BY t.id
             ORDER BY t.normalized_name, t.id',
            [$user->id],
        );

        return array_values(array_map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'color' => $r->color,
            'count' => (int) $r->active_count,
            'total' => (int) $r->total_count,
        ], $rows));
    }
}
