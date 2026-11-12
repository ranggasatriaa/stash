<?php

namespace App\Stash\Resources;

use App\Models\User;
use App\Stash\Enums\ResourceType;
use App\Stash\Enums\Section;
use App\Stash\Enums\SortOrder;
use App\Stash\Models\Resource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Builds owner-scoped listing queries. Filter groups are ANDed; tag
 * Any/All applies within the tag group. Tag filters use EXISTS subqueries
 * so tag joins can never duplicate result rows.
 *
 * Search (PostgreSQL):
 *  - prefix full-text match on the weighted tsvector (title/tags A,
 *    description B, URL words C, personal notes D)
 *  - queries of 3+ characters also match substrings and fuzzy trigram
 *    word-similarity on the lower-cased search document
 *  - 1–2 character queries use indexed prefix strategies only
 *  - relevance: exact title, exact tag, title prefix, tag prefix, then
 *    ts_rank and trigram similarity, then newest, then id
 */
final class ResourceQuery
{
    /** @return Builder<Resource> */
    public function build(User $user, ResourceFilters $f): Builder
    {
        $query = Resource::query()
            ->select(array_map(fn ($c) => 'resources.'.$c, Resource::LIST_COLUMNS))
            ->where('resources.user_id', $user->id);

        $this->applySection($query, $f->section);

        if ($f->projectScope) {
            $query->where('resources.project_id', $f->projectScope);
        }
        if ($f->tagScope) {
            $query->whereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')->from('resource_tag')
                ->whereColumn('resource_tag.resource_id', 'resources.id')
                ->where('resource_tag.tag_id', $f->tagScope));
        }

        if ($f->types !== []) {
            $query->whereIn('resources.type', array_map(fn (ResourceType $t) => $t->value, $f->types));
        }

        if ($f->tags !== []) {
            if ($f->tagMode === ResourceFilters::TAG_MODE_ALL) {
                $placeholders = implode(',', array_fill(0, count($f->tags), '?'));
                $query->whereRaw(
                    "(SELECT count(DISTINCT rt.tag_id) FROM resource_tag rt WHERE rt.resource_id = resources.id AND rt.tag_id IN ({$placeholders})) = ?",
                    [...$f->tags, count($f->tags)],
                );
            } else {
                $query->whereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')->from('resource_tag')
                    ->whereColumn('resource_tag.resource_id', 'resources.id')
                    ->whereIn('resource_tag.tag_id', $f->tags));
            }
        }

        if ($f->project === 'none') {
            $query->whereNull('resources.project_id');
        } elseif ($f->project) {
            $query->where('resources.project_id', $f->project);
        }

        if ($f->reading) {
            $query->where('resources.type', ResourceType::Article->value)->where('resources.reading_status', $f->reading);
        }

        [$from, $to] = $f->dateBounds($user->timezone ?: 'UTC');
        if ($from) {
            $query->where('resources.created_at', '>=', $from);
        }
        if ($to) {
            $query->where('resources.created_at', '<', $to);
        }

        if ($f->q !== null) {
            $this->applySearch($query, $f->q);
        }

        $this->applySort($query, $f);

        return $query;
    }

    /** @return LengthAwarePaginator<int, Resource> */
    public function paginate(User $user, ResourceFilters $f): LengthAwarePaginator
    {
        return $this->build($user, $f)
            ->with(['tags:id,name,normalized_name,color', 'project:id,name,color,icon', 'files'])
            ->paginate($f->perPage, ['*'], 'page', $f->page);
    }

    /** @param Builder<Resource> $query */
    public function applySection(Builder $query, Section $section): void
    {
        match ($section) {
            Section::All => $query->whereNull('resources.archived_at'),
            Section::Inbox => $query->whereNull('resources.archived_at')->whereNull('resources.project_id')
                ->whereNotExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')->from('resource_tag')->whereColumn('resource_tag.resource_id', 'resources.id')),
            Section::Bookmarks => $query->whereNull('resources.archived_at')->whereIn('resources.type', ['link', 'article']),
            Section::Screenshots => $query->whereNull('resources.archived_at')->where('resources.type', 'image'),
            Section::ReadLater => $query->whereNull('resources.archived_at')->where('resources.type', 'article')->where('resources.read_later', true),
            Section::Archive => $query->whereNotNull('resources.archived_at'),
            Section::Trash => $query->onlyTrashed(),
        };
    }

    /** @param Builder<Resource> $query */
    private function applySearch(Builder $query, string $q): void
    {
        $tsQuery = self::prefixTsQuery($q);
        $like = '%'.self::escapeLike($q).'%';
        $prefix = self::escapeLike($q).'%';
        $long = mb_strlen($q) >= 3;

        $query->where(function (Builder $w) use ($tsQuery, $like, $prefix, $q, $long) {
            if ($tsQuery !== null) {
                $w->orWhereRaw("resources.search_vector @@ to_tsquery('simple', ?)", [$tsQuery]);
            }
            $w->orWhereRaw('lower(resources.title) LIKE lower(?)', [$prefix]);
            if ($long) {
                $w->orWhereRaw('resources.search_document LIKE lower(?)', [$like]);
                $w->orWhereRaw('lower(?) <% resources.search_document', [$q]);
            }
        });

        $tsExpr = $tsQuery !== null ? "ts_rank(resources.search_vector, to_tsquery('simple', ?))" : '0';
        $bindings = [$q, $q, $prefix, $prefix];
        if ($tsQuery !== null) {
            $bindings[] = $tsQuery;
        }
        $bindings[] = $q;

        $query->selectRaw(
            "(CASE
                WHEN lower(resources.title) = lower(?) THEN 0
                WHEN EXISTS (SELECT 1 FROM resource_tag rt JOIN tags t ON t.id = rt.tag_id WHERE rt.resource_id = resources.id AND t.normalized_name = lower(?)) THEN 1
                WHEN lower(resources.title) LIKE lower(?) THEN 2
                WHEN EXISTS (SELECT 1 FROM resource_tag rt JOIN tags t ON t.id = rt.tag_id WHERE rt.resource_id = resources.id AND t.normalized_name LIKE lower(?)) THEN 3
                ELSE 4 END) AS match_tier,
             {$tsExpr} AS text_rank,
             word_similarity(lower(?), resources.search_document) AS fuzzy_rank",
            $bindings,
        );
    }

    /** @param Builder<Resource> $query */
    private function applySort(Builder $query, ResourceFilters $f): void
    {
        $sort = $f->effectiveSort();
        if ($sort === SortOrder::Relevance && $f->q === null) {
            $sort = SortOrder::Newest;
        }

        match ($sort) {
            SortOrder::Relevance => $query->orderBy('match_tier')->orderByDesc('text_rank')->orderByDesc('fuzzy_rank')
                ->orderByDesc('resources.created_at')->orderByDesc('resources.id'),
            SortOrder::Newest => $query->orderByDesc('resources.created_at')->orderByDesc('resources.id'),
            SortOrder::Oldest => $query->orderBy('resources.created_at')->orderBy('resources.id'),
            SortOrder::Title => $query->orderByRaw('lower(resources.title) ASC')->orderBy('resources.id'),
        };
    }

    /**
     * Build a safe prefix tsquery ("tok1:* & tok2:*") from free text. Tokens
     * contain only letters/digits, so no tsquery syntax can be injected.
     */
    public static function prefixTsQuery(string $q): ?string
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_slice(array_values(array_unique($tokens)), 0, 8);
        if ($tokens === []) {
            return null;
        }

        return implode(' & ', array_map(fn ($t) => $t.':*', $tokens));
    }

    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
