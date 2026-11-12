<?php

namespace App\Stash\Resources;

use App\Stash\Enums\ResourceType;
use App\Stash\Enums\Section;
use App\Stash\Enums\SortOrder;
use Carbon\CarbonImmutable;

/**
 * Normalized listing state: navigation section (+ optional project/tag
 * scope), filter groups, search text, sort and paging. Parsing is lenient:
 * unknown or invalid values are dropped rather than failing the page.
 */
final class ResourceFilters
{
    public const TAG_MODE_ANY = 'any';

    public const TAG_MODE_ALL = 'all';

    /**
     * @param  list<ResourceType>  $types
     * @param  list<string>  $tags
     */
    public function __construct(
        public Section $section = Section::All,
        public ?string $projectScope = null,
        public ?string $tagScope = null,
        public ?string $q = null,
        public array $types = [],
        public array $tags = [],
        public string $tagMode = self::TAG_MODE_ANY,
        public ?string $project = null,
        public ?string $reading = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?SortOrder $sort = null,
        public int $page = 1,
        public int $perPage = 24,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input, Section $section = Section::All, ?string $projectScope = null, ?string $tagScope = null): self
    {
        $q = isset($input['q']) && is_string($input['q']) ? trim(mb_substr($input['q'], 0, 200)) : null;

        $types = [];
        foreach (self::list($input['type'] ?? $input['types'] ?? []) as $value) {
            $type = ResourceType::tryFrom($value);
            if ($type && ! in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        $tags = array_values(array_unique(array_filter(self::list($input['tags'] ?? []), fn ($id) => self::isId($id))));
        $tagMode = ($input['tag_mode'] ?? null) === self::TAG_MODE_ALL ? self::TAG_MODE_ALL : self::TAG_MODE_ANY;

        $project = $input['project'] ?? null;
        $project = is_string($project) && ($project === 'none' || self::isId($project)) ? $project : null;

        $reading = $input['reading'] ?? null;
        $reading = in_array($reading, ['unread', 'read'], true) ? $reading : null;

        $from = self::date($input['from'] ?? null);
        $to = self::date($input['to'] ?? null);
        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        $sort = SortOrder::tryFrom((string) ($input['sort'] ?? ''));
        if ($sort === SortOrder::Relevance && ($q === null || $q === '')) {
            $sort = null;
        }

        $allowed = (array) config('stash.pagination.allowed', [24, 48, 96]);
        $perPage = (int) ($input['per_page'] ?? config('stash.pagination.default', 24));
        $max = (int) config('stash.pagination.max', 100);
        $perPage = max(1, min($perPage, $max));

        return new self(
            section: $section,
            projectScope: $projectScope,
            tagScope: $tagScope,
            q: $q === '' ? null : $q,
            types: $types,
            tags: $tags,
            tagMode: $tagMode,
            project: $project,
            reading: $reading,
            from: $from,
            to: $to,
            sort: $sort,
            page: max(1, min((int) ($input['page'] ?? 1), 100000)),
            perPage: in_array($perPage, $allowed, true) || $perPage <= $max ? $perPage : 24,
        );
    }

    public function effectiveSort(): SortOrder
    {
        if ($this->sort) {
            return $this->sort;
        }

        return $this->q ? SortOrder::Relevance : SortOrder::Newest;
    }

    public function hasActiveFilters(): bool
    {
        return $this->q !== null || $this->types !== [] || $this->tags !== [] || $this->project !== null
            || $this->reading !== null || $this->from !== null || $this->to !== null;
    }

    /**
     * Filter state for the UI / saved views (no paging).
     *
     * @return array{q: ?string, type: list<string>, tags: list<string>, tag_mode: string, project: ?string, reading: ?string, from: ?string, to: ?string, sort: ?string, per_page: int}
     */
    public function toArray(): array
    {
        return [
            'q' => $this->q,
            'type' => array_map(fn (ResourceType $t) => $t->value, $this->types),
            'tags' => $this->tags,
            'tag_mode' => $this->tagMode,
            'project' => $this->project,
            'reading' => $this->reading,
            'from' => $this->from,
            'to' => $this->to,
            'sort' => $this->sort?->value,
            'per_page' => $this->perPage,
        ];
    }

    /**
     * UTC bounds for the created-date filter in the user's timezone. The end
     * date is inclusive: we compare `< start of the following day`.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public function dateBounds(string $timezone): array
    {
        $from = $this->from ? CarbonImmutable::createFromFormat('!Y-m-d', $this->from, $timezone)?->utc() : null;
        $to = $this->to ? CarbonImmutable::createFromFormat('!Y-m-d', $this->to, $timezone)?->addDay()->utc() : null;

        return [$from ?: null, $to ?: null];
    }

    public static function isId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/i', $value) === 1;
    }

    /** @return list<string> */
    private static function list(mixed $value): array
    {
        if (is_string($value)) {
            $value = $value === '' ? [] : explode(',', $value);
        }

        return is_array($value) ? array_values(array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $value), fn ($v) => $v !== '')) : [];
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? $value : null;
    }
}
