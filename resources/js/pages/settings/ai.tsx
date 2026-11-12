import { Head } from '@inertiajs/react';
import { Bot, CircleCheck, Info } from 'lucide-react';
import { TimeAgo } from '@/components/stash/time-ago';
import { formatNumber } from '@/lib/format';
import type { AiRun } from '@/types/stash';

type Props = {
    status: {
        enabled: boolean;
        configured: boolean;
        reason: string | null;
        provider: string;
        model: string | null;
    };
    budget: {
        limit: number;
        used: number;
        remaining: number;
        period_start: string;
    };
    limits: { max_input_chars: number; max_output_tokens: number };
    runs: AiRun[];
};

export default function AiSettings({ status, budget, limits, runs }: Props) {
    const pct = budget.limit
        ? Math.min(100, Math.round((budget.used / budget.limit) * 100))
        : 0;

    return (
        <>
            <Head title="AI summaries" />
            <div className="st-panel flex flex-col gap-4 p-5 sm:p-6">
                <div className="flex items-start gap-3">
                    <Bot
                        className="mt-0.5 size-6 shrink-0"
                        aria-hidden="true"
                    />
                    <div>
                        <h2 className="st-h3">AI summaries (optional)</h2>
                        <p className="mt-1 text-sm text-muted-text">
                            Manually triggered article summaries via DeepSeek.
                            Stash works fully without AI. Keys live only in
                            server environment variables, never in the browser.
                        </p>
                    </div>
                </div>
                {status.enabled ? (
                    <div className="st-alert">
                        <CircleCheck
                            className="st-alert__icon"
                            aria-hidden="true"
                        />
                        <div className="st-alert__content">
                            <p className="st-alert__title">Enabled</p>
                            <p className="st-alert__body">
                                Provider: {status.provider} · model:{' '}
                                <code className="st-mono">{status.model}</code>
                            </p>
                        </div>
                    </div>
                ) : (
                    <div className="st-alert st-alert--info">
                        <Info className="st-alert__icon" aria-hidden="true" />
                        <div className="st-alert__content">
                            <p className="st-alert__title">Not enabled</p>
                            <p className="st-alert__body">{status.reason}</p>
                            <p className="st-alert__body mt-1">
                                Set{' '}
                                <code className="st-mono">AI_ENABLED=true</code>
                                ,{' '}
                                <code className="st-mono">
                                    DEEPSEEK_API_KEY
                                </code>{' '}
                                and{' '}
                                <code className="st-mono">DEEPSEEK_MODEL</code>,
                                then run{' '}
                                <code className="st-mono">
                                    php artisan stash:ai-check
                                </code>
                                .
                            </p>
                        </div>
                    </div>
                )}
                <div>
                    <div className="mb-1 flex flex-wrap justify-between gap-2 text-sm font-bold">
                        <span>Monthly token budget</span>
                        <span>
                            {formatNumber(budget.used)} /{' '}
                            {formatNumber(budget.limit)} tokens used
                        </span>
                    </div>
                    <div
                        className="st-progress"
                        role="progressbar"
                        aria-valuenow={pct}
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-label="Monthly token budget used"
                    >
                        <div
                            className="st-progress__bar"
                            style={{ width: `${pct}%` }}
                        />
                    </div>
                    <p className="st-meta mt-1">
                        Usage is shown in tokens (no currency estimates). Inputs
                        are capped at {formatNumber(limits.max_input_chars)}{' '}
                        characters and outputs at{' '}
                        {formatNumber(limits.max_output_tokens)} tokens.
                    </p>
                </div>
            </div>

            <div className="flex flex-col gap-3">
                <h2 className="st-h3">Recent runs</h2>
                {runs.length === 0 && (
                    <p className="text-muted-text">No summaries yet.</p>
                )}
                {runs.length > 0 && (
                    <div className="st-table-wrap">
                        <table className="st-table">
                            <thead>
                                <tr>
                                    <th scope="col">Resource</th>
                                    <th scope="col">Status</th>
                                    <th
                                        scope="col"
                                        className="hidden sm:table-cell"
                                    >
                                        Tokens
                                    </th>
                                    <th
                                        scope="col"
                                        className="hidden sm:table-cell"
                                    >
                                        When
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {runs.map((r) => (
                                    <tr key={r.id}>
                                        <td className="max-w-[16rem] truncate font-semibold">
                                            {r.resource_title ??
                                                'Deleted resource'}
                                        </td>
                                        <td>
                                            <span
                                                className={`st-badge ${r.status === 'succeeded' ? 'st-badge--read' : r.status === 'failed' ? 'st-badge--trash' : 'st-badge--unread'}`}
                                            >
                                                {r.status}
                                                {r.cached ? ' (cached)' : ''}
                                            </span>
                                            {r.error && (
                                                <span className="st-meta block">
                                                    {r.error.code}
                                                </span>
                                            )}
                                        </td>
                                        <td className="hidden sm:table-cell">
                                            {formatNumber(
                                                r.usage.total_tokens ??
                                                    r.usage.reserved_tokens,
                                            )}
                                            {r.usage.estimated ? ' (est.)' : ''}
                                        </td>
                                        <td className="hidden whitespace-nowrap sm:table-cell">
                                            <TimeAgo iso={r.created_at} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}
