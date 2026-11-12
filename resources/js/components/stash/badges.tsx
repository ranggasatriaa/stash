import {
    Archive,
    BookOpenCheck,
    CircleDot,
    FileText,
    Film,
    Image,
    Link2,
    Loader2,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import type {
    ProcessingStatus,
    ReadingStatus,
    ResourceType,
} from '@/types/stash';

export const TYPE_META: Record<
    ResourceType,
    { label: string; Icon: typeof Link2 }
> = {
    link: { label: 'Link', Icon: Link2 },
    image: { label: 'Image', Icon: Image },
    article: { label: 'Article', Icon: FileText },
    video: { label: 'Video', Icon: Film },
};

export function TypeIcon({
    type,
    className,
}: {
    type: ResourceType;
    className?: string;
}) {
    const { Icon } = TYPE_META[type];

    return <Icon className={className} aria-hidden="true" />;
}

export function TypeBadge({ type }: { type: ResourceType }) {
    const { label, Icon } = TYPE_META[type];

    return (
        <span className="st-badge st-badge--type">
            <Icon aria-hidden="true" />
            {label}
        </span>
    );
}

export function ReadingBadge({ status }: { status: ReadingStatus | null }) {
    if (!status) return null;

    return status === 'read' ? (
        <span className="st-badge st-badge--read">
            <BookOpenCheck aria-hidden="true" />
            Read
        </span>
    ) : (
        <span className="st-badge st-badge--unread">
            <CircleDot aria-hidden="true" />
            Unread
        </span>
    );
}

export function ArchivedBadge() {
    return (
        <span className="st-badge st-badge--archived">
            <Archive aria-hidden="true" />
            Archived
        </span>
    );
}

export function TrashBadge() {
    return (
        <span className="st-badge st-badge--trash">
            <Trash2 aria-hidden="true" />
            In Trash
        </span>
    );
}

export function ProcessingBadge({ status }: { status: ProcessingStatus }) {
    if (status === 'queued' || status === 'processing') {
        return (
            <span className="st-badge st-badge--unread" role="status">
                <Loader2
                    className="animate-spin motion-reduce:animate-none"
                    aria-hidden="true"
                />
                {status === 'queued' ? 'Queued' : 'Processing'}
            </span>
        );
    }
    if (status === 'failed') {
        return (
            <span className="st-badge st-badge--warning">
                <TriangleAlert aria-hidden="true" />
                Needs attention
            </span>
        );
    }

    return null;
}
