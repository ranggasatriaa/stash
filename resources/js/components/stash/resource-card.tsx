import { ExternalLink } from 'lucide-react';
import type { MouseEvent } from 'react';
import {
    ArchivedBadge,
    ProcessingBadge,
    ReadingBadge,
    TrashBadge,
    TypeBadge,
    TypeIcon,
} from '@/components/stash/badges';
import { TimeAgo } from '@/components/stash/time-ago';
import { cn } from '@/lib/utils';
import type { StashResource } from '@/types/stash';

export function ResourcePreview({ resource }: { resource: StashResource }) {
    const image = resource.thumbnail;

    return image ? (
        <img
            src={image.url}
            alt=""
            loading="lazy"
            decoding="async"
            width={image.width ?? undefined}
            height={image.height ?? undefined}
        />
    ) : (
        <div className="st-card__fallback">
            <TypeIcon type={resource.type} />
            {resource.host ? (
                <span className="st-card__fallback-host">
                    {resource.host.replace(/^www\./, '')}
                </span>
            ) : (
                <span className="st-card__fallback-host">
                    {resource.processing.status === 'ready' ||
                    resource.processing.status === 'none'
                        ? 'No preview'
                        : 'Preparing preview…'}
                </span>
            )}
        </div>
    );
}

export function ResourceCard({
    resource,
    href,
    selected,
    selectable,
    onOpen,
    onToggleSelect,
}: {
    resource: StashResource;
    href: string;
    selected: boolean;
    selectable: boolean;
    onOpen: (e: MouseEvent<HTMLAnchorElement>) => void;
    onToggleSelect: (shift: boolean) => void;
}) {
    const tags = resource.tags.slice(0, 4);
    const extra = resource.tags.length - tags.length;

    return (
        <article
            className={cn(
                'st-card st-card--interactive',
                selected && 'st-card--selected',
            )}
            aria-labelledby={`card-title-${resource.id}`}
            data-testid="resource-card"
        >
            <div className="st-card__media">
                <ResourcePreview resource={resource} />
                <div className="st-card__overlay st-card__overlay--tl">
                    <TypeBadge type={resource.type} />
                </div>
                {selectable && (
                    <div className="st-card__overlay st-card__overlay--tr">
                        <label
                            className="st-card__select"
                            onClick={(e) => e.stopPropagation()}
                        >
                            <input
                                type="checkbox"
                                className="st-checkbox"
                                checked={selected}
                                onClick={(e) => e.stopPropagation()}
                                onChange={() => onToggleSelect(false)}
                                aria-label={`Select ${resource.title}`}
                            />
                        </label>
                    </div>
                )}
            </div>
            <div className="st-card__body">
                <h3
                    className="st-card__title st-clamp-2"
                    id={`card-title-${resource.id}`}
                >
                    <a href={href} onClick={onOpen}>
                        {resource.title}
                    </a>
                </h3>
                {resource.url && resource.type !== 'image' && (
                    <a
                        href={resource.url}
                        target="_blank"
                        rel="noopener noreferrer nofollow"
                        className="st-card__host relative z-[3] w-fit"
                        aria-label={`Open ${resource.host ?? 'source'} in a new tab`}
                    >
                        <span>
                            {resource.site_name ||
                                resource.host?.replace(/^www\./, '')}
                        </span>
                        <ExternalLink aria-hidden="true" />
                    </a>
                )}
                {resource.description && (
                    <p className="st-card__desc st-clamp-2">
                        {resource.description}
                    </p>
                )}
                {(tags.length > 0 || resource.project) && (
                    <div className="st-tags">
                        {tags.map((t, i) => (
                            <span
                                key={t.id}
                                className={cn(
                                    'st-tag',
                                    i % 2 === 1 && 'st-tag--orange',
                                )}
                            >
                                <span>{t.name}</span>
                            </span>
                        ))}
                        {extra > 0 && (
                            <span className="st-tag st-tag--white">
                                +{extra}
                            </span>
                        )}
                    </div>
                )}
                <div className="st-card__meta">
                    {resource.project && (
                        <span className="inline-flex max-w-full min-w-0 items-center gap-1 font-bold text-ink">
                            <span
                                className="size-2.5 shrink-0 rounded-[3px] border-[1.5px] border-ink"
                                style={{
                                    background:
                                        resource.project.color ?? 'transparent',
                                }}
                                aria-hidden="true"
                            />
                            <span className="truncate">
                                {resource.project.name}
                            </span>
                        </span>
                    )}
                    <ReadingBadge status={resource.reading_status} />
                    {resource.read_later && (
                        <span className="st-badge st-badge--warning">
                            Read later
                        </span>
                    )}
                    <ProcessingBadge status={resource.processing.status} />
                    {resource.archived_at && !resource.deleted_at && (
                        <ArchivedBadge />
                    )}
                    {resource.deleted_at && <TrashBadge />}
                    <span className="ml-auto">
                        <TimeAgo iso={resource.created_at} />
                    </span>
                </div>
            </div>
        </article>
    );
}

export function ResourceCardSkeleton() {
    return (
        <div className="st-card st-card--loading" aria-hidden="true">
            <div className="st-card__media st-skeleton !rounded-none" />
            <div className="st-card__body">
                <div className="st-skeleton h-5 w-4/5" />
                <div className="st-skeleton h-4 w-2/5" />
                <div className="st-skeleton h-4 w-3/5" />
            </div>
        </div>
    );
}
