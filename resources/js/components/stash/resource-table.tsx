import { ExternalLink } from 'lucide-react';
import type { MouseEvent } from 'react';
import {
    ArchivedBadge,
    ProcessingBadge,
    ReadingBadge,
    TrashBadge,
    TYPE_META,
} from '@/components/stash/badges';
import { TimeAgo } from '@/components/stash/time-ago';
import { cn } from '@/lib/utils';
import type { StashResource } from '@/types/stash';

export function ResourceTable({
    resources,
    hrefFor,
    selected,
    onOpen,
    onToggle,
    onToggleAll,
    onContextMenu,
}: {
    onContextMenu?: (r: StashResource, e: MouseEvent<HTMLElement>) => void;
    resources: StashResource[];
    hrefFor: (r: StashResource) => string;
    selected: Set<string>;
    onOpen: (r: StashResource, e: MouseEvent<HTMLAnchorElement>) => void;
    onToggle: (id: string) => void;
    onToggleAll: (checked: boolean) => void;
}) {
    const allChecked =
        resources.length > 0 && resources.every((r) => selected.has(r.id));
    const someChecked =
        !allChecked && resources.some((r) => selected.has(r.id));

    return (
        <div className="st-table-wrap">
            <table className="st-table">
                <caption className="sr-only">Resources</caption>
                <thead>
                    <tr>
                        <th scope="col" className="w-12">
                            <input
                                type="checkbox"
                                className="st-checkbox"
                                aria-label="Select all on this page"
                                checked={allChecked}
                                ref={(el) => {
                                    if (el) el.indeterminate = someChecked;
                                }}
                                onChange={(e) => onToggleAll(e.target.checked)}
                            />
                        </th>
                        <th scope="col">Title</th>
                        <th scope="col" className="hidden md:table-cell">
                            Type
                        </th>
                        <th scope="col" className="hidden lg:table-cell">
                            Tags
                        </th>
                        <th scope="col" className="hidden sm:table-cell">
                            Saved
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {resources.map((r) => {
                        const { Icon, label } = TYPE_META[r.type];

                        return (
                            <tr
                                key={r.id}
                                aria-selected={selected.has(r.id)}
                                onContextMenu={(e) => onContextMenu?.(r, e)}
                            >
                                <td>
                                    <input
                                        type="checkbox"
                                        className="st-checkbox"
                                        checked={selected.has(r.id)}
                                        onChange={() => onToggle(r.id)}
                                        aria-label={`Select ${r.title}`}
                                    />
                                </td>
                                <td className="max-w-0 min-w-[12rem]">
                                    <a
                                        href={hrefFor(r)}
                                        onClick={(e) => onOpen(r, e)}
                                        className="block truncate font-extrabold text-ink no-underline hover:underline"
                                    >
                                        {r.title}
                                    </a>
                                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                        {r.url && r.type !== 'image' && (
                                            <a
                                                href={r.url}
                                                target="_blank"
                                                rel="noopener noreferrer nofollow"
                                                className="st-card__host max-w-[16rem]"
                                            >
                                                <span>
                                                    {r.host?.replace(
                                                        /^www\./,
                                                        '',
                                                    )}
                                                </span>
                                                <ExternalLink aria-hidden="true" />
                                            </a>
                                        )}
                                        <ReadingBadge
                                            status={r.reading_status}
                                        />
                                        <ProcessingBadge
                                            status={r.processing.status}
                                        />
                                        {r.archived_at && !r.deleted_at && (
                                            <ArchivedBadge />
                                        )}
                                        {r.deleted_at && <TrashBadge />}
                                    </div>
                                </td>
                                <td className="hidden md:table-cell">
                                    <span className="inline-flex items-center gap-1.5 font-semibold">
                                        <Icon
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        {label}
                                    </span>
                                </td>
                                <td className="hidden lg:table-cell">
                                    <div className="st-tags">
                                        {r.tags.slice(0, 3).map((t, i) => (
                                            <span
                                                key={t.id}
                                                className={cn(
                                                    'st-tag',
                                                    i % 2 === 1 &&
                                                        'st-tag--orange',
                                                )}
                                            >
                                                <span>{t.name}</span>
                                            </span>
                                        ))}
                                        {r.tags.length > 3 && (
                                            <span className="st-tag st-tag--white">
                                                +{r.tags.length - 3}
                                            </span>
                                        )}
                                    </div>
                                </td>
                                <td className="hidden text-sm whitespace-nowrap text-muted-text sm:table-cell">
                                    <TimeAgo iso={r.created_at} />
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
