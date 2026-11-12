import * as Popover from '@radix-ui/react-popover';
import { SlidersHorizontal } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { StashSheet } from '@/components/stash/overlay';
import { useIsDesktop } from '@/hooks/use-media';
import type {
    Filters,
    NavProject,
    NavTag,
    ReadingStatus,
    ResourceType,
    SectionKey,
} from '@/types/stash';

export type FilterDraft = Pick<
    Filters,
    'type' | 'tags' | 'tag_mode' | 'project' | 'reading' | 'from' | 'to'
>;

const TYPES: { key: ResourceType; label: string }[] = [
    { key: 'link', label: 'Link' },
    { key: 'article', label: 'Article' },
    { key: 'image', label: 'Image' },
];

/**
 * Custom filter panel (sheet 02 · 06): type, tags (Any/All), project incl.
 * Unassigned, reading status and created-date range. Popover on desktop,
 * bottom sheet on mobile.
 */
export function FilterPanel({
    filters,
    tags,
    projects,
    section,
    scopeType,
    activeCount,
    onApply,
    onClear,
}: {
    filters: Filters;
    tags: NavTag[];
    projects: NavProject[];
    section: SectionKey;
    scopeType: 'project' | 'tag' | null;
    activeCount: number;
    onApply: (draft: FilterDraft) => void;
    onClear: () => void;
}) {
    const [open, setOpen] = useState(false);
    const desktop = useIsDesktop();
    const [draft, setDraft] = useState<FilterDraft>(pick(filters));
    const [tagQuery, setTagQuery] = useState('');

    useEffect(() => {
        if (open) {
            setDraft(pick(filters));
            setTagQuery('');
        }
    }, [open, filters]);

    const toggle = <K extends 'type' | 'tags'>(
        key: K,
        value: FilterDraft[K][number],
    ) =>
        setDraft((d) => {
            const list = d[key] as string[];

            return {
                ...d,
                [key]: list.includes(value as string)
                    ? list.filter((v) => v !== value)
                    : [...list, value],
            };
        });

    const typeLocked =
        section === 'screenshots' ||
        section === 'bookmarks' ||
        section === 'read-later';
    const visibleTags = tags
        .filter((t) => t.name.toLowerCase().includes(tagQuery.toLowerCase()))
        .slice(0, 60);

    const body = (
        <div className="grid gap-5 sm:grid-cols-2">
            <fieldset className="flex flex-col gap-1">
                <legend className="st-field__label mb-1">Resource type</legend>
                {TYPES.map((t) => (
                    <label key={t.key} className="st-check !min-h-[36px]">
                        <input
                            type="checkbox"
                            className="st-checkbox"
                            checked={draft.type.includes(t.key)}
                            disabled={
                                typeLocked &&
                                section === 'screenshots' &&
                                t.key !== 'image'
                            }
                            onChange={() => toggle('type', t.key)}
                        />
                        {t.label}
                    </label>
                ))}
            </fieldset>

            <fieldset className="flex flex-col gap-1">
                <legend className="st-field__label mb-1">Reading status</legend>
                <p className="st-meta -mt-1 mb-1">Applies to articles only.</p>
                {(
                    [
                        [null, 'Any'],
                        ['unread', 'Unread'],
                        ['read', 'Read'],
                    ] as [ReadingStatus | null, string][]
                ).map(([value, label]) => (
                    <label key={label} className="st-check !min-h-[36px]">
                        <input
                            type="radio"
                            name="reading"
                            className="st-radio"
                            checked={draft.reading === value}
                            onChange={() =>
                                setDraft((d) => ({ ...d, reading: value }))
                            }
                        />
                        {label}
                    </label>
                ))}
            </fieldset>

            <div className="st-field sm:col-span-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="st-field__label" id="filter-tags-label">
                        Tags
                    </span>
                    <div
                        className="st-segmented"
                        role="radiogroup"
                        aria-label="Tag match mode"
                    >
                        {(['any', 'all'] as const).map((m) => (
                            <button
                                key={m}
                                type="button"
                                role="radio"
                                aria-checked={draft.tag_mode === m}
                                className="st-segmented__item !min-h-[32px]"
                                onClick={() =>
                                    setDraft((d) => ({ ...d, tag_mode: m }))
                                }
                            >
                                {m === 'any' ? 'Any tag' : 'All tags'}
                            </button>
                        ))}
                    </div>
                </div>
                {scopeType === 'tag' && (
                    <p className="st-meta">
                        Already scoped to one tag; extra tags narrow further.
                    </p>
                )}
                {tags.length > 8 && (
                    <input
                        type="search"
                        className="st-input st-input--sm"
                        placeholder="Find a tag…"
                        aria-label="Find a tag"
                        value={tagQuery}
                        onChange={(e) => setTagQuery(e.target.value)}
                    />
                )}
                <div
                    className="flex max-h-40 flex-wrap gap-2 overflow-y-auto p-1"
                    role="group"
                    aria-labelledby="filter-tags-label"
                >
                    {visibleTags.length === 0 && (
                        <p className="st-meta">No tags yet.</p>
                    )}
                    {visibleTags.map((t) => {
                        const on = draft.tags.includes(t.id);

                        return (
                            <button
                                key={t.id}
                                type="button"
                                aria-pressed={on}
                                className={
                                    on
                                        ? 'st-tag st-tag--selected'
                                        : 'st-tag st-tag--white'
                                }
                                onClick={() => toggle('tags', t.id)}
                            >
                                <span>{t.name}</span>
                                <span className="opacity-60">{t.count}</span>
                            </button>
                        );
                    })}
                </div>
            </div>

            <div className="st-field">
                <label htmlFor="filter-project" className="st-field__label">
                    Project
                </label>
                <select
                    id="filter-project"
                    className="st-select"
                    value={draft.project ?? ''}
                    disabled={scopeType === 'project'}
                    onChange={(e) =>
                        setDraft((d) => ({
                            ...d,
                            project: e.target.value || null,
                        }))
                    }
                >
                    <option value="">All projects</option>
                    <option value="none">Unassigned</option>
                    {projects.map((p) => (
                        <option key={p.id} value={p.id}>
                            {p.name}
                        </option>
                    ))}
                </select>
            </div>

            <fieldset className="st-field">
                <legend className="st-field__label mb-1.5">
                    Saved between
                </legend>
                <div className="grid grid-cols-2 gap-2">
                    <label className="sr-only" htmlFor="filter-from">
                        From date
                    </label>
                    <input
                        id="filter-from"
                        type="date"
                        className="st-input st-input--sm"
                        value={draft.from ?? ''}
                        onChange={(e) =>
                            setDraft((d) => ({
                                ...d,
                                from: e.target.value || null,
                            }))
                        }
                    />
                    <label className="sr-only" htmlFor="filter-to">
                        To date (inclusive)
                    </label>
                    <input
                        id="filter-to"
                        type="date"
                        className="st-input st-input--sm"
                        value={draft.to ?? ''}
                        onChange={(e) =>
                            setDraft((d) => ({
                                ...d,
                                to: e.target.value || null,
                            }))
                        }
                    />
                </div>
                <p className="st-meta">
                    Dates use your timezone; the end date is inclusive.
                </p>
            </fieldset>
        </div>
    );

    const footer = (
        <>
            <button
                type="button"
                className="st-btn st-btn--secondary flex-1"
                onClick={() => {
                    onClear();
                    setOpen(false);
                }}
            >
                Clear filters
            </button>
            <button
                type="button"
                className="st-btn st-btn--primary flex-1"
                onClick={() => {
                    onApply(draft);
                    setOpen(false);
                }}
            >
                Apply filters
            </button>
        </>
    );

    const trigger: ReactNode = (
        <button
            type="button"
            className={
                activeCount
                    ? 'st-btn st-btn--selected'
                    : 'st-btn st-btn--secondary'
            }
            aria-haspopup="dialog"
            aria-expanded={open}
        >
            <SlidersHorizontal aria-hidden="true" />
            <span>Filters</span>
            {activeCount > 0 && (
                <span
                    className="grid h-5 min-w-5 place-items-center rounded-full bg-ink px-1 text-[11px] text-white"
                    aria-label={`${activeCount} active`}
                >
                    {activeCount}
                </span>
            )}
        </button>
    );

    if (!desktop) {
        return (
            <>
                <span onClick={() => setOpen(true)}>{trigger}</span>
                <StashSheet
                    open={open}
                    onOpenChange={setOpen}
                    side="bottom"
                    title="Filters"
                    footer={footer}
                >
                    {body}
                </StashSheet>
            </>
        );
    }

    return (
        <Popover.Root open={open} onOpenChange={setOpen}>
            <Popover.Trigger asChild>{trigger}</Popover.Trigger>
            <Popover.Portal>
                <Popover.Content
                    align="end"
                    sideOffset={10}
                    collisionPadding={16}
                    className="st-panel z-50 w-[min(560px,calc(100vw-32px))] data-[state=open]:animate-in data-[state=open]:fade-in-0"
                    aria-label="Filters"
                >
                    <div className="st-panel__header">
                        <h2 className="flex items-center gap-2 text-lg font-extrabold">
                            <SlidersHorizontal
                                className="size-5"
                                aria-hidden="true"
                            />
                            Filters
                        </h2>
                    </div>
                    <div className="max-h-[60vh] overflow-y-auto p-5">
                        {body}
                    </div>
                    <div className="flex gap-3 border-t-[1.5px] border-line-soft p-4">
                        {footer}
                    </div>
                </Popover.Content>
            </Popover.Portal>
        </Popover.Root>
    );
}

function pick(f: Filters): FilterDraft {
    return {
        type: [...(f.type ?? [])],
        tags: [...(f.tags ?? [])],
        tag_mode: f.tag_mode ?? 'any',
        project: f.project ?? null,
        reading: f.reading ?? null,
        from: f.from ?? null,
        to: f.to ?? null,
    };
}
