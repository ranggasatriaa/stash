import { Head, router, usePage } from '@inertiajs/react';
import {
    Bookmark,
    BookmarkPlus,
    ChevronLeft,
    ChevronRight,
    LayoutGrid,
    Plus,
    Rows3,
    Search,
    SearchX,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { DragEvent, MouseEvent } from 'react';
import { BulkBar } from '@/components/stash/bulk-bar';
import type { FilterDraft } from '@/components/stash/filter-panel';
import { FilterPanel } from '@/components/stash/filter-panel';
import { StashDialog } from '@/components/stash/overlay';
import { ResourceCard } from '@/components/stash/resource-card';
import { useResourceContextMenu } from '@/components/stash/resource-context-menu';
import { ResourceDetailPanel } from '@/components/stash/resource-detail';
import { ResourceTable } from '@/components/stash/resource-table';
import { useSave } from '@/components/stash/save-context';
import { formatNumber, shortDate } from '@/lib/format';
import {
    filtersToQuery,
    hasActiveFilters,
    SECTION_LABELS,
    sectionPath,
    SORT_LABELS,
    toQueryString,
} from '@/lib/library';
import type { FilterQuery } from '@/lib/library';
import type {
    Filters,
    PageMeta,
    ResourceDetail,
    Scope,
    SectionKey,
    SortKey,
    StashResource,
} from '@/types/stash';

type Props = {
    section: SectionKey;
    scope: Scope;
    filters: Filters;
    resources: { data: StashResource[]; meta: PageMeta };
    selected: ResourceDetail | null;
    layout: 'grid' | 'table';
};

const EMPTY: Record<SectionKey, { title: string; body: string }> = {
    all: {
        title: 'Your stash starts here',
        body: 'Save links, images and articles to keep them in one place.',
    },
    inbox: {
        title: 'Inbox zero',
        body: 'Everything is tagged or filed in a project. Nice.',
    },
    bookmarks: {
        title: 'No bookmarks yet',
        body: 'Links and articles you save appear here.',
    },
    screenshots: {
        title: 'No screenshots yet',
        body: 'Upload a JPG, PNG or WebP image to start a visual library.',
    },
    'read-later': {
        title: 'Nothing to read later',
        body: 'Mark an article “Read later” to queue it here.',
    },
    archive: {
        title: 'Nothing archived',
        body: 'Archived items stay searchable here without cluttering your library.',
    },
    trash: {
        title: 'Trash is empty',
        body: 'Items you move to Trash can be restored from here.',
    },
};

export default function LibraryIndex() {
    const {
        section,
        scope,
        filters,
        resources,
        selected,
        layout: initialLayout,
    } = usePage<Props>().props;
    const { openSave } = useSave();
    const path = sectionPath(section, scope);
    const [q, setQ] = useState(filters.q ?? '');
    const [selection, setSelection] = useState<Set<string>>(new Set());
    const [layout, setLayout] = useState<'grid' | 'table'>(initialLayout);
    const [dragging, setDragging] = useState(false);
    const [saveViewOpen, setSaveViewOpen] = useState(false);
    const [viewName, setViewName] = useState('');
    const searchRef = useRef<HTMLInputElement>(null);
    const lastQ = useRef(filters.q ?? '');

    const baseQuery = useMemo(() => filtersToQuery(filters), [filters]);
    const currentResourceId =
        selected?.id ??
        new URLSearchParams(
            typeof window !== 'undefined' ? window.location.search : '',
        ).get('resource');

    const visit = useCallback(
        (query: FilterQuery, opts: Record<string, unknown> = {}) => {
            router.get(path, query as never, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['resources', 'filters', 'selected', 'nav'],
                ...opts,
            });
        },
        [path],
    );

    // Debounced search; Inertia cancels the in-flight visit when a new one starts.
    useEffect(() => {
        if (q === lastQ.current) return;
        const timer = window.setTimeout(() => {
            lastQ.current = q;
            const next = {
                ...baseQuery,
                q: q.trim() || undefined,
                page: undefined,
            };
            if (!q.trim() && next.sort === 'relevance') delete next.sort;
            visit(next);
        }, 300);

        return () => window.clearTimeout(timer);
    }, [q, baseQuery, visit]);

    useEffect(() => {
        if (filters.q !== lastQ.current && (filters.q ?? '') !== q) {
            lastQ.current = filters.q ?? '';
            setQ(filters.q ?? '');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [filters.q]);

    useEffect(() => {
        setSelection(new Set());
    }, [
        section,
        scope?.id,
        JSON.stringify(baseQuery),
        resources.meta.current_page,
    ]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        if (
            new URLSearchParams(window.location.search).get('focus') ===
            'search'
        ) {
            searchRef.current?.focus();
        }
        const onKey = (e: KeyboardEvent) => {
            const target = e.target as HTMLElement;
            if (
                e.key === '/' &&
                !['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) &&
                !target.isContentEditable
            ) {
                e.preventDefault();
                searchRef.current?.focus();
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    // Poll while metadata, image processing or an AI summary is pending.
    const pending =
        resources.data.some(
            (r) =>
                r.processing.status === 'queued' ||
                r.processing.status === 'processing',
        ) ||
        Boolean(
            selected &&
            (selected.processing.status === 'queued' ||
                selected.processing.status === 'processing' ||
                selected.active_run),
        );
    useEffect(() => {
        if (!pending) return;
        let ticks = 0;
        const timer = window.setInterval(() => {
            ticks++;
            if (ticks > 60 || document.hidden) return;
            router.reload({
                only: ['resources', 'selected', 'nav'],
                async: true,
                showProgress: false,
            });
        }, 2500);

        return () => window.clearInterval(timer);
    }, [pending]);

    const resourceHref = (r: StashResource) =>
        path +
        toQueryString({
            ...baseQuery,
            page:
                resources.meta.current_page > 1
                    ? resources.meta.current_page
                    : undefined,
            resource: r.id,
        });

    const openResource = (
        r: StashResource,
        e?: MouseEvent<HTMLAnchorElement>,
    ) => {
        if (e && (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0))
            return;
        e?.preventDefault();
        router.get(
            path,
            {
                ...baseQuery,
                page:
                    resources.meta.current_page > 1
                        ? resources.meta.current_page
                        : undefined,
                resource: r.id,
            } as never,
            {
                preserveState: true,
                preserveScroll: true,
                only: ['selected'],
            },
        );
    };

    const closeResource = () => {
        router.get(
            path,
            {
                ...baseQuery,
                page:
                    resources.meta.current_page > 1
                        ? resources.meta.current_page
                        : undefined,
            } as never,
            {
                preserveState: true,
                preserveScroll: true,
                only: ['selected'],
            },
        );
    };

    const applyFilters = (draft: FilterDraft) =>
        visit(
            filtersToQuery({
                ...filters,
                ...draft,
                q: filters.q,
                sort: filters.sort,
            }),
            { replace: false },
        );

    // Clear resets search + filters but keeps navigation (section/scope) and sort.
    const clearFilters = () => {
        setQ('');
        lastQ.current = '';
        visit(
            filtersToQuery({
                sort: filters.sort === 'relevance' ? null : filters.sort,
                per_page: filters.per_page,
            }),
            { replace: false },
        );
    };

    const removeChip = (key: keyof Filters, value?: string) => {
        const next: Partial<Filters> = { ...filters };
        if (key === 'type' || key === 'tags') {
            next[key] = (filters[key] as string[]).filter(
                (v) => v !== value,
            ) as never;
        } else {
            (next as Record<string, unknown>)[key] = null;
        }
        if (key === 'q') {
            setQ('');
            lastQ.current = '';
            if (next.sort === 'relevance') next.sort = null;
        }
        visit(filtersToQuery(next), { replace: false });
    };

    const setSort = (sort: SortKey) =>
        visit({ ...baseQuery, sort, page: undefined });
    const setPage = (page: number) =>
        visit(
            { ...baseQuery, page },
            { preserveScroll: false, replace: false },
        );
    const setPerPage = (perPage: number) => {
        visit({ ...baseQuery, per_page: perPage, page: undefined });
        router.patch(
            '/settings/preferences',
            { page_size: perPage, silent: true },
            {
                async: true,
                preserveState: true,
                preserveScroll: true,
                showProgress: false,
                only: ['auth'],
            },
        );
    };
    const changeLayout = (next: 'grid' | 'table') => {
        setLayout(next);
        router.patch(
            '/settings/preferences',
            { layout: next, silent: true },
            {
                async: true,
                preserveState: true,
                preserveScroll: true,
                showProgress: false,
                only: ['auth'],
            },
        );
    };

    const ctxMenu = useResourceContextMenu((r) => openResource(r));

    const toggle = (id: string) =>
        setSelection((s) => {
            const n = new Set(s);
            if (n.has(id)) n.delete(id);
            else n.add(id);

            return n;
        });

    const nav = usePage().props.nav;
    const tagName = (id: string) =>
        nav?.tags.find((t) => t.id === id)?.name ?? 'Tag';
    const projectName = (id: string) =>
        id === 'none'
            ? 'Unassigned'
            : (nav?.projects.find((p) => p.id === id)?.name ?? 'Project');
    const tz = usePage().props.auth?.user?.timezone ?? 'UTC';

    const chips: { key: string; label: string; onRemove: () => void }[] = [];
    if (filters.q)
        chips.push({
            key: 'q',
            label: `“${filters.q}”`,
            onRemove: () => removeChip('q'),
        });
    filters.type.forEach((t) =>
        chips.push({
            key: `type-${t}`,
            label: `Type: ${t}`,
            onRemove: () => removeChip('type', t),
        }),
    );
    filters.tags.forEach((t) =>
        chips.push({
            key: `tag-${t}`,
            label: `${filters.tag_mode === 'all' ? 'All of' : 'Tag'}: ${tagName(t)}`,
            onRemove: () => removeChip('tags', t),
        }),
    );
    if (filters.project)
        chips.push({
            key: 'project',
            label: `Project: ${projectName(filters.project)}`,
            onRemove: () => removeChip('project'),
        });
    if (filters.reading)
        chips.push({
            key: 'reading',
            label: `Articles: ${filters.reading}`,
            onRemove: () => removeChip('reading'),
        });
    if (filters.from)
        chips.push({
            key: 'from',
            label: `From ${shortDate(`${filters.from}T12:00:00Z`, tz)}`,
            onRemove: () => removeChip('from'),
        });
    if (filters.to)
        chips.push({
            key: 'to',
            label: `To ${shortDate(`${filters.to}T12:00:00Z`, tz)}`,
            onRemove: () => removeChip('to'),
        });

    const active = hasActiveFilters(filters);
    const filterCount = chips.length - (filters.q ? 1 : 0);
    const heading = scope ? scope.name : SECTION_LABELS[section];
    const meta = resources.meta;
    const sorts: SortKey[] = filters.q
        ? ['relevance', 'newest', 'oldest', 'title']
        : ['newest', 'oldest', 'title'];

    const onDrop = (e: DragEvent) => {
        if (!e.dataTransfer.types.includes('Files')) return;
        e.preventDefault();
        setDragging(false);
        const files = Array.from(e.dataTransfer.files);
        if (files.length) openSave('upload', files.slice(0, 1));
    };

    return (
        <div
            className="relative mx-auto flex max-w-[1400px] flex-col gap-5"
            onDragOver={(e) => {
                if (e.dataTransfer.types.includes('Files')) {
                    e.preventDefault();
                    setDragging(true);
                }
            }}
            onDragLeave={(e) => {
                if (e.currentTarget === e.target) setDragging(false);
            }}
            onDrop={onDrop}
        >
            <Head title={heading} />

            <header className="flex flex-col gap-4">
                <div className="flex flex-wrap items-center gap-3">
                    <div className="st-section-title mr-auto min-w-0">
                        <h1 className="st-h2 st-truncate">{heading}</h1>
                        <span
                            className="st-section-title__count"
                            aria-label={`${meta.total} items`}
                        >
                            {formatNumber(meta.total)}
                        </span>
                    </div>
                    <button
                        type="button"
                        className="st-btn st-btn--primary hidden lg:inline-flex"
                        onClick={() => openSave('link')}
                    >
                        <Plus aria-hidden="true" />
                        Save
                    </button>
                </div>
                {scope?.type === 'project' && scope.description && (
                    <p className="-mt-2 max-w-3xl text-muted-text">
                        {scope.description}
                    </p>
                )}

                <div className="st-toolbar">
                    <div className="st-search min-w-[12rem] flex-1 basis-full sm:basis-auto">
                        <Search
                            className="st-search__icon"
                            aria-hidden="true"
                        />
                        <label htmlFor="library-search" className="sr-only">
                            Search your stash
                        </label>
                        <input
                            ref={searchRef}
                            id="library-search"
                            type="search"
                            className="st-input"
                            placeholder="Search your stash…"
                            value={q}
                            autoComplete="off"
                            onChange={(e) => setQ(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Escape' && q) {
                                    e.preventDefault();
                                    setQ('');
                                }
                            }}
                        />
                        {q && (
                            <button
                                type="button"
                                className="st-search__clear st-icon-btn !h-9 !w-9"
                                aria-label="Clear search"
                                onClick={() => setQ('')}
                            >
                                <X aria-hidden="true" />
                            </button>
                        )}
                    </div>
                    <FilterPanel
                        filters={filters}
                        tags={nav?.tags ?? []}
                        projects={nav?.projects ?? []}
                        section={section}
                        scopeType={scope?.type ?? null}
                        activeCount={filterCount}
                        onApply={applyFilters}
                        onClear={clearFilters}
                    />
                    <label htmlFor="library-sort" className="sr-only">
                        Sort
                    </label>
                    <select
                        id="library-sort"
                        className="st-select !w-auto min-w-[10.5rem] font-bold"
                        value={filters.sort_effective}
                        onChange={(e) => setSort(e.target.value as SortKey)}
                    >
                        {sorts.map((s) => (
                            <option key={s} value={s}>
                                Sort: {SORT_LABELS[s]}
                            </option>
                        ))}
                    </select>
                    <div
                        className="st-segmented hidden sm:inline-flex"
                        role="group"
                        aria-label="Layout"
                    >
                        <button
                            type="button"
                            className="st-segmented__item !min-h-[44px]"
                            aria-pressed={layout === 'grid'}
                            onClick={() => changeLayout('grid')}
                            aria-label="Grid view"
                        >
                            <LayoutGrid aria-hidden="true" />
                        </button>
                        <button
                            type="button"
                            className="st-segmented__item !min-h-[44px]"
                            aria-pressed={layout === 'table'}
                            onClick={() => changeLayout('table')}
                            aria-label="List view"
                        >
                            <Rows3 aria-hidden="true" />
                        </button>
                    </div>
                </div>

                {chips.length > 0 && (
                    <div
                        className="flex flex-wrap items-center gap-2"
                        aria-label="Active filters"
                    >
                        {chips.map((chip) => (
                            <span key={chip.key} className="st-chip">
                                <span className="st-chip__label">
                                    {chip.label}
                                </span>
                                <button
                                    type="button"
                                    className="st-chip__remove"
                                    aria-label={`Remove filter ${chip.label}`}
                                    onClick={chip.onRemove}
                                >
                                    <X aria-hidden="true" />
                                </button>
                            </span>
                        ))}
                        <button
                            type="button"
                            className="st-btn st-btn--ghost st-btn--sm"
                            onClick={clearFilters}
                        >
                            Clear filters
                        </button>
                        <button
                            type="button"
                            className="st-btn st-btn--ghost st-btn--sm"
                            onClick={() => {
                                setViewName('');
                                setSaveViewOpen(true);
                            }}
                        >
                            <BookmarkPlus aria-hidden="true" />
                            Save view
                        </button>
                    </div>
                )}
            </header>

            {resources.data.length > 0 && (
                <div className="flex flex-wrap items-center gap-3 text-sm">
                    <label className="st-check !min-h-[32px]">
                        <input
                            type="checkbox"
                            className="st-checkbox"
                            checked={resources.data.every((r) =>
                                selection.has(r.id),
                            )}
                            onChange={(e) =>
                                setSelection(
                                    e.target.checked
                                        ? new Set(
                                              resources.data.map((r) => r.id),
                                          )
                                        : new Set(),
                                )
                            }
                        />
                        Select page
                    </label>
                    <span className="text-muted-text" aria-live="polite">
                        Showing {meta.from}–{meta.to} of{' '}
                        {formatNumber(meta.total)}
                    </span>
                </div>
            )}

            {resources.data.length === 0 ? (
                <div className="st-panel st-panel--flat border-dashed">
                    <div className="st-empty">
                        {active ? (
                            <SearchX
                                className="st-empty__icon"
                                aria-hidden="true"
                            />
                        ) : (
                            <Bookmark
                                className="st-empty__icon"
                                aria-hidden="true"
                            />
                        )}
                        <h2 className="st-empty__title">
                            {active ? 'No results' : EMPTY[section].title}
                        </h2>
                        <p className="st-empty__body">
                            {active
                                ? 'No resources match your search and filters.'
                                : EMPTY[section].body}
                        </p>
                        {active ? (
                            <button
                                type="button"
                                className="st-btn st-btn--secondary"
                                onClick={clearFilters}
                            >
                                Clear filters
                            </button>
                        ) : (
                            !['trash', 'archive', 'inbox'].includes(
                                section,
                            ) && (
                                <button
                                    type="button"
                                    className="st-btn st-btn--primary"
                                    onClick={() =>
                                        openSave(
                                            section === 'screenshots'
                                                ? 'upload'
                                                : 'link',
                                        )
                                    }
                                >
                                    <Plus aria-hidden="true" />
                                    {section === 'screenshots'
                                        ? 'Upload image'
                                        : 'Save resource'}
                                </button>
                            )
                        )}
                    </div>
                </div>
            ) : layout === 'table' ? (
                <ResourceTable
                    resources={resources.data}
                    hrefFor={resourceHref}
                    selected={selection}
                    onOpen={(r, e) => openResource(r, e)}
                    onToggle={toggle}
                    onContextMenu={ctxMenu.openMenu}
                    onToggleAll={(checked) =>
                        setSelection(
                            checked
                                ? new Set(resources.data.map((r) => r.id))
                                : new Set(),
                        )
                    }
                />
            ) : (
                <div className="st-grid" aria-label="Resources">
                    {resources.data.map((r) => (
                        <ResourceCard
                            key={r.id}
                            resource={r}
                            href={resourceHref(r)}
                            selected={selection.has(r.id)}
                            selectable
                            onOpen={(e) => openResource(r, e)}
                            onToggleSelect={() => toggle(r.id)}
                            onContextMenu={(e) => ctxMenu.openMenu(r, e)}
                        />
                    ))}
                </div>
            )}

            {(meta.last_page > 1 || meta.total > 24) && (
                <Pagination
                    meta={meta}
                    onPage={setPage}
                    onPerPage={setPerPage}
                />
            )}

            {ctxMenu.element}

            {selection.size > 0 && (
                <BulkBar
                    section={section}
                    selected={[...selection]}
                    resources={resources.data}
                    onClear={() => setSelection(new Set())}
                />
            )}

            {dragging && (
                <div className="pointer-events-none fixed inset-0 z-40 grid place-items-center bg-[rgb(255_116_23/0.12)] p-6">
                    <div className="st-dropzone st-dropzone--active w-full max-w-md">
                        <p className="st-dropzone__title">Drop to upload</p>
                        <p className="st-dropzone__hint">JPG, PNG or WebP</p>
                    </div>
                </div>
            )}

            <ResourceDetailPanel
                resource={selected}
                open={Boolean(currentResourceId && selected)}
                onClose={closeResource}
            />

            <StashDialog
                open={saveViewOpen}
                onOpenChange={setSaveViewOpen}
                title="Save this view"
                description="Saved views keep the current section, search, filters and sort in the sidebar."
                size="sm"
                footer={
                    <>
                        <button
                            type="button"
                            className="st-btn st-btn--secondary"
                            onClick={() => setSaveViewOpen(false)}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="st-btn st-btn--primary"
                            disabled={!viewName.trim()}
                            onClick={() =>
                                router.post(
                                    '/saved-views',
                                    {
                                        name: viewName,
                                        section,
                                        filters: { ...baseQuery },
                                    } as never,
                                    {
                                        preserveScroll: true,
                                        preserveState: true,
                                        onSuccess: () => setSaveViewOpen(false),
                                    },
                                )
                            }
                        >
                            Save view
                        </button>
                    </>
                }
            >
                <div className="st-field">
                    <label htmlFor="view-name" className="st-field__label">
                        Name
                    </label>
                    <input
                        id="view-name"
                        className="st-input"
                        value={viewName}
                        maxLength={80}
                        onChange={(e) => setViewName(e.target.value)}
                    />
                </div>
            </StashDialog>
        </div>
    );
}

function Pagination({
    meta,
    onPage,
    onPerPage,
}: {
    meta: PageMeta;
    onPage: (p: number) => void;
    onPerPage: (n: number) => void;
}) {
    const pages: (number | '…')[] = [];
    const { current_page: current, last_page: last } = meta;
    for (let p = 1; p <= last; p++) {
        if (p === 1 || p === last || Math.abs(p - current) <= 1) {
            pages.push(p);
        } else if (pages[pages.length - 1] !== '…') {
            pages.push('…');
        }
    }

    return (
        <nav
            className="flex flex-wrap items-center justify-between gap-3"
            aria-label="Pagination"
        >
            <label className="flex items-center gap-2 text-sm font-bold">
                <span className="sr-only">Items per page</span>
                <select
                    className="st-select st-input--sm !w-auto"
                    value={meta.per_page}
                    onChange={(e) => onPerPage(Number(e.target.value))}
                >
                    {[24, 48, 96].map((n) => (
                        <option key={n} value={n}>
                            {n} / page
                        </option>
                    ))}
                </select>
            </label>
            <div className="st-pagination">
                <button
                    type="button"
                    className="st-pagination__page"
                    aria-label="Previous page"
                    aria-disabled={current <= 1}
                    disabled={current <= 1}
                    onClick={() => onPage(current - 1)}
                >
                    <ChevronLeft className="size-5" aria-hidden="true" />
                </button>
                {pages.map((p, i) =>
                    p === '…' ? (
                        <span
                            key={`gap-${i}`}
                            className="px-1 font-bold"
                            aria-hidden="true"
                        >
                            …
                        </span>
                    ) : (
                        <button
                            key={p}
                            type="button"
                            className="st-pagination__page"
                            aria-current={p === current ? 'page' : undefined}
                            aria-label={`Page ${p}`}
                            onClick={() => onPage(p)}
                        >
                            {p}
                        </button>
                    ),
                )}
                <button
                    type="button"
                    className="st-pagination__page"
                    aria-label="Next page"
                    aria-disabled={current >= last}
                    disabled={current >= last}
                    onClick={() => onPage(current + 1)}
                >
                    <ChevronRight className="size-5" aria-hidden="true" />
                </button>
            </div>
        </nav>
    );
}
