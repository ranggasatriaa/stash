import type { Filters, Scope, SectionKey, SortKey } from '@/types/stash';

export const SECTION_LABELS: Record<SectionKey, string> = {
    all: 'All saved',
    inbox: 'Inbox',
    bookmarks: 'Bookmarks',
    screenshots: 'Screenshots',
    'read-later': 'Read later',
    archive: 'Archive',
    trash: 'Trash',
};

export const SORT_LABELS: Record<SortKey, string> = {
    relevance: 'Relevance',
    newest: 'Newest',
    oldest: 'Oldest',
    title: 'Title A → Z',
};

/** Path for a navigation section or project/tag scope. */
export function sectionPath(section: SectionKey, scope?: Scope): string {
    if (scope?.type === 'project') {
        return `/projects/${scope.id}`;
    }
    if (scope?.type === 'tag') {
        return `/tags/${scope.id}`;
    }

    return section === 'all' ? '/library' : `/library/${section}`;
}

export type FilterQuery = {
    q?: string;
    type?: string[];
    tags?: string[];
    tag_mode?: 'any' | 'all';
    project?: string;
    reading?: string;
    from?: string;
    to?: string;
    sort?: string;
    page?: number;
    per_page?: number;
    resource?: string;
};

/** Build query params from filter state (drops empty/default values). */
export function filtersToQuery(
    filters: Partial<Filters>,
    extra: Partial<FilterQuery> = {},
): FilterQuery {
    const query: FilterQuery = {};
    if (filters.q) query.q = filters.q;
    if (filters.type?.length) query.type = filters.type;
    if (filters.tags?.length) query.tags = filters.tags;
    if (filters.tags?.length && filters.tag_mode === 'all')
        query.tag_mode = 'all';
    if (filters.project) query.project = filters.project;
    if (filters.reading) query.reading = filters.reading;
    if (filters.from) query.from = filters.from;
    if (filters.to) query.to = filters.to;
    if (filters.sort) query.sort = filters.sort;
    if (filters.per_page && filters.per_page !== 24)
        query.per_page = filters.per_page;

    return { ...query, ...extra };
}

export function hasActiveFilters(filters: Partial<Filters>): boolean {
    return Boolean(
        filters.q ||
        filters.type?.length ||
        filters.tags?.length ||
        filters.project ||
        filters.reading ||
        filters.from ||
        filters.to,
    );
}

export function toQueryString(query: FilterQuery): string {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(query)) {
        if (value === undefined || value === null || value === '') continue;
        if (Array.isArray(value)) {
            value.forEach((v) => params.append(`${key}[]`, String(v)));
        } else {
            params.set(key, String(value));
        }
    }
    const qs = params.toString();

    return qs ? `?${qs}` : '';
}
