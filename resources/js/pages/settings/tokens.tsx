import { Head, router, useForm } from '@inertiajs/react';
import { Check, Copy, KeyRound, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { ConfirmDialog } from '@/components/stash/overlay';
import { TimeAgo } from '@/components/stash/time-ago';

type Token = {
    id: number;
    name: string;
    abilities: string[];
    last_used_at: string | null;
    expires_at: string | null;
    created_at: string | null;
    expired: boolean;
};

export default function Tokens({
    tokens,
    abilities,
}: {
    tokens: Token[];
    abilities: Record<string, string>;
}) {
    const form = useForm<{
        name: string;
        abilities: string[];
        expires_in_days: string;
    }>({
        name: '',
        abilities: ['resources:read'],
        expires_in_days: '90',
    });
    const [newToken, setNewToken] = useState<{
        name: string;
        plain_text: string;
    } | null>(null);
    const [copied, setCopied] = useState(false);
    const [revoke, setRevoke] = useState<Token | null>(null);

    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash as
                | { new_token?: { name: string; plain_text: string } }
                | undefined;
            if (flash?.new_token) {
                setNewToken(flash.new_token);
                setCopied(false);
            }
        });
    }, []);

    const toggle = (ability: string) =>
        form.setData(
            'abilities',
            form.data.abilities.includes(ability)
                ? form.data.abilities.filter((a) => a !== ability)
                : [...form.data.abilities, ability],
        );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            ...d,
            expires_in_days:
                d.expires_in_days === 'never'
                    ? null
                    : Number(d.expires_in_days),
        }));
        form.post('/settings/tokens', {
            preserveScroll: true,
            onSuccess: () => form.reset('name'),
        });
    };

    return (
        <>
            <Head title="API tokens" />

            {newToken && (
                <div className="st-alert st-alert--warning" role="alert">
                    <TriangleAlert
                        className="st-alert__icon"
                        aria-hidden="true"
                    />
                    <div className="st-alert__content">
                        <p className="st-alert__title">
                            Copy “{newToken.name}” now
                        </p>
                        <p className="st-alert__body">
                            This is the only time the token is shown. Store it
                            in a secret manager.
                        </p>
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            <code
                                className="st-code flex-1 !py-2 select-all"
                                data-testid="new-token"
                            >
                                {newToken.plain_text}
                            </code>
                            <button
                                type="button"
                                className="st-btn st-btn--secondary st-btn--sm"
                                onClick={() => {
                                    void navigator.clipboard?.writeText(
                                        newToken.plain_text,
                                    );
                                    setCopied(true);
                                }}
                            >
                                {copied ? (
                                    <Check aria-hidden="true" />
                                ) : (
                                    <Copy aria-hidden="true" />
                                )}
                                {copied ? 'Copied' : 'Copy'}
                            </button>
                        </div>
                        <button
                            type="button"
                            className="st-alert__action mt-2"
                            onClick={() => setNewToken(null)}
                        >
                            I stored it — hide
                        </button>
                    </div>
                </div>
            )}

            <form
                onSubmit={submit}
                className="st-panel flex flex-col gap-5 p-5 sm:p-6"
            >
                <div>
                    <h2 className="st-h3">Create a scoped token</h2>
                    <p className="mt-1 text-sm text-muted-text">
                        Tokens authenticate{' '}
                        <code className="st-mono">/api/v1</code> (send{' '}
                        <code className="st-mono">Authorization: Bearer …</code>
                        ). Abilities limit what a token can do; tokens can never
                        access another owner’s data.
                    </p>
                </div>
                <div className="grid gap-4 sm:grid-cols-[1fr_auto]">
                    <div className="st-field">
                        <label htmlFor="token-name" className="st-field__label">
                            Name
                        </label>
                        <input
                            id="token-name"
                            className="st-input"
                            placeholder="e.g. Reading agent"
                            value={form.data.name}
                            maxLength={80}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            aria-invalid={form.errors.name ? true : undefined}
                        />
                        {form.errors.name && (
                            <p className="st-field__error">
                                {form.errors.name}
                            </p>
                        )}
                    </div>
                    <div className="st-field">
                        <label
                            htmlFor="token-expiry"
                            className="st-field__label"
                        >
                            Expires
                        </label>
                        <select
                            id="token-expiry"
                            className="st-select"
                            value={form.data.expires_in_days}
                            onChange={(e) =>
                                form.setData('expires_in_days', e.target.value)
                            }
                        >
                            <option value="7">In 7 days</option>
                            <option value="30">In 30 days</option>
                            <option value="90">In 90 days</option>
                            <option value="365">In 1 year</option>
                            <option value="never">Never</option>
                        </select>
                    </div>
                </div>
                <fieldset>
                    <legend className="st-field__label mb-2">Abilities</legend>
                    <div className="grid gap-1 sm:grid-cols-2">
                        {Object.entries(abilities).map(([key, label]) => (
                            <label
                                key={key}
                                className="st-check !min-h-0 items-start py-1.5"
                            >
                                <input
                                    type="checkbox"
                                    className="st-checkbox mt-0.5"
                                    checked={form.data.abilities.includes(key)}
                                    onChange={() => toggle(key)}
                                />
                                <span>
                                    <span className="st-mono font-bold">
                                        {key}
                                    </span>
                                    <span className="block text-xs font-normal text-muted-text">
                                        {label}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </div>
                    {form.errors.abilities && (
                        <p className="st-field__error mt-1">
                            {form.errors.abilities}
                        </p>
                    )}
                </fieldset>
                <div>
                    <button
                        type="submit"
                        className="st-btn st-btn--primary"
                        disabled={
                            form.processing || !form.data.abilities.length
                        }
                        aria-busy={form.processing || undefined}
                    >
                        <KeyRound aria-hidden="true" />
                        Create token
                    </button>
                </div>
            </form>

            <div className="flex flex-col gap-3">
                <h2 className="st-h3">Active tokens</h2>
                {tokens.length === 0 && (
                    <p className="text-muted-text">No tokens yet.</p>
                )}
                {tokens.map((t) => (
                    <div
                        key={t.id}
                        className="st-panel st-panel--flat flex flex-wrap items-start gap-3 p-4"
                    >
                        <div className="min-w-0 flex-1">
                            <p className="font-extrabold break-words">
                                {t.name}{' '}
                                {t.expired && (
                                    <span className="st-badge st-badge--trash ml-1">
                                        Expired
                                    </span>
                                )}
                            </p>
                            <div className="mt-1.5 flex flex-wrap gap-1.5">
                                {t.abilities.map((a) => (
                                    <span
                                        key={a}
                                        className="st-badge st-badge--type st-mono"
                                    >
                                        {a}
                                    </span>
                                ))}
                            </div>
                            <p className="st-meta mt-2">
                                {t.created_at && (
                                    <>
                                        Created <TimeAgo iso={t.created_at} />{' '}
                                        ·{' '}
                                    </>
                                )}
                                {t.last_used_at ? (
                                    <>
                                        Last used{' '}
                                        <TimeAgo iso={t.last_used_at} />
                                    </>
                                ) : (
                                    'Never used'
                                )}
                                {t.expires_at && (
                                    <>
                                        {' '}
                                        · Expires <TimeAgo iso={t.expires_at} />
                                    </>
                                )}
                            </p>
                        </div>
                        <button
                            type="button"
                            className="st-btn st-btn--danger st-btn--sm"
                            onClick={() => setRevoke(t)}
                        >
                            Revoke
                        </button>
                    </div>
                ))}
            </div>

            <ConfirmDialog
                open={revoke !== null}
                onOpenChange={(o) => !o && setRevoke(null)}
                title="Revoke token?"
                destructive
                confirmLabel="Revoke"
                body={
                    <>
                        Clients using <strong>{revoke?.name}</strong> will
                        immediately lose access.
                    </>
                }
                onConfirm={() =>
                    revoke &&
                    router.delete(`/settings/tokens/${revoke.id}`, {
                        preserveScroll: true,
                        onSuccess: () => setRevoke(null),
                    })
                }
            />
        </>
    );
}
