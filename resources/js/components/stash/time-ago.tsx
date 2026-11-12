import { usePage } from '@inertiajs/react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { exactTime, relativeTime } from '@/lib/format';

/** Relative time with the exact timestamp (user timezone) in a tooltip. */
export function TimeAgo({ iso, prefix }: { iso: string; prefix?: string }) {
    const tz = usePage().props.auth?.user?.timezone ?? 'UTC';
    const exact = exactTime(iso, tz);

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <time dateTime={iso} title={exact} className="cursor-default">
                    {prefix}
                    {relativeTime(iso)}
                </time>
            </TooltipTrigger>
            <TooltipContent>{exact}</TooltipContent>
        </Tooltip>
    );
}
