import { Head, router, useForm } from '@inertiajs/react';
import {
    CircleAlert,
    CloudUpload,
    Download,
    FileArchive,
    FileJson,
    NotebookText,
    Trash2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { TimeAgo } from '@/components/stash/time-ago';
import { formatBytes, formatNumber } from '@/lib/format';

type ExportRow = {
    id: string;
    format: 'json' | 'zip' | 'obsidian';
    include_trash: boolean;
    status: string;
    filename: string | null;
    size_bytes: number | null;
    checksum_sha256: string | null;
    stats: Record<string, number>;
    error: string | null;
    expires_at: string | null;
    created_at: string;
    download_url: string | null;
};

type ImportRow = {
    id: string;
    format: string;
    status: string;
    original_filename: string;
    size_bytes: number;
    collision_strategy: string;
    preview: null | {
        counts: Record<string, number>;
        collisions: Record<string, number>;
        missing_attachments: number;
        duplicates: {
            import_id: string;
            title: string;
            existing_id: string;
            existing_title: string;
        }[];
        errors: { index: number; message: string }[];
        error_count: number;
        warnings: string[];
        exported_at: string | null;
    };
    report: null | {
        created: Record<string, number>;
        reused: Record<string, number>;
        skipped: { id: string; reason: string }[];
        errors: { index: number; message: string }[];
    };
    error: string | null;
    created_at: string;
};

type Props = {
    exports: ExportRow[];
    imports: ImportRow[];
    storage: {
        by_role: Record<string, { files: number; bytes: number }>;
        exports_bytes: number;
        imports_bytes: number;
        total_bytes: number;
        resources: number;
    };
    import_max_bytes: number;
};

const FORMATS = [
    {
        key: 'zip',
        label: 'Full backup (ZIP)',
        hint: 'JSON metadata + original images + manifest with checksums. Re-importable.',
        Icon: FileArchive,
    },
    {
        key: 'json',
        label: 'Metadata only (JSON)',
        hint: 'Resources, tags, projects, notes and summaries. No image files.',
        Icon: FileJson,
    },
    {
        key: 'obsidian',
        label: 'Obsidian snapshot (ZIP)',
        hint: 'One-way Markdown vault: Resources/, Projects/, attachments/. Not synced.',
        Icon: NotebookText,
    },
] as const;

export default function DataSettings({
    exports,
    imports,
    storage,
    import_max_bytes,
}: Props) {
    const exportForm = useForm<{ format: string; include_trash: boolean }>({
        format: 'zip',
        include_trash: false,
    });
    const importForm = useForm<{ file: File | null }>({ file: null });
    const fileRef = useRef<HTMLInputElement>(null);
    const [strategy, setStrategy] = useState<Record<string, string>>({});

    const busy =
        exports.some(
            (e) => e.status === 'queued' || e.status === 'processing',
        ) ||
        imports.some((i) =>
            ['uploaded', 'analyzing', 'applying'].includes(i.status),
        );
    useEffect(() => {
        if (!busy) return;
        const t = window.setInterval(
            () =>
                router.reload({
                    only: ['exports', 'imports', 'storage'],
                    async: true,
                    showProgress: false,
                }),
            2500,
        );

        return () => window.clearInterval(t);
    }, [busy]);

    const submitExport = (e: FormEvent) => {
        e.preventDefault();
        exportForm.post('/settings/exports', { preserveScroll: true });
    };

    const submitImport = (e: FormEvent) => {
        e.preventDefault();
        importForm.post('/settings/imports', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => importForm.reset(),
        });
    };

    return (
        <>
            <Head title="Data & export" />

            <div className="st-panel p-5 sm:p-6">
                <h2 className="st-h3">Storage</h2>
                <dl className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {[
                        ['Resources', formatNumber(storage.resources)],
                        [
                            'Originals',
                            formatBytes(storage.by_role.original?.bytes ?? 0),
                        ],
                        [
                            'Previews',
                            formatBytes(
                                (storage.by_role.thumbnail?.bytes ?? 0) +
                                    (storage.by_role.preview?.bytes ?? 0),
                            ),
                        ],
                        ['Total', formatBytes(storage.total_bytes)],
                    ].map(([k, v]) => (
                        <div
                            key={k}
                            className="rounded-[12px] border-2 border-ink bg-ivory p-3"
                        >
                            <dt className="st-meta font-bold">{k}</dt>
                            <dd className="text-lg font-extrabold">{v}</dd>
                        </div>
                    ))}
                </dl>
            </div>

            <form
                onSubmit={submitExport}
                className="st-panel flex flex-col gap-4 p-5 sm:p-6"
            >
                <div>
                    <h2 className="st-h3">Export</h2>
                    <p className="mt-1 text-sm text-muted-text">
                        Exports are private, need you to be signed in to
                        download, and expire automatically.
                    </p>
                </div>
                <fieldset className="grid gap-3 sm:grid-cols-3">
                    <legend className="sr-only">Export format</legend>
                    {FORMATS.map(({ key, label, hint, Icon }) => (
                        <label
                            key={key}
                            className={`flex cursor-pointer flex-col gap-1.5 rounded-[16px] border-2 border-ink p-3 ${exportForm.data.format === key ? 'bg-yellow-soft shadow-[3px_3px_0_#191A1D]' : 'bg-white'}`}
                        >
                            <span className="flex items-center gap-2 font-extrabold">
                                <input
                                    type="radio"
                                    name="format"
                                    className="st-radio"
                                    checked={exportForm.data.format === key}
                                    onChange={() =>
                                        exportForm.setData('format', key)
                                    }
                                />
                                <Icon className="size-5" aria-hidden="true" />
                                {label}
                            </span>
                            <span className="text-xs text-muted-text">
                                {hint}
                            </span>
                        </label>
                    ))}
                </fieldset>
                <label className="st-check">
                    <input
                        type="checkbox"
                        className="st-checkbox"
                        checked={exportForm.data.include_trash}
                        onChange={(e) =>
                            exportForm.setData(
                                'include_trash',
                                e.target.checked,
                            )
                        }
                    />
                    Include items in Trash
                </label>
                <div>
                    <button
                        type="submit"
                        className="st-btn st-btn--primary"
                        disabled={exportForm.processing}
                        aria-busy={exportForm.processing || undefined}
                    >
                        <Download aria-hidden="true" />
                        Create export
                    </button>
                </div>

                {exports.length > 0 && (
                    <ul
                        className="flex flex-col gap-2"
                        aria-label="Recent exports"
                    >
                        {exports.map((x) => (
                            <li
                                key={x.id}
                                className="flex flex-wrap items-center gap-3 rounded-[12px] border-2 border-line-soft p-3"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="font-bold break-words">
                                        {x.filename ??
                                            `${x.format.toUpperCase()} export`}
                                    </p>
                                    <p className="st-meta">
                                        <TimeAgo iso={x.created_at} /> ·{' '}
                                        {x.status}
                                        {x.size_bytes
                                            ? ` · ${formatBytes(x.size_bytes)}`
                                            : ''}
                                        {x.stats?.resources !== undefined
                                            ? ` · ${x.stats.resources} resources`
                                            : ''}
                                        {x.include_trash
                                            ? ' · incl. Trash'
                                            : ''}
                                        {x.expires_at &&
                                        x.status === 'ready' ? (
                                            <>
                                                {' '}
                                                · expires{' '}
                                                <TimeAgo iso={x.expires_at} />
                                            </>
                                        ) : null}
                                    </p>
                                    {x.error && (
                                        <p className="st-field__error">
                                            {x.error}
                                        </p>
                                    )}
                                </div>
                                {x.status === 'queued' ||
                                x.status === 'processing' ? (
                                    <span
                                        className="st-spinner"
                                        role="status"
                                        aria-label="Preparing export"
                                    />
                                ) : x.download_url ? (
                                    <a
                                        href={x.download_url}
                                        className="st-btn st-btn--secondary st-btn--sm"
                                    >
                                        <Download aria-hidden="true" />
                                        Download
                                    </a>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}
            </form>

            <form
                onSubmit={submitImport}
                className="st-panel flex flex-col gap-4 p-5 sm:p-6"
            >
                <div>
                    <h2 className="st-h3">Import a Stash export</h2>
                    <p className="mt-1 text-sm text-muted-text">
                        Upload a Stash <strong>.json</strong> or full-backup{' '}
                        <strong>.zip</strong> (up to{' '}
                        {formatBytes(import_max_bytes)}). You’ll see a preview
                        before anything is written; existing items are never
                        overwritten.
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-3">
                    <input
                        ref={fileRef}
                        type="file"
                        accept=".json,.zip,application/json,application/zip"
                        className="sr-only"
                        id="import-file"
                        onChange={(e) =>
                            importForm.setData(
                                'file',
                                e.target.files?.[0] ?? null,
                            )
                        }
                    />
                    <label
                        htmlFor="import-file"
                        className="st-btn st-btn--secondary"
                    >
                        <CloudUpload aria-hidden="true" />
                        Choose file
                    </label>
                    <span className="min-w-0 truncate text-sm font-semibold">
                        {importForm.data.file
                            ? `${importForm.data.file.name} · ${formatBytes(importForm.data.file.size)}`
                            : 'No file selected'}
                    </span>
                    <button
                        type="submit"
                        className="st-btn st-btn--primary"
                        disabled={
                            !importForm.data.file || importForm.processing
                        }
                        aria-busy={importForm.processing || undefined}
                    >
                        Upload & analyze
                    </button>
                </div>
                {importForm.progress && (
                    <div
                        className="st-progress"
                        role="progressbar"
                        aria-valuenow={importForm.progress.percentage}
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-label="Upload progress"
                    >
                        <div
                            className="st-progress__bar"
                            style={{
                                width: `${importForm.progress.percentage}%`,
                            }}
                        />
                    </div>
                )}
                {importForm.errors.file && (
                    <p className="st-field__error">{importForm.errors.file}</p>
                )}

                {imports.map((imp) => (
                    <div
                        key={imp.id}
                        className="rounded-[16px] border-2 border-ink p-4"
                    >
                        <div className="flex flex-wrap items-center gap-2">
                            <p className="min-w-0 flex-1 font-extrabold break-words">
                                {imp.original_filename}
                            </p>
                            <span className="st-badge st-badge--type">
                                {imp.status}
                            </span>
                            {imp.status !== 'applying' && (
                                <button
                                    type="button"
                                    className="st-icon-btn"
                                    aria-label="Remove this import"
                                    onClick={() =>
                                        router.delete(`/imports/${imp.id}`, {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    <Trash2 aria-hidden="true" />
                                </button>
                            )}
                        </div>
                        <p className="st-meta">
                            <TimeAgo iso={imp.created_at} /> ·{' '}
                            {formatBytes(imp.size_bytes)}
                        </p>
                        {imp.error && (
                            <div
                                className="st-alert st-alert--error mt-3"
                                role="alert"
                            >
                                <CircleAlert
                                    className="st-alert__icon"
                                    aria-hidden="true"
                                />
                                <div className="st-alert__content">
                                    <p className="st-alert__body">
                                        {imp.error}
                                    </p>
                                </div>
                            </div>
                        )}
                        {imp.preview && imp.status === 'ready' && (
                            <div className="mt-3 flex flex-col gap-3 text-sm">
                                <p>
                                    <strong>
                                        {imp.preview.counts.valid_resources}
                                    </strong>{' '}
                                    of {imp.preview.counts.resources} resources
                                    valid · {imp.preview.counts.projects}{' '}
                                    projects · {imp.preview.counts.tags} tags ·{' '}
                                    {imp.preview.counts.attachments} attachments
                                </p>
                                <ul className="list-disc pl-5">
                                    <li>
                                        {
                                            imp.preview.collisions
                                                .resources_same_owner
                                        }{' '}
                                        already exist in your library (same id)
                                    </li>
                                    <li>
                                        {
                                            imp.preview.collisions
                                                .projects_existing
                                        }{' '}
                                        projects and{' '}
                                        {imp.preview.collisions.tags_existing}{' '}
                                        tags will be reused
                                    </li>
                                    {imp.preview.missing_attachments > 0 && (
                                        <li>
                                            {imp.preview.missing_attachments}{' '}
                                            attachments are missing
                                        </li>
                                    )}
                                    {imp.preview.error_count > 0 && (
                                        <li className="text-[#c42b1c]">
                                            {imp.preview.error_count} invalid
                                            entries will be skipped
                                        </li>
                                    )}
                                </ul>
                                {imp.preview.duplicates.length > 0 && (
                                    <details>
                                        <summary className="cursor-pointer font-bold">
                                            {imp.preview.duplicates.length}{' '}
                                            possible duplicates (by URL or
                                            image)
                                        </summary>
                                        <ul className="mt-1 list-disc pl-5">
                                            {imp.preview.duplicates
                                                .slice(0, 20)
                                                .map((d) => (
                                                    <li
                                                        key={d.import_id}
                                                        className="break-words"
                                                    >
                                                        “{d.title}” matches “
                                                        {d.existing_title}”
                                                    </li>
                                                ))}
                                        </ul>
                                    </details>
                                )}
                                <fieldset className="flex flex-col gap-1">
                                    <legend className="st-field__label mb-1">
                                        If an item’s id already exists
                                    </legend>
                                    {[
                                        [
                                            'skip',
                                            'Skip it (recommended — safe to re-run)',
                                        ],
                                        [
                                            'duplicate',
                                            'Import a copy with a new id',
                                        ],
                                    ].map(([value, label]) => (
                                        <label
                                            key={value}
                                            className="st-check !min-h-[36px]"
                                        >
                                            <input
                                                type="radio"
                                                className="st-radio"
                                                name={`strategy-${imp.id}`}
                                                checked={
                                                    (strategy[imp.id] ??
                                                        'skip') === value
                                                }
                                                onChange={() =>
                                                    setStrategy((s) => ({
                                                        ...s,
                                                        [imp.id]: value,
                                                    }))
                                                }
                                            />
                                            {label}
                                        </label>
                                    ))}
                                </fieldset>
                                <div>
                                    <button
                                        type="button"
                                        className="st-btn st-btn--primary st-btn--sm"
                                        onClick={() =>
                                            router.post(
                                                `/imports/${imp.id}/apply`,
                                                {
                                                    collision_strategy:
                                                        strategy[imp.id] ??
                                                        'skip',
                                                },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Import now
                                    </button>
                                </div>
                            </div>
                        )}
                        {imp.report && (
                            <div className="mt-3 text-sm">
                                <p>
                                    Created {imp.report.created.resources}{' '}
                                    resources, {imp.report.created.projects}{' '}
                                    projects, {imp.report.created.tags} tags,{' '}
                                    {imp.report.created.files} files. Skipped{' '}
                                    {imp.report.skipped.length}. Errors{' '}
                                    {imp.report.errors.length}.
                                </p>
                                {imp.report.errors.length > 0 && (
                                    <details className="mt-1">
                                        <summary className="cursor-pointer font-bold">
                                            Show errors
                                        </summary>
                                        <ul className="mt-1 list-disc pl-5">
                                            {imp.report.errors
                                                .slice(0, 50)
                                                .map((err, i) => (
                                                    <li
                                                        key={i}
                                                        className="break-words"
                                                    >
                                                        {err.message}
                                                    </li>
                                                ))}
                                        </ul>
                                    </details>
                                )}
                            </div>
                        )}
                    </div>
                ))}
            </form>
        </>
    );
}
