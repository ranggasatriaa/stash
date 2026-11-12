import { router } from '@inertiajs/react';
import { Bot, CircleAlert, Info, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { StashDialog } from '@/components/stash/overlay';
import { formatNumber } from '@/lib/format';
import { fetchJson, HttpError, newIdempotencyKey } from '@/lib/http';
import type { AiRun, ResourceDetail } from '@/types/stash';

type Preview = {
    source: 'article' | 'pasted' | 'metadata';
    text: string;
    chars: number;
    truncated: boolean;
    estimated_tokens: number;
    limitation: string | null;
    budget: { limit: number; used: number; remaining: number };
};

/**
 * Optional, manually triggered AI summary. Shows exactly what will be sent
 * before spending tokens; never sends images or personal notes.
 */
export function SummaryPanel({
    resource,
    enabled,
}: {
    resource: ResourceDetail;
    enabled: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [source, setSource] = useState<'article' | 'pasted' | 'metadata'>(
        'article',
    );
    const [pasted, setPasted] = useState('');
    const [preview, setPreview] = useState<Preview | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [ack, setAck] = useState(false);

    const summary = resource.summary;
    const active = resource.active_run;
    const failed: AiRun | null =
        resource.last_run?.status === 'failed' ? resource.last_run : null;

    const reset = () => {
        setPreview(null);
        setError(null);
        setAck(false);
    };

    const loadPreview = async () => {
        setBusy(true);
        setError(null);
        try {
            const res = await fetchJson<{ data: Preview }>(
                `/resources/${resource.id}/summaries/preview`,
                {
                    body: { source, text: source === 'pasted' ? pasted : null },
                },
            );
            setPreview(res.data);
        } catch (e) {
            setError(
                e instanceof HttpError
                    ? e.message
                    : 'Could not prepare the preview.',
            );
        } finally {
            setBusy(false);
        }
    };

    const start = async () => {
        if (!preview) return;
        setBusy(true);
        setError(null);
        try {
            await fetchJson(`/resources/${resource.id}/summaries`, {
                body: {
                    source: preview.source,
                    text: preview.source === 'metadata' ? null : preview.text,
                    confirm_metadata_only: ack,
                },
                headers: { 'Idempotency-Key': newIdempotencyKey() },
            });
            setOpen(false);
            reset();
            router.reload({ only: ['selected'] });
        } catch (e) {
            setError(
                e instanceof HttpError
                    ? e.message
                    : 'Could not start the summary.',
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <section
            aria-labelledby="summary-heading"
            className="flex flex-col gap-3"
        >
            <div className="flex items-center justify-between gap-2">
                <h3
                    id="summary-heading"
                    className="flex items-center gap-2 text-base font-extrabold"
                >
                    <Sparkles className="size-5" aria-hidden="true" />
                    AI summary
                </h3>
                {enabled && !active && (
                    <button
                        type="button"
                        className="st-btn st-btn--secondary st-btn--sm"
                        onClick={() => {
                            reset();
                            setSource(resource.url ? 'article' : 'pasted');
                            setOpen(true);
                        }}
                    >
                        <Bot aria-hidden="true" />
                        {summary ? 'Summarize again' : 'Summarize'}
                    </button>
                )}
            </div>

            {!enabled && (
                <p className="st-meta">
                    Optional AI summaries are off. An administrator can enable
                    them by configuring the DeepSeek variables on the server.
                </p>
            )}

            {active && (
                <div
                    className="st-alert st-alert--info"
                    role="status"
                    aria-live="polite"
                >
                    <span
                        className="st-spinner st-alert__icon"
                        aria-hidden="true"
                    />
                    <div className="st-alert__content">
                        <p className="st-alert__title">Summarizing…</p>
                        <p className="st-alert__body">
                            About {formatNumber(active.usage.reserved_tokens)}{' '}
                            tokens reserved from your monthly budget.
                        </p>
                    </div>
                </div>
            )}

            {failed &&
                !active &&
                (!summary || failed.created_at > summary.created_at) && (
                    <div className="st-alert st-alert--error" role="alert">
                        <CircleAlert
                            className="st-alert__icon"
                            aria-hidden="true"
                        />
                        <div className="st-alert__content">
                            <p className="st-alert__title">Summary failed</p>
                            <p className="st-alert__body">
                                {failed.error?.message ??
                                    'The provider did not return a usable summary.'}{' '}
                                Your resource is unchanged.
                            </p>
                        </div>
                    </div>
                )}

            {summary?.result && (
                <div className="rounded-[12px] border-2 border-ink bg-[#fffaf0] p-3">
                    <p className="st-meta mb-2 flex flex-wrap items-center gap-1.5">
                        <Info className="size-3.5" aria-hidden="true" />
                        AI-generated{summary.cached ? ' (cached)' : ''} ·{' '}
                        {summary.model} · from{' '}
                        {summary.source_kind === 'metadata'
                            ? 'metadata only'
                            : summary.source_kind === 'pasted'
                              ? 'pasted text'
                              : 'article text'}
                        {summary.usage.total_tokens
                            ? ` · ${formatNumber(summary.usage.total_tokens)} tokens`
                            : ''}
                    </p>
                    <p className="text-[15px] leading-relaxed">
                        {summary.result.summary}
                    </p>
                    {summary.result.key_points.length > 0 && (
                        <ul className="mt-2 list-disc space-y-1 pl-5 text-sm">
                            {summary.result.key_points.map((p, i) => (
                                <li key={i}>{p}</li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            <StashDialog
                open={open}
                onOpenChange={(o) => !busy && setOpen(o)}
                title="Summarize article"
                description="Nothing is sent until you confirm. Images and your personal notes are never sent."
                size="lg"
                footer={
                    <>
                        <button
                            type="button"
                            className="st-btn st-btn--secondary"
                            onClick={() => (preview ? reset() : setOpen(false))}
                            disabled={busy}
                        >
                            {preview ? 'Back' : 'Cancel'}
                        </button>
                        {preview ? (
                            <button
                                type="button"
                                className="st-btn st-btn--primary"
                                onClick={start}
                                aria-busy={busy || undefined}
                                disabled={
                                    busy ||
                                    (preview.source === 'metadata' && !ack) ||
                                    preview.estimated_tokens >
                                        preview.budget.remaining
                                }
                            >
                                Send to provider
                            </button>
                        ) : (
                            <button
                                type="button"
                                className="st-btn st-btn--primary"
                                onClick={loadPreview}
                                aria-busy={busy || undefined}
                                disabled={
                                    busy ||
                                    (source === 'pasted' &&
                                        pasted.trim().length < 50)
                                }
                            >
                                Preview what will be sent
                            </button>
                        )}
                    </>
                }
            >
                {error && (
                    <div className="st-alert st-alert--error mb-4" role="alert">
                        <CircleAlert
                            className="st-alert__icon"
                            aria-hidden="true"
                        />
                        <div className="st-alert__content">
                            <p className="st-alert__body">{error}</p>
                        </div>
                    </div>
                )}

                {!preview ? (
                    <div className="flex flex-col gap-4">
                        <fieldset className="flex flex-col gap-1">
                            <legend className="st-field__label mb-1">
                                Text source
                            </legend>
                            <label className="st-check">
                                <input
                                    type="radio"
                                    className="st-radio"
                                    name="ai-source"
                                    checked={source === 'article'}
                                    disabled={!resource.url}
                                    onChange={() => setSource('article')}
                                />
                                Extract readable text from the article URL
                            </label>
                            <label className="st-check">
                                <input
                                    type="radio"
                                    className="st-radio"
                                    name="ai-source"
                                    checked={source === 'pasted'}
                                    onChange={() => setSource('pasted')}
                                />
                                Paste the article text myself
                            </label>
                            <label className="st-check">
                                <input
                                    type="radio"
                                    className="st-radio"
                                    name="ai-source"
                                    checked={source === 'metadata'}
                                    onChange={() => setSource('metadata')}
                                />
                                Metadata only (title + description — limited)
                            </label>
                        </fieldset>
                        {source === 'pasted' && (
                            <div className="st-field">
                                <label
                                    htmlFor="ai-pasted"
                                    className="st-field__label"
                                >
                                    Article text
                                </label>
                                <textarea
                                    id="ai-pasted"
                                    className="st-textarea !min-h-[180px]"
                                    value={pasted}
                                    onChange={(e) => setPasted(e.target.value)}
                                />
                                <p className="st-field__hint">
                                    {pasted.trim().length < 50
                                        ? 'Paste at least 50 characters.'
                                        : `${formatNumber(pasted.length)} characters`}
                                </p>
                            </div>
                        )}
                    </div>
                ) : (
                    <div className="flex flex-col gap-3">
                        {preview.limitation && (
                            <div className="st-alert st-alert--warning">
                                <Info
                                    className="st-alert__icon"
                                    aria-hidden="true"
                                />
                                <div className="st-alert__content">
                                    <p className="st-alert__title">
                                        Limited source
                                    </p>
                                    <p className="st-alert__body">
                                        {preview.limitation}
                                    </p>
                                    <label className="st-check mt-1">
                                        <input
                                            type="checkbox"
                                            className="st-checkbox"
                                            checked={ack}
                                            onChange={(e) =>
                                                setAck(e.target.checked)
                                            }
                                        />
                                        I understand this summary is based on
                                        metadata only
                                    </label>
                                </div>
                            </div>
                        )}
                        <p className="text-sm font-bold">
                            Text to send ({formatNumber(preview.chars)}{' '}
                            characters
                            {preview.truncated
                                ? ', truncated to the limit'
                                : ''}
                            ):
                        </p>
                        <pre
                            className="st-code max-h-64 overflow-y-auto"
                            tabIndex={0}
                            aria-label="Text that will be sent"
                        >
                            {preview.text}
                        </pre>
                        <p className="st-meta">
                            Estimated cost ≈{' '}
                            {formatNumber(preview.estimated_tokens)} tokens ·
                            budget left this month:{' '}
                            {formatNumber(preview.budget.remaining)} of{' '}
                            {formatNumber(preview.budget.limit)} tokens.
                        </p>
                        {preview.estimated_tokens >
                            preview.budget.remaining && (
                            <p className="st-field__error">
                                This request exceeds the remaining monthly token
                                budget.
                            </p>
                        )}
                    </div>
                )}
            </StashDialog>
        </section>
    );
}
