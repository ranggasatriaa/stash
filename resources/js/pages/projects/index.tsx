import { Head, Link, router, useForm } from '@inertiajs/react';
import { Folder, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { ConfirmDialog, StashDialog } from '@/components/stash/overlay';
import { pluralize } from '@/lib/format';
import type { NavProject } from '@/types/stash';

const COLORS = [
    '#FF7417',
    '#FFD83D',
    '#3A7BD5',
    '#2E8B57',
    '#9B5DE5',
    '#E0493A',
    '#62656D',
];

export default function ProjectsIndex({
    projects,
}: {
    projects: NavProject[];
}) {
    const [editing, setEditing] = useState<NavProject | 'new' | null>(null);
    const [deleting, setDeleting] = useState<NavProject | null>(null);
    const form = useForm({
        name: '',
        description: '',
        color: COLORS[0] as string | null,
    });

    const openEditor = (p: NavProject | 'new') => {
        setEditing(p);
        form.clearErrors();
        form.setData(
            p === 'new'
                ? { name: '', description: '', color: COLORS[0] }
                : {
                      name: p.name,
                      description: p.description ?? '',
                      color: p.color,
                  },
        );
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const opts = {
            preserveScroll: true,
            onSuccess: () => setEditing(null),
        };
        if (editing === 'new') {
            form.post('/projects', opts);
        } else if (editing) {
            form.patch(`/projects/${editing.id}`, opts);
        }
    };

    return (
        <div className="mx-auto flex max-w-[1100px] flex-col gap-5">
            <Head title="Projects" />
            <header className="flex flex-wrap items-center gap-3">
                <div className="st-section-title mr-auto">
                    <h1 className="st-h2">Projects</h1>
                    <span className="st-section-title__count">
                        {projects.length}
                    </span>
                </div>
                <button
                    type="button"
                    className="st-btn st-btn--primary"
                    onClick={() => openEditor('new')}
                >
                    <Plus aria-hidden="true" />
                    New project
                </button>
            </header>

            {projects.length === 0 ? (
                <div className="st-panel st-panel--flat">
                    <div className="st-empty">
                        <Folder className="st-empty__icon" aria-hidden="true" />
                        <h2 className="st-empty__title">No projects yet</h2>
                        <p className="st-empty__body">
                            Group resources for a piece of work. Each resource
                            can belong to one project.
                        </p>
                        <button
                            type="button"
                            className="st-btn st-btn--primary"
                            onClick={() => openEditor('new')}
                        >
                            <Plus aria-hidden="true" />
                            New project
                        </button>
                    </div>
                </div>
            ) : (
                <ul className="st-grid">
                    {projects.map((p) => (
                        <li key={p.id} className="st-card">
                            <div
                                className="h-3 border-b-2 border-ink"
                                style={{ background: p.color ?? '#FFF4C2' }}
                                aria-hidden="true"
                            />
                            <div className="st-card__body">
                                <h2 className="st-card__title">
                                    <Link
                                        href={`/projects/${p.id}`}
                                        className="hover:underline"
                                    >
                                        {p.name}
                                    </Link>
                                </h2>
                                {p.description && (
                                    <p className="st-card__desc st-clamp-3">
                                        {p.description}
                                    </p>
                                )}
                                <p className="st-meta">
                                    {pluralize(p.count, 'active resource')}
                                    {p.total !== p.count
                                        ? ` · ${p.total} incl. archive/trash`
                                        : ''}
                                </p>
                                <div className="mt-auto flex gap-2 pt-2">
                                    <button
                                        type="button"
                                        className="st-btn st-btn--secondary st-btn--sm"
                                        onClick={() => openEditor(p)}
                                    >
                                        <Pencil aria-hidden="true" />
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        className="st-btn st-btn--danger st-btn--sm"
                                        onClick={() => setDeleting(p)}
                                    >
                                        <Trash2 aria-hidden="true" />
                                        Delete
                                    </button>
                                </div>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <StashDialog
                open={editing !== null}
                onOpenChange={(o) => !o && setEditing(null)}
                title={editing === 'new' ? 'New project' : 'Edit project'}
                footer={
                    <>
                        <button
                            type="button"
                            className="st-btn st-btn--secondary"
                            onClick={() => setEditing(null)}
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            form="project-form"
                            className="st-btn st-btn--primary"
                            disabled={form.processing}
                            aria-busy={form.processing || undefined}
                        >
                            Save
                        </button>
                    </>
                }
            >
                <form
                    id="project-form"
                    onSubmit={submit}
                    className="flex flex-col gap-4"
                >
                    <div className="st-field">
                        <label
                            htmlFor="project-name"
                            className="st-field__label"
                        >
                            Name
                        </label>
                        <input
                            id="project-name"
                            className="st-input"
                            value={form.data.name}
                            maxLength={120}
                            required
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
                            htmlFor="project-description"
                            className="st-field__label"
                        >
                            Description
                        </label>
                        <textarea
                            id="project-description"
                            className="st-textarea !min-h-[80px]"
                            value={form.data.description}
                            maxLength={2000}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                        />
                    </div>
                    <fieldset>
                        <legend className="st-field__label mb-2">Color</legend>
                        <div className="flex flex-wrap gap-2">
                            {COLORS.map((c) => (
                                <label key={c} className="cursor-pointer">
                                    <input
                                        type="radio"
                                        name="project-color"
                                        className="peer sr-only"
                                        checked={form.data.color === c}
                                        onChange={() =>
                                            form.setData('color', c)
                                        }
                                    />
                                    <span
                                        className="block size-9 rounded-[10px] border-2 border-ink peer-checked:shadow-[3px_3px_0_#191A1D] peer-checked:outline-3 peer-checked:outline-offset-2 peer-checked:outline-ink peer-focus-visible:outline-[#E35D00]"
                                        style={{ background: c }}
                                    >
                                        <span className="sr-only">{c}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                    </fieldset>
                </form>
            </StashDialog>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(o) => !o && setDeleting(null)}
                title="Delete project?"
                destructive
                confirmLabel="Delete project"
                body={
                    <>
                        <strong className="break-words">
                            {deleting?.name}
                        </strong>{' '}
                        will be deleted. Its{' '}
                        {pluralize(deleting?.total ?? 0, 'resource')} will be{' '}
                        <strong>kept</strong> and become unassigned.
                    </>
                }
                onConfirm={() =>
                    deleting &&
                    router.delete(`/projects/${deleting.id}`, {
                        preserveScroll: true,
                        onSuccess: () => setDeleting(null),
                    })
                }
            />
        </div>
    );
}
