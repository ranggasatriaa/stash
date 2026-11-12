import { cn } from '@/lib/utils';

/** Stacked-sheet mark used everywhere (favicon uses the same geometry). */
export function StashMark({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 32 32"
            fill="none"
            stroke="currentColor"
            strokeWidth={2.75}
            strokeLinejoin="round"
            strokeLinecap="round"
            aria-hidden="true"
            className={className}
        >
            <path d="M16 3.5 29 10l-13 6.5L3 10 16 3.5Z" />
            <path d="m3 16 13 6.5L29 16" />
            <path d="m3 22 13 6.5L29 22" />
        </svg>
    );
}

export function Brand({
    size = 'md',
    slogan = false,
    className,
}: {
    size?: 'sm' | 'md';
    slogan?: boolean;
    className?: string;
}) {
    return (
        <div className={className}>
            <span className={cn('st-brand', size === 'sm' && 'st-brand--sm')}>
                <StashMark className="st-brand__mark" />
                <span className="st-brand__word">Stash</span>
            </span>
            {slogan && (
                <p className="st-brand__slogan">
                    Good finds,
                    <br />
                    <em>kept close.</em>
                </p>
            )}
        </div>
    );
}
