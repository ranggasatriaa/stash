<?php

namespace App\Stash\Taxonomy;

use App\Models\User;
use App\Stash\Activity\ActivityLogger;
use App\Stash\Exceptions\StashException;
use App\Stash\Models\Tag;
use App\Stash\Resources\ResourceTagger;
use App\Stash\Support\TagName;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tag lifecycle. Tags are unique per owner by normalized name; deleting a tag
 * removes associations only, never resources.
 */
final class TagService
{
    public function __construct(
        private readonly ResourceTagger $tagger,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Find or create tags by display name (case/space-insensitive).
     *
     * @param  iterable<string>  $names
     * @return Collection<int, Tag>
     */
    public function resolveNames(User $user, iterable $names): Collection
    {
        $wanted = [];
        foreach ($names as $name) {
            $clean = TagName::clean((string) $name);
            if ($clean === '') {
                continue;
            }
            $wanted[TagName::normalize($clean)] ??= $clean;
        }

        if ($wanted === []) {
            return new Collection;
        }

        if (count($wanted) > 50) {
            throw new StashException('too_many_tags', 'A resource can have at most 50 tags.');
        }

        $now = now();
        $rows = [];
        foreach ($wanted as $normalized => $display) {
            $rows[] = [
                'id' => Str::lower((string) Str::ulid()),
                'user_id' => $user->id,
                'name' => $display,
                'normalized_name' => $normalized,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('tags')->insertOrIgnore($rows);

        return Tag::query()
            ->where('user_id', $user->id)
            ->whereIn('normalized_name', array_keys($wanted))
            ->orderBy('normalized_name')
            ->get();
    }

    /**
     * Resolve tag ids that must belong to the user.
     *
     * @param  list<string>  $ids
     * @return Collection<int, Tag>
     */
    public function ownedByIds(User $user, array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        $tags = Tag::query()->where('user_id', $user->id)->whereIn('id', $ids)->get();
        if ($tags->count() !== count($ids)) {
            throw new StashException('tag_not_found', 'One or more tags do not exist.', 422);
        }

        return $tags;
    }

    public function create(User $user, string $name, ?string $color = null): Tag
    {
        $clean = TagName::clean($name);
        if ($clean === '') {
            throw new StashException('invalid_tag', 'Tag name is required.', 422, ['name' => ['Tag name is required.']]);
        }

        $normalized = TagName::normalize($clean);
        if (Tag::query()->where('user_id', $user->id)->where('normalized_name', $normalized)->exists()) {
            throw new StashException('tag_exists', 'A tag with this name already exists.', 422, ['name' => ['A tag with this name already exists.']]);
        }

        $tag = new Tag(['name' => $clean, 'normalized_name' => $normalized, 'color' => $color]);
        $tag->user_id = $user->id;
        $tag->save();

        $this->activity->record($user->id, 'tag.created', 'tag', $tag->id);

        return $tag;
    }

    public function update(User $user, Tag $tag, ?string $name, ?string $color, bool $colorProvided = false): Tag
    {
        return DB::transaction(function () use ($user, $tag, $name, $color, $colorProvided) {
            /** @var Tag $tag */
            $tag = Tag::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($tag->id);
            $renamed = false;

            if ($name !== null) {
                $clean = TagName::clean($name);
                if ($clean === '') {
                    throw new StashException('invalid_tag', 'Tag name is required.', 422, ['name' => ['Tag name is required.']]);
                }
                $normalized = TagName::normalize($clean);
                $conflict = Tag::query()->where('user_id', $user->id)->where('normalized_name', $normalized)->whereKeyNot($tag->id)->exists();
                if ($conflict) {
                    throw new StashException('tag_exists', 'A tag with this name already exists.', 422, ['name' => ['A tag with this name already exists.']]);
                }
                $renamed = $clean !== $tag->name;
                $tag->name = $clean;
                $tag->normalized_name = $normalized;
            }

            if ($colorProvided) {
                $tag->color = $color;
            }

            $tag->save();

            if ($renamed) {
                $resourceIds = array_values(DB::table('resource_tag')->where('tag_id', $tag->id)->pluck('resource_id')->all());
                $this->tagger->refreshTagNames($resourceIds, bumpVersion: true);
            }

            $this->activity->record($user->id, 'tag.updated', 'tag', $tag->id, ['renamed' => $renamed]);

            return $tag;
        });
    }

    /** Delete a tag: associations are removed, resources are kept. */
    public function delete(User $user, Tag $tag): int
    {
        return DB::transaction(function () use ($user, $tag) {
            /** @var Tag $tag */
            $tag = Tag::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($tag->id);
            $resourceIds = array_values(DB::table('resource_tag')->where('tag_id', $tag->id)->pluck('resource_id')->all());
            DB::table('resource_tag')->where('tag_id', $tag->id)->delete();
            $tag->delete();
            $this->tagger->refreshTagNames($resourceIds, bumpVersion: true);
            $this->activity->record($user->id, 'tag.deleted', 'tag', $tag->id, ['detached' => count($resourceIds)]);

            return count($resourceIds);
        });
    }
}
