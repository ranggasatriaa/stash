import { Link, router, usePage } from '@inertiajs/react';
import {
    CircleAlert,
    CloudUpload,
    FileText,
    ImageUp,
    Link2,
    TriangleAlert,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { DragEvent, FormEvent } from 'react';
import { StashDialog } from '@/components/stash/overlay';
import { useSave } from '@/components/stash/save-context';
import { ProjectSelect, TagInput } from '@/components/stash/tag-input';
import { formatBytes, relativeTime } from '@/lib/format';
import { fetchJson, newIdempotencyKey, sha256Hex } from '@/lib/http';
import { cn } from '@/lib/utils';
import type { Duplicate } from '@/types/stash';

const ACCEPTED_IMAGES = ['image/jpeg', 'image/png', 'image/webp'];
const ACCEPTED_VIDEOS = ['video/mp4', 'video/webm', 'video/quicktime'];
const ACCEPTED = [...ACCEPTED_IMAGES, ...ACCEPTED_VIDEOS];
/** Files above this size skip the in-browser duplicate hash. */
const HASH_LIMIT = 64 * 1024 * 1024;

/** Grab a frame near the start of a video as a JPEG poster, or null. */
function capturePoster(file: File): Promise<File | null> {
    return new Promise((resolve) => {
        const url = URL.createObjectURL(file);
        const video = document.createElement('video');
        let settled = false;
        const finish = (poster: File | null) => {
            if (settled) return;
            settled = true;
            URL.revokeObjectURL(url);
            resolve(poster);
        };
        const timer = window.setTimeout(() => finish(null), 8000);
        video.muted = true;
        video.playsInline = true;
        video.preload = 'auto';
        video.onerror = () => finish(null);
        video.onloadedmetadata = () => {
            const d = Number.isFinite(video.duration) ? video.duration : 0;
            video.currentTime = d > 0 ? Math.min(1, d * 0.1) : 0;
        };
        video.onseeked = () => {
            window.clearTimeout(timer);
            const w = video.videoWidth;
            const h = video.videoHeight;
            if (!w || !h) return finish(null);
            const scale = Math.min(1, 1280 / w);
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(w * scale);
            canvas.height = Math.round(h * scale);
            const ctx = canvas.getContext('2d');
            if (!ctx) return finish(null);
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            canvas.toBlob(
                (blob) =>
                    finish(
                        blob
                            ? new File([blob], 'poster.jpg', {
                                  type: 'image/jpeg',
                              })
                            : null,
                    ),
                'image/jpeg',
                0.85,
            );
        };
        video.src = url;
    });
}

type Errors = Partial<Record<string, string>>;

/** Global "Save a resource" dialog (Link / Upload image or video). */
export function SaveDialog() {
    const {
        open,
        setOpen,
        mode: initialMode,
        initialFiles,
        prefill,
    } = useSave();
    const page = usePage();
    const maxImageBytes =
        page.props.limits?.upload_max_bytes ?? 10 * 1024 * 1024;
    const maxVideoBytes =
        page.props.limits?.upload_max_video_bytes ?? 200 * 1024 * 1024;
    const scope = page.props.scope as
        | { type?: string; id?: string; name?: string }
        | null
        | undefined;

    const [mode, setMode] = useState<'link' | 'upload'>(initialMode);
    const [url, setUrl] = useState('');
    const [type, setType] = useState<'link' | 'article'>('link');
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [tags, setTags] = useState<string[]>([]);
    const [projectId, setProjectId] = useState<string | null>(null);
    const [readLater, setReadLater] = useState(false);
    const [readingStatus, setReadingStatus] = useState<'unread' | 'read'>(
        'unread',
    );
    const [file, setFile] = useState<File | null>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [poster, setPoster] = useState<File | null>(null);
    const [fileError, setFileError] = useState<string | null>(null);
    const [dragging, setDragging] = useState(false);
    const [duplicates, setDuplicates] = useState<Duplicate[]>([]);
    const [errors, setErrors] = useState<Errors>({});
    const [progress, setProgress] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);
    const idempotencyKey = useRef(newIdempotencyKey());
    const fileInput = useRef<HTMLInputElement>(null);
    const dupAbort = useRef<AbortController | null>(null);

    // Reset whenever the dialog opens.
    useEffect(() => {
        if (!open) return;
        setMode(initialMode);
        setUrl(prefill.url ?? '');
        setType('link');
        setTitle(prefill.title ?? '');
        setDescription(prefill.description ?? '');
        setTags(scope?.type === 'tag' && scope.name ? [scope.name] : []);
        setProjectId(scope?.type === 'project' && scope.id ? scope.id : null);
        setReadLater(false);
        setReadingStatus('unread');
        setFile(null);
        setFileError(null);
        setDuplicates([]);
        setErrors({});
        setProgress(null);
        setBusy(false);
        idempotencyKey.current = newIdempotencyKey();
        if (initialFiles[0]) {
            void chooseFile(initialFiles[0]);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    useEffect(() => {
        if (!file) {
            setPreview(null);

            return;
        }
        const objectUrl = URL.createObjectURL(file);
        setPreview(objectUrl);

        return () => URL.revokeObjectURL(objectUrl);
    }, [file]);

    // Debounced duplicate check for URLs; stale requests are aborted.
    useEffect(() => {
        if (mode !== 'link') return;
        dupAbort.current?.abort();
        if (!/^https?:\/\/\S+\.\S+/i.test(url.trim())) {
            setDuplicates([]);

            return;
        }
        const controller = new AbortController();
        dupAbort.current = controller;
        const timer = window.setTimeout(() => {
            fetchJson<{ duplicates: Duplicate[] }>('/resources/duplicates', {
                body: { url: url.trim() },
                signal: controller.signal,
            })
                .then((r) => setDuplicates(r.duplicates))
                .catch(() => undefined);
        }, 400);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [url, mode]);

    const chooseFile = async (f: File) => {
        setErrors({});
        setDuplicates([]);
        const isVideo = ACCEPTED_VIDEOS.includes(f.type);
        const maxBytes = isVideo ? maxVideoBytes : maxImageBytes;
        if (!ACCEPTED.includes(f.type)) {
            setFile(null);
            setPoster(null);
            setFileError(
                `“${f.name}” is not supported. Use JPG, PNG or WebP images, or MP4, WebM or MOV videos.`,
            );

            return;
        }
        if (f.size > maxBytes) {
            setFile(null);
            setFileError(
                `“${f.name}” is ${formatBytes(f.size)}. The limit is ${formatBytes(maxBytes)}.`,
            );

            return;
        }
        setFileError(null);
        setFile(f);
        setPoster(null);
        if (isVideo) {
            void capturePoster(f).then(setPoster);
        }
        const hash = f.size > HASH_LIMIT ? null : await sha256Hex(f);
        if (hash) {
            fetchJson<{ duplicates: Duplicate[] }>('/resources/duplicates', {
                body: { sha256: hash },
            })
                .then((r) => setDuplicates(r.duplicates))
                .catch(() => undefined);
        }
    };

    const onDrop = (e: DragEvent) => {
        e.preventDefault();
        setDragging(false);
        const f = e.dataTransfer.files?.[0];
        if (f) void chooseFile(f);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (busy) return;
        const local: Errors = {};
        if (mode === 'link' && !/^https?:\/\//i.test(url.trim())) {
            local.url = 'Enter a full URL starting with http:// or https://';
        }
        if (mode === 'upload' && !file) {
            local.file = fileError ?? 'Choose an image or video to upload.';
        }
        setErrors(local);
        if (Object.keys(local).length) return;

        const common = {
            title: title.trim() || null,
            description: description.trim() || null,
            tags,
            project_id: projectId,
        };
        const data =
            mode === 'link'
                ? {
                      ...common,
                      url: url.trim(),
                      type,
                      ...(type === 'article'
                          ? {
                                read_later: readLater,
                                reading_status: readingStatus,
                            }
                          : {}),
                  }
                : { ...common, file, poster, url: url.trim() || null };

        setBusy(true);
        router.post('/resources', data as never, {
            forceFormData: mode === 'upload',
            preserveScroll: true,
            preserveState: true,
            headers: { 'Idempotency-Key': idempotencyKey.current },
            onProgress: (p) => setProgress(p?.percentage ?? null),
            onSuccess: () => {
                setOpen(false);
            },
            onError: (errs) => {
                setErrors(errs as Errors);
                idempotencyKey.current = newIdempotencyKey();
            },
            onFinish: () => {
                setBusy(false);
                setProgress(null);
            },
        });
    };

    const tabs = useMemo(
        () => [
            { key: 'link' as const, label: 'Link', Icon: Link2 },
            { key: 'upload' as const, label: 'Upload', Icon: ImageUp },
        ],
        [],
    );

    const generalError = errors._error;

    return (
        <StashDialog
            open={open}
            onOpenChange={(o) => !busy && setOpen(o)}
            title="Save a resource"
            onOpenAutoFocus={(e) => {
                e.preventDefault();
                window.setTimeout(
                    () =>
                        document
                            .getElementById(
                                mode === 'link' ? 'save-url' : 'save-dropzone',
                            )
                            ?.focus(),
                    30,
                );
            }}
        >
            <form
                id="save-form"
                onSubmit={submit}
                noValidate
                className="flex flex-col gap-4"
            >
                <div
                    className="st-tabs st-tabs--fill"
                    role="tablist"
                    aria-label="What to save"
                >
                    {tabs.map(({ key, label, Icon }) => (
                        <button
                            key={key}
                            type="button"
                            role="tab"
                            aria-selected={mode === key}
                            className="st-tab"
                            onClick={() => {
                                setMode(key);
                                setErrors({});
                                setDuplicates([]);
                            }}
                        >
                            <Icon aria-hidden="true" />
                            {label}
                        </button>
                    ))}
                </div>

                {generalError && (
                    <div className="st-alert st-alert--error" role="alert">
                        <CircleAlert
                            className="st-alert__icon"
                            aria-hidden="true"
                        />
                        <div className="st-alert__content">
                            <p className="st-alert__body">{generalError}</p>
                        </div>
                    </div>
                )}

                {mode === 'upload' && (
                    <div className="st-field">
                        <span className="st-field__label" id="save-file-label">
                            Image or video
                        </span>
                        {file && preview ? (
                            <div className="flex items-center gap-3 rounded-[12px] border-2 border-ink bg-white p-2">
                                {file.type.startsWith('video/') ? (
                                    <video
                                        src={preview}
                                        muted
                                        playsInline
                                        preload="metadata"
                                        className="size-20 shrink-0 rounded-[8px] border-2 border-ink bg-ink object-cover"
                                    />
                                ) : (
                                    <img
                                        src={preview}
                                        alt=""
                                        className="size-20 shrink-0 rounded-[8px] border-2 border-ink object-cover"
                                    />
                                )}
                                <div className="min-w-0 flex-1">
                                    <p className="truncate font-bold">
                                        {file.name}
                                    </p>
                                    <p className="st-meta">
                                        {formatBytes(file.size)}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    className="st-icon-btn"
                                    aria-label="Remove selected file"
                                    onClick={() => {
                                        setFile(null);
                                        setPoster(null);
                                    }}
                                >
                                    <X aria-hidden="true" />
                                </button>
                            </div>
                        ) : (
                            <div
                                id="save-dropzone"
                                role="button"
                                tabIndex={0}
                                aria-labelledby="save-file-label"
                                aria-describedby="save-file-hint"
                                className={cn(
                                    'st-dropzone',
                                    dragging && 'st-dropzone--active',
                                    (fileError || errors.file) &&
                                        'st-dropzone--invalid',
                                )}
                                onClick={() => fileInput.current?.click()}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' || e.key === ' ') {
                                        e.preventDefault();
                                        fileInput.current?.click();
                                    }
                                }}
                                onDragOver={(e) => {
                                    e.preventDefault();
                                    setDragging(true);
                                }}
                                onDragLeave={() => setDragging(false)}
                                onDrop={onDrop}
                            >
                                {fileError || errors.file ? (
                                    <CircleAlert aria-hidden="true" />
                                ) : (
                                    <CloudUpload aria-hidden="true" />
                                )}
                                <span className="st-dropzone__title">
                                    {dragging
                                        ? 'Drop to upload'
                                        : fileError || errors.file
                                          ? 'Invalid file'
                                          : 'Drop an image or video here'}
                                </span>
                                <span
                                    className="st-dropzone__hint"
                                    id="save-file-hint"
                                >
                                    or click to browse · JPG, PNG, WebP up to{' '}
                                    {formatBytes(maxImageBytes)} · MP4, WebM,
                                    MOV up to {formatBytes(maxVideoBytes)}
                                </span>
                            </div>
                        )}
                        <input
                            ref={fileInput}
                            type="file"
                            accept={ACCEPTED.join(',')}
                            className="sr-only"
                            tabIndex={-1}
                            aria-hidden="true"
                            onChange={(e) => {
                                const f = e.target.files?.[0];
                                if (f) void chooseFile(f);
                                e.target.value = '';
                            }}
                        />
                        {(fileError || errors.file) && (
                            <p className="st-field__error" role="alert">
                                {fileError ?? errors.file}
                            </p>
                        )}
                    </div>
                )}

                <div className="st-field">
                    <label htmlFor="save-url" className="st-field__label">
                        {mode === 'link'
                            ? 'URL'
                            : 'Source URL (optional attribution)'}
                    </label>
                    <input
                        id="save-url"
                        type="url"
                        inputMode="url"
                        className="st-input"
                        placeholder="https://"
                        value={url}
                        aria-invalid={errors.url ? true : undefined}
                        aria-describedby={
                            errors.url ? 'save-url-error' : undefined
                        }
                        onChange={(e) => setUrl(e.target.value)}
                        required={mode === 'link'}
                        autoComplete="off"
                    />
                    {errors.url && (
                        <p id="save-url-error" className="st-field__error">
                            {errors.url}
                        </p>
                    )}
                </div>

                {duplicates.length > 0 && (
                    <div className="st-alert st-alert--warning" role="status">
                        <TriangleAlert
                            className="st-alert__icon"
                            aria-hidden="true"
                        />
                        <div className="st-alert__content">
                            <p className="st-alert__title">
                                You already saved this
                            </p>
                            <ul className="st-alert__body mt-1 space-y-1">
                                {duplicates.slice(0, 3).map((d) => (
                                    <li key={d.id} className="break-words">
                                        <Link
                                            href={`/library?resource=${d.id}`}
                                            className="st-link"
                                            onClick={() => setOpen(false)}
                                        >
                                            {d.title}
                                        </Link>{' '}
                                        · {relativeTime(d.created_at)}
                                        {d.archived ? ' · archived' : ''}
                                        {d.trashed ? ' · in Trash' : ''}
                                    </li>
                                ))}
                            </ul>
                            <p className="st-alert__body mt-1">
                                Duplicates are allowed — use “Save anyway” to
                                keep both.
                            </p>
                        </div>
                    </div>
                )}

                {mode === 'link' && (
                    <fieldset className="st-field">
                        <legend className="st-field__label mb-1.5">Type</legend>
                        <div
                            className="st-segmented"
                            role="radiogroup"
                            aria-label="Resource type"
                        >
                            {[
                                {
                                    key: 'link' as const,
                                    label: 'Link',
                                    Icon: Link2,
                                },
                                {
                                    key: 'article' as const,
                                    label: 'Article',
                                    Icon: FileText,
                                },
                            ].map(({ key, label, Icon }) => (
                                <button
                                    key={key}
                                    type="button"
                                    role="radio"
                                    aria-checked={type === key}
                                    className="st-segmented__item"
                                    onClick={() => setType(key)}
                                >
                                    <Icon aria-hidden="true" />
                                    {label}
                                </button>
                            ))}
                        </div>
                    </fieldset>
                )}

                <div className="st-field">
                    <label htmlFor="save-title" className="st-field__label">
                        Title
                    </label>
                    <input
                        id="save-title"
                        className="st-input"
                        placeholder={
                            mode === 'link'
                                ? 'Optional — fetched from the page'
                                : 'Optional — uses the file name'
                        }
                        value={title}
                        maxLength={500}
                        aria-invalid={errors.title ? true : undefined}
                        onChange={(e) => setTitle(e.target.value)}
                    />
                    {errors.title && (
                        <p className="st-field__error">{errors.title}</p>
                    )}
                </div>

                <div className="st-field">
                    <label
                        htmlFor="save-description"
                        className="st-field__label"
                    >
                        Description
                    </label>
                    <textarea
                        id="save-description"
                        className="st-textarea !min-h-[76px]"
                        placeholder="A short note about this resource (optional)…"
                        value={description}
                        maxLength={5000}
                        onChange={(e) => setDescription(e.target.value)}
                    />
                </div>

                <TagInput
                    value={tags}
                    onChange={setTags}
                    id="save-tags"
                    invalid={Boolean(errors.tags)}
                />
                <ProjectSelect
                    value={projectId}
                    onChange={setProjectId}
                    id="save-project"
                    error={errors.project_id}
                />

                {mode === 'link' && type === 'article' && (
                    <div className="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-[12px] border-2 border-dashed border-[#d9d0c2] p-3">
                        <label className="st-check">
                            <input
                                type="checkbox"
                                role="switch"
                                className="st-switch"
                                checked={readLater}
                                onChange={(e) => setReadLater(e.target.checked)}
                            />
                            Add to Read later
                        </label>
                        <label className="st-check">
                            <input
                                type="checkbox"
                                className="st-checkbox"
                                checked={readingStatus === 'read'}
                                onChange={(e) =>
                                    setReadingStatus(
                                        e.target.checked ? 'read' : 'unread',
                                    )
                                }
                            />
                            Already read
                        </label>
                    </div>
                )}

                {progress !== null && (
                    <div aria-live="polite">
                        <div className="mb-1 flex justify-between text-sm font-bold">
                            <span>Uploading…</span>
                            <span>{progress}%</span>
                        </div>
                        <div
                            className="st-progress"
                            role="progressbar"
                            aria-valuenow={progress}
                            aria-valuemin={0}
                            aria-valuemax={100}
                            aria-label="Upload progress"
                        >
                            <div
                                className="st-progress__bar"
                                style={{ width: `${progress}%` }}
                            />
                        </div>
                    </div>
                )}

                <div className="flex flex-wrap gap-3 pt-1">
                    <button
                        type="button"
                        className="st-btn st-btn--secondary flex-1"
                        onClick={() => setOpen(false)}
                        disabled={busy}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        className="st-btn st-btn--primary flex-1"
                        aria-busy={busy || undefined}
                        disabled={busy}
                    >
                        {busy
                            ? 'Saving…'
                            : duplicates.length
                              ? 'Save anyway'
                              : 'Save'}
                    </button>
                </div>
            </form>
        </StashDialog>
    );
}
