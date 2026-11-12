<?php

namespace App\Stash\Resources;

use Illuminate\Support\Facades\DB;

/**
 * Maintains resource_tag associations and the denormalized `tag_names`
 * column that feeds the full-text and trigram search columns.
 */
final class ResourceTagger
{
    /**
     * Replace a resource's tags. Returns true if the set changed.
     *
     * @param  list<string>  $tagIds
     */
    public function sync(string $resourceId, array $tagIds): bool
    {
        $tagIds = array_values(array_unique($tagIds));
        $current = DB::table('resource_tag')->where('resource_id', $resourceId)->pluck('tag_id')->all();

        $toAdd = array_values(array_diff($tagIds, $current));
        $toRemove = array_values(array_diff($current, $tagIds));
        if ($toAdd === [] && $toRemove === []) {
            return false;
        }

        if ($toRemove !== []) {
            DB::table('resource_tag')->where('resource_id', $resourceId)->whereIn('tag_id', $toRemove)->delete();
        }
        if ($toAdd !== []) {
            DB::table('resource_tag')->insertOrIgnore(array_map(fn ($id) => ['resource_id' => $resourceId, 'tag_id' => $id, 'created_at' => now()], $toAdd));
        }

        $this->refreshTagNames([$resourceId]);

        return true;
    }

    /**
     * Add tags to many resources. Returns ids of resources that changed.
     *
     * @param  list<string>  $resourceIds
     * @param  list<string>  $tagIds
     * @return list<string>
     */
    public function attach(array $resourceIds, array $tagIds): array
    {
        if ($resourceIds === [] || $tagIds === []) {
            return [];
        }

        $existing = DB::table('resource_tag')->whereIn('resource_id', $resourceIds)->whereIn('tag_id', $tagIds)
            ->get(['resource_id', 'tag_id'])->map(fn ($r) => $r->resource_id.'|'.$r->tag_id)->flip();

        $rows = [];
        $changed = [];
        foreach ($resourceIds as $rid) {
            foreach ($tagIds as $tid) {
                if (! isset($existing[$rid.'|'.$tid])) {
                    $rows[] = ['resource_id' => $rid, 'tag_id' => $tid, 'created_at' => now()];
                    $changed[$rid] = true;
                }
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('resource_tag')->insertOrIgnore($chunk);
        }

        $changed = array_keys($changed);
        $this->refreshTagNames($changed, bumpVersion: true);

        return $changed;
    }

    /**
     * Remove tags from many resources. Returns ids of resources that changed.
     *
     * @param  list<string>  $resourceIds
     * @param  list<string>  $tagIds
     * @return list<string>
     */
    public function detach(array $resourceIds, array $tagIds): array
    {
        if ($resourceIds === [] || $tagIds === []) {
            return [];
        }

        $changed = array_values(DB::table('resource_tag')->whereIn('resource_id', $resourceIds)->whereIn('tag_id', $tagIds)
            ->distinct()->pluck('resource_id')->all());

        DB::table('resource_tag')->whereIn('resource_id', $resourceIds)->whereIn('tag_id', $tagIds)->delete();
        $this->refreshTagNames($changed, bumpVersion: true);

        return $changed;
    }

    /**
     * Recompute the denormalized tag_names text for the given resources.
     *
     * @param  list<string>  $resourceIds
     */
    public function refreshTagNames(array $resourceIds, bool $bumpVersion = false): void
    {
        $resourceIds = array_values(array_unique($resourceIds));
        foreach (array_chunk($resourceIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $version = $bumpVersion ? ', version = r.version + 1, updated_at = now()' : '';
            DB::update(
                "UPDATE resources r SET tag_names = coalesce((
                    SELECT string_agg(t.name, ' ' ORDER BY t.normalized_name)
                    FROM resource_tag rt JOIN tags t ON t.id = rt.tag_id
                    WHERE rt.resource_id = r.id
                ), ''){$version}
                WHERE r.id IN ({$placeholders})",
                $chunk,
            );
        }
    }
}
