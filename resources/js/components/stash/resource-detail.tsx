import { router, usePage } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    BookOpenCheck,
    CircleAlert,
    CircleDot,
    Download,
    ExternalLink,
    FileText,
    Image as ImageIcon,
    Link2,
    RefreshCw,
    RotateCcw,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import {
    ArchivedBadge,
    ProcessingBadge,
    ReadingBadge,
    TrashBadge,
    TypeBadge,
} from '@/components/stash/badges';
import { Markdown } from '@/components/stash/markdown';
import { ConfirmDialog, StashSheet } from '@/components/stash/overlay';
import { ResourcePreview } from '@/components/stash/resource-card';
import { SummaryPanel } from '@/components/stash/summary-panel';
import { ProjectSelect, TagInput } from '@/components/stash/tag-input';
import { TimeAgo } from '@/components/stash/time-ago';
import { useIsDesktop } from '@/hooks/use-media';
import { exactTime, formatBytes } from '@/lib/format';
import type { ResourceDetail, ResourceType } from '@/types/stash';

type FormState = {
    title: string;
    description: string;
    url: string;
    type: ResourceType;
    tags: string[];
    project_id: string | null;
    personal_notes: string;
};

function snapshot(r: ResourceDetail): FormState {
    return {
        title: r.title,
        description: r.description ?? '',
        url: r.url ?? '',
        type: r.type,
        tags: r.tags.map((t) => t.name),
        project_id: r.project_id,
        personal_notes: r.personal_notes ?? '',
    };
}

const visitOptions = { preserveScroll: true, preserveState: true } as const;

export function ResourceDetailPanel({
    resource,
    open,
    onClose,
}: {
    resource: ResourceDetail | null;
    open: boolean;
    onClose: () => void;
}) {
    const desktop = useIsDesktop();

    return (
        <StashSheet
            open={open && resource !== null}
            onOpenChange={(o) => !o && onClose()}
            side={desktop ? 'right' : 'full'}
            title="Resource details"
            hideTitle
            label="Resource details"
            header={resource ? <HeaderBadges resource={resource} /> : null}
        >
            {resource && (
                <DetailBody
                    key={resource.id}
                    resource={resource}
                    onClose={onClose}
                />
            )}
        </StashSheet>
    );
}

function HeaderBadges({ resource }: { resource: ResourceDetail }) {
    return (
        <div className="flex min-w-0 flex-wrap items-center gap-1.5">
            <TypeBadge type={resource.type} />
            <ReadingBadge status={resource.reading_status} />
            {resource.archived_at && !resource.deleted_at && <ArchivedBadge />}
            {resource.deleted_at && <TrashBadge />}
        </div>
    );
}

function DetailBody({
    resource,
    onClose,
}: {
    resource: ResourceDetail;
    onClose: () => void;
}) {
    const page = usePage();
    const tz = page.props.auth?.user?.timezone ?? 'UTC';
    const aiEnabled = Boolean(page.props.ai?.enabled);
    const serverErrors = (page.props.errors ?? {}) as Record<string, string>;

    const [form, setForm] = useState<FormState>(() => snapshot(resource));
    const [base, setBase] = useState<FormState>(() => snapshot(resource));
    const [baseVersion, setBaseVersion] = useState(resource.version);
    const [notesTab, setNotesTab] = useState<'write' | 'preview'>(
        resource.personal_notes ? 'preview' : 'write',
    );
    const [saving, setSaving] = useState(false);
    const [conflict, setConflict] = useState<string | null>(null);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const dirty = useMemo(
        () => JSON.stringify(form) !== JSON.stringify(base),
        [form, base],
    );

    // Adopt server changes (e.g. metadata enrichment) unless the user is editing.
    useEffect(() => {
        if (resource.version !== baseVersion && !dirty) {
            setForm(snapshot(resource));
            setBase(snapshot(resource));
            setBaseVersion(resource.version);
        }
    }, [resource, baseVersion, dirty]);

    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash as
                | { conflict?: { message: string } }
                | undefined;
            if (flash?.conflict) {
                setConflict(flash.conflict.message);
            }
        });
    }, []);

    const set = <K extends keyof FormState>(key: K, value: FormState[K]) =>
        setForm((f) => ({ ...f, [key]: value }));

    const save = (e?: FormEvent, overwrite = false) => {
        e?.preventDefault();
        const changes: Record<string, unknown> = {};
        (Object.keys(form) as (keyof FormState)[]).forEach((k) => {
            if (JSON.stringify(form[k]) !== JSON.stringify(base[k])) {
                changes[k] = form[k];
            }
        });
        if (!Object.keys(changes).length) return;
        setSaving(true);
        setConflict(null);
        router.patch(
            `/resources/${resource.id}`,
            {
                ...changes,
                version: overwrite ? resource.version : baseVersion,
            } as never,
            {
                ...visitOptions,
                only: ['selected', 'resources', 'nav', 'errors', 'flash'],
                onSuccess: (p) => {
                    const fresh = (
                        p.props as unknown as { selected?: ResourceDetail }
                    ).selected;
                    if (
                        fresh &&
                        !(p.props.errors as Record<string, string>)?.version
                    ) {
                        setForm(snapshot(fresh));
                        setBase(snapshot(fresh));
                        setBaseVersion(fresh.version);
                    }
                },
                onFinish: () => setSaving(false),
            },
        );
    };

    const quick = (
        method: 'post' | 'patch' | 'delete',
        url: string,
        data: Record<string, unknown> = {},
    ) =>
        router.visit(url, {
            method,
            data: data as never,
            ...visitOptions,
            only: ['selected', 'resources', 'nav', 'flash', 'errors'],
        });

    const isArticle = resource.type === 'article';
    const trashed = Boolean(resource.deleted_at);
    const archived = Boolean(resource.archived_at);
    const pending =
        resource.processing.status === 'queued' ||
        resource.processing.status === 'processing';
    const image = resource.preview ?? resource.original;

    return (
        <div className="flex flex-col gap-5 pb-6">
            <div className="overflow-hidden rounded-[16px] border-2 border-ink bg-yellow-soft">
                {image ? (
                    <a
                        href={resource.original?.url ?? image.url}
                        target="_blank"
                        rel="noopener"
                        className="block"
                        aria-label="Open the full image in a new tab"
                    >
                        <img
                            src={image.url}
                            alt={resource.title}
                            className="max-h-[360px] w-full object-contain"
                        />
                    </a>
                ) : (
                    <div className="aspect-[16/9]">
                        <ResourcePreview resource={resource} />
                    </div>
                )}
            </div>

            <div className="flex flex-wrap gap-2">
                {resource.url && (
                    <a
                        href={resource.url}
                        target="_blank"
                        rel="noopener noreferrer nofollow"
                        className="st-btn st-btn--secondary st-btn--sm"
                    >
                        <ExternalLink aria-hidden="true" />
                        {resource.type === 'image'
                            ? 'Open source'
                            : 'Open original'}
                    </a>
                )}
                {resource.original && (
                    <a
                        href={
                            resource.original.download_url ??
                            resource.original.url
                        }
                        className="st-btn st-btn--secondary st-btn--sm"
                    >
                        <Download aria-hidden="true" />
                        Download ({formatBytes(resource.original.size_bytes)})
                    </a>
                )}
                {isArticle && !trashed && (
                    <>
                        <button
                            type="button"
                            className="st-btn st-btn--secondary st-btn--sm"
                            onClick={() =>
                                quick('patch', `/resources/${resource.id}`, {
                                    reading_status:
                                        resource.reading_status === 'read'
                                            ? 'unread'
                                            : 'read',
                                })
                            }
                        >
                            {resource.reading_status === 'read' ? (
                                <CircleDot aria-hidden="true" />
                            ) : (
                                <BookOpenCheck aria-hidden="true" />
                            )}
                            {resource.reading_status === 'read'
                                ? 'Mark unread'
                                : 'Mark read'}
                        </button>
                        <label className="st-check !min-h-[36px] px-1">
                            <input
                                type="checkbox"
                                role="switch"
                                className="st-switch"
                                checked={resource.read_later}
                                onChange={(e) =>
                                    quick(
                                        'patch',
                                        `/resources/${resource.id}`,
                                        { read_later: e.target.checked },
                                    )
                                }
                            />
                            Read later
                        </label>
                    </>
                )}
            </div>

            {pending && (
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
                        <p className="st-alert__title">
                            {resource.type === 'image'
                                ? 'Generating previews…'
                                : 'Fetching title, description and preview…'}
                        </p>
                        <p className="st-alert__body">
                            You can keep editing; your changes always win over
                            fetched metadata.
                        </p>
                    </div>
                </div>
            )}

            {resource.processing.status === 'failed' && (
                <div className="st-alert st-alert--warning" role="alert">
                    <TriangleAlert
                        className="st-alert__icon"
                        aria-hidden="true"
                    />
                    <div className="st-alert__content">
                        <p className="st-alert__title">
                            {resource.type === 'image'
                                ? 'Preview unavailable'
                                : 'Metadata unavailable'}
                        </p>
                        <p className="st-alert__body">
                            {resource.processing.error ??
                                'Something went wrong while processing.'}
                        </p>
                        <button
                            type="button"
                            className="st-alert__action mt-1 inline-flex items-center gap-1"
                            onClick={() =>
                                quick(
                                    'post',
                                    `/resources/${resource.id}/refresh`,
                                )
                            }
                        >
                            <RefreshCw className="size-4" aria-hidden="true" />{' '}
                            Retry
                        </button>
                    </div>
                </div>
            )}

            {Object.keys(resource.source_proposals).length > 0 && (
                <div className="st-alert st-alert--info">
                    <RefreshCw className="st-alert__icon" aria-hidden="true" />
                    <div className="st-alert__content">
                        <p className="st-alert__title">
                            The source has different details
                        </p>
                        {resource.source_proposals.title && (
                            <p className="st-alert__body">
                                Title:{' '}
                                <q className="font-semibold">
                                    {resource.source_proposals.title}
                                </q>
                            </p>
                        )}
                        {resource.source_proposals.description && (
                            <p className="st-alert__body st-clamp-3">
                                Description:{' '}
                                <q>{resource.source_proposals.description}</q>
                            </p>
                        )}
                        <button
                            type="button"
                            className="st-alert__action mt-1"
                            onClick={() =>
                                quick(
                                    'post',
                                    `/resources/${resource.id}/apply-source`,
                                    {
                                        fields: Object.keys(
                                            resource.source_proposals,
                                        ),
                                        version: resource.version,
                                    },
                                )
                            }
                        >
                            Replace my edits with these
                        </button>
                    </div>
                </div>
            )}

            {conflict && (
                <div className="st-alert st-alert--error" role="alert">
                    <CircleAlert
                        className="st-alert__icon"
                        aria-hidden="true"
                    />
                    <div className="st-alert__content">
                        <p className="st-alert__title">Changed elsewhere</p>
                        <p className="st-alert__body">
                            {conflict} Your unsaved edits are still in the form.
                        </p>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <button
                                type="button"
                                className="st-btn st-btn--secondary st-btn--sm"
                                onClick={() => {
                                    setForm(snapshot(resource));
                                    setBase(snapshot(resource));
                                    setBaseVersion(resource.version);
                                    setConflict(null);
                                }}
                            >
                                Discard mine, load latest
                            </button>
                            <button
                                type="button"
                                className="st-btn st-btn--primary st-btn--sm"
                                onClick={() => save(undefined, true)}
                            >
                                Save mine over it
                            </button>
                        </div>
                    </div>
                </div>
            )}

            <form
                onSubmit={save}
                className="flex flex-col gap-4"
                aria-label="Edit resource"
            >
                <div className="st-field">
                    <label htmlFor="detail-title" className="st-field__label">
                        Title
                    </label>
                    <textarea
                        id="detail-title"
                        rows={2}
                        className="st-textarea !min-h-0 font-extrabold"
                        value={form.title}
                        maxLength={500}
                        required
                        aria-invalid={serverErrors.title ? true : undefined}
                        onChange={(e) =>
                            set('title', e.target.value.replace(/\n/g, ' '))
                        }
                    />
                    {serverErrors.title && (
                        <p className="st-field__error">{serverErrors.title}</p>
                    )}
                    {resource.manual_fields.includes('title') && (
                        <p className="st-field__hint">
                            Edited by you — automatic refreshes keep your title.
                        </p>
                    )}
                </div>

                <fieldset className="st-field">
                    <legend className="st-field__label mb-1.5">Type</legend>
                    <div
                        className="st-segmented"
                        role="radiogroup"
                        aria-label="Type"
                    >
                        {(
                            [
                                ['link', 'Link', Link2],
                                ['article', 'Article', FileText],
                                ['image', 'Image', ImageIcon],
                            ] as const
                        ).map(([key, label, Icon]) => (
                            <button
                                key={key}
                                type="button"
                                role="radio"
                                aria-checked={form.type === key}
                                className="st-segmented__item"
                                disabled={key === 'image' && !resource.original}
                                title={
                                    key === 'image' && !resource.original
                                        ? 'Only uploaded images can use the image type'
                                        : undefined
                                }
                                onClick={() => set('type', key)}
                            >
                                <Icon aria-hidden="true" />
                                {label}
                            </button>
                        ))}
                    </div>
                    {serverErrors.type && (
                        <p className="st-field__error">{serverErrors.type}</p>
                    )}
                    {form.type === 'article' && resource.type !== 'article' && (
                        <p className="st-field__hint">
                            Becomes an unread article.
                        </p>
                    )}
                </fieldset>

                <div className="st-field">
                    <label htmlFor="detail-url" className="st-field__label">
                        {form.type === 'image'
                            ? 'Attribution URL (optional)'
                            : 'Source URL'}
                    </label>
                    <input
                        id="detail-url"
                        type="url"
                        className="st-input"
                        value={form.url}
                        aria-invalid={serverErrors.url ? true : undefined}
                        onChange={(e) => set('url', e.target.value)}
                    />
                    {serverErrors.url && (
                        <p className="st-field__error">{serverErrors.url}</p>
                    )}
                </div>

                <div className="st-field">
                    <label
                        htmlFor="detail-description"
                        className="st-field__label"
                    >
                        Description
                    </label>
                    <textarea
                        id="detail-description"
                        className="st-textarea !min-h-[88px]"
                        value={form.description}
                        maxLength={5000}
                        onChange={(e) => set('description', e.target.value)}
                    />
                </div>

                <TagInput
                    value={form.tags}
                    onChange={(tags) => set('tags', tags)}
                    id="detail-tags"
                />
                <ProjectSelect
                    value={form.project_id}
                    onChange={(id) => set('project_id', id)}
                    id="detail-project"
                    error={serverErrors.project_id}
                />

                <div className="st-field">
                    <div className="flex items-center justify-between gap-2">
                        <span className="st-field__label" id="notes-label">
                            Personal notes
                        </span>
                        <div
                            className="st-segmented"
                            role="tablist"
                            aria-label="Notes mode"
                        >
                            {(['write', 'preview'] as const).map((t) => (
                                <button
                                    key={t}
                                    type="button"
                                    role="tab"
                                    aria-selected={notesTab === t}
                                    className="st-segmented__item !min-h-[30px]"
                                    onClick={() => setNotesTab(t)}
                                >
                                    {t === 'write' ? 'Write' : 'Preview'}
                                </button>
                            ))}
                        </div>
                    </div>
                    {notesTab === 'write' ? (
                        <textarea
                            aria-labelledby="notes-label"
                            className="st-textarea !min-h-[140px] font-[450]"
                            placeholder="Markdown supported: **bold**, lists, links…"
                            value={form.personal_notes}
                            onChange={(e) =>
                                set('personal_notes', e.target.value)
                            }
                        />
                    ) : (
                        <div
                            className="min-h-[60px] rounded-[12px] border-2 border-dashed border-[#d9d0c2] bg-white p-3"
                            aria-labelledby="notes-label"
                        >
                            {form.personal_notes.trim() ? (
                                <Markdown>{form.personal_notes}</Markdown>
                            ) : (
                                <p className="st-meta">No notes yet.</p>
                            )}
                        </div>
                    )}
                </div>

                <div
                    className={
                        dirty
                            ? 'sticky bottom-0 z-10 -mx-4 flex gap-3 border-t-[1.5px] border-line-soft bg-white px-4 py-3'
                            : 'flex'
                    }
                    aria-live="polite"
                >
                    {dirty ? (
                        <>
                            <button
                                type="button"
                                className="st-btn st-btn--secondary flex-1"
                                onClick={() => setForm(base)}
                                disabled={saving}
                            >
                                Discard
                            </button>
                            <button
                                type="submit"
                                className="st-btn st-btn--primary flex-1"
                                aria-busy={saving || undefined}
                                disabled={saving}
                            >
                                Save changes
                            </button>
                        </>
                    ) : (
                        <p className="st-meta">
                            All changes saved · version {resource.version}
                        </p>
                    )}
                </div>
            </form>

            {isArticle && !trashed && (
                <SummaryPanel resource={resource} enabled={aiEnabled} />
            )}

            <section
                aria-label="Lifecycle"
                className="flex flex-wrap gap-2 border-t-[1.5px] border-line-soft pt-4"
            >
                {!trashed && (
                    <button
                        type="button"
                        className="st-btn st-btn--secondary st-btn--sm"
                        onClick={() =>
                            quick(
                                'post',
                                `/resources/${resource.id}/${archived ? 'unarchive' : 'archive'}`,
                            )
                        }
                    >
                        {archived ? (
                            <ArchiveRestore aria-hidden="true" />
                        ) : (
                            <Archive aria-hidden="true" />
                        )}
                        {archived ? 'Restore from Archive' : 'Archive'}
                    </button>
                )}
                {(resource.type !== 'image' ||
                    resource.processing.status === 'failed') &&
                    !trashed &&
                    resource.url && (
                        <button
                            type="button"
                            className="st-btn st-btn--secondary st-btn--sm"
                            onClick={() =>
                                quick(
                                    'post',
                                    `/resources/${resource.id}/refresh`,
                                )
                            }
                        >
                            <RefreshCw aria-hidden="true" />
                            Refresh metadata
                        </button>
                    )}
                {trashed ? (
                    <>
                        <button
                            type="button"
                            className="st-btn st-btn--secondary st-btn--sm"
                            onClick={() =>
                                quick(
                                    'post',
                                    `/resources/${resource.id}/restore`,
                                )
                            }
                        >
                            <RotateCcw aria-hidden="true" />
                            Restore from Trash
                        </button>
                        <button
                            type="button"
                            className="st-btn st-btn--danger st-btn--sm"
                            onClick={() => setConfirmDelete(true)}
                        >
                            <Trash2 aria-hidden="true" />
                            Delete permanently
                        </button>
                    </>
                ) : (
                    <button
                        type="button"
                        className="st-btn st-btn--danger st-btn--sm"
                        onClick={() =>
                            quick('delete', `/resources/${resource.id}`)
                        }
                    >
                        <Trash2 aria-hidden="true" />
                        Move to Trash
                    </button>
                )}
            </section>

            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
                <dt className="font-bold">Saved</dt>
                <dd className="text-muted-text">
                    <TimeAgo iso={resource.created_at} /> ·{' '}
                    {exactTime(resource.created_at, tz)}
                </dd>
                <dt className="font-bold">Updated</dt>
                <dd className="text-muted-text">
                    {exactTime(resource.updated_at, tz)}
                </dd>
                {resource.read_at && (
                    <>
                        <dt className="font-bold">Read</dt>
                        <dd className="text-muted-text">
                            {exactTime(resource.read_at, tz)}
                        </dd>
                    </>
                )}
                {resource.archived_at && (
                    <>
                        <dt className="font-bold">Archived</dt>
                        <dd className="text-muted-text">
                            {exactTime(resource.archived_at, tz)}
                        </dd>
                    </>
                )}
                {resource.deleted_at && (
                    <>
                        <dt className="font-bold">Trashed</dt>
                        <dd className="text-muted-text">
                            {exactTime(resource.deleted_at, tz)}
                        </dd>
                    </>
                )}
                {resource.original && (
                    <>
                        <dt className="font-bold">Image</dt>
                        <dd className="text-muted-text">
                            {resource.original.width}×{resource.original.height}{' '}
                            ·{' '}
                            {resource.original.mime_type
                                .replace('image/', '')
                                .toUpperCase()}
                        </dd>
                    </>
                )}
                <dt className="font-bold">Status</dt>
                <dd>
                    <ProcessingBadge status={resource.processing.status} />
                    {resource.processing.status === 'ready' && (
                        <span className="text-muted-text">Ready</span>
                    )}
                    {resource.processing.status === 'none' && (
                        <span className="text-muted-text">—</span>
                    )}
                </dd>
            </dl>

            <ConfirmDialog
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                title="Delete resource?"
                destructive
                busy={deleting}
                confirmLabel="Delete permanently"
                body={
                    <>
                        <strong className="break-words">
                            {resource.title}
                        </strong>{' '}
                        and its stored files will be removed for good. This
                        can’t be undone.
                    </>
                }
                onConfirm={() => {
                    setDeleting(true);
                    router.delete(`/resources/${resource.id}/permanent`, {
                        data: { confirm: true },
                        preserveScroll: true,
                        onSuccess: () => {
                            setConfirmDelete(false);
                            onClose();
                        },
                        onFinish: () => setDeleting(false),
                    });
                }}
            />
        </div>
    );
}
