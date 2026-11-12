import { Head, Link, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Search, Tag, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { ConfirmDialog, StashDialog } from '@/components/stash/overlay';
import { pluralize } from '@/lib/format';
import type { NavTag } from '@/types/stash';

export default function TagsIndex({ tags }: { tags: NavTag[] }) {
    const [editing, setEditing] = useState<NavTag | 'new' | null>(null);
    const [deleting, setDeleting] = useState<NavTag | null>(null);
    const [query, setQuery] = useState('');
    const form = useForm({ name: '' });

    const visible = tags.filter((t) =>
        t.name.toLowerCase().includes(query.trim().toLowerCase()),
    );

    const openEditor = (t: NavTag | 'new') => {
        setEditing(t);
        form.clearErrors();
        form.setData({ name: t === 'new' ? '' : t.name });
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const opts = {
            preserveScroll: true,
            onSuccess: () => setEditing(null),
        };
        if (editing === 'new') form.post('/tags', opts);
        else if (editing) form.patch(`/tags/${editing.id}`, opts);
    };

    return (
        <div className="mx-auto flex max-w-[1100px] flex-col gap-5">
            <Head title="Tags" />
            <header className="flex flex-wrap items-center gap-3">
                <div className="st-section-title mr-auto">
                    <h1 className="st-h2">Tags</h1>
                    <span className="st-section-title__count">
                        {tags.length}
                    </span>
                </div>
                <button
                    type="button"
                    className="st-btn st-btn--primary"
                    onClick={() => openEditor('new')}
                >
                    <Plus aria-hidden="true" />
                    New tag
                </button>
            </header>

            {tags.length > 0 && (
                <div className="st-search max-w-md">
                    <Search className="st-search__icon" aria-hidden="true" />
                    <label htmlFor="tag-search" className="sr-only">
                        Find a tag
                    </label>
                    <input
                        id="tag-search"
                        type="search"
                        className="st-input"
                        placeholder="Find a tag…"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                    />
                </div>
            )}

            {tags.length === 0 ? (
                <div className="st-panel st-panel--flat">
                    <div className="st-empty">
                        <Tag className="st-empty__icon" aria-hidden="true" />
                        <h2 className="st-empty__title">No tags yet</h2>
                        <p className="st-empty__body">
                            Tags are created when you add them to a resource, or
                            here.
                        </p>
                    </div>
                </div>
            ) : (
                <div className="st-table-wrap">
                    <table className="st-table">
                        <caption className="sr-only">
                            Tags with usage counts
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">Tag</th>
                                <th scope="col">Active</th>
                                <th
                                    scope="col"
                                    className="hidden sm:table-cell"
                                >
                                    All (incl. archive/trash)
                                </th>
                                <th scope="col">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {visible.map((t, i) => (
                                <tr key={t.id}>
                                    <td>
                                        <Link
                                            href={`/tags/${t.id}`}
                                            className={`st-tag ${i % 2 ? 'st-tag--orange' : ''}`}
                                        >
                                            <span>{t.name}</span>
                                        </Link>
                                    </td>
                                    <td className="font-bold tabular-nums">
                                        {t.count}
                                    </td>
                                    <td className="hidden tabular-nums sm:table-cell">
                                        {t.total}
                                    </td>
                                    <td className="text-right whitespace-nowrap">
                                        <button
                                            type="button"
                                            className="st-icon-btn"
                                            aria-label={`Rename ${t.name}`}
                                            onClick={() => openEditor(t)}
                                        >
                                            <Pencil aria-hidden="true" />
                                        </button>
                                        <button
                                            type="button"
                                            className="st-icon-btn text-[#c42b1c]"
                                            aria-label={`Delete ${t.name}`}
                                            onClick={() => setDeleting(t)}
                                        >
                                            <Trash2 aria-hidden="true" />
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <StashDialog
                open={editing !== null}
                onOpenChange={(o) => !o && setEditing(null)}
                title={editing === 'new' ? 'New tag' : 'Rename tag'}
                size="sm"
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
                            form="tag-form"
                            className="st-btn st-btn--primary"
                            disabled={form.processing}
                        >
                            Save
                        </button>
                    </>
                }
            >
                <form id="tag-form" onSubmit={submit} className="st-field">
                    <label htmlFor="tag-name" className="st-field__label">
                        Name
                    </label>
                    <input
                        id="tag-name"
                        className="st-input"
                        value={form.data.name}
                        maxLength={64}
                        required
                        onChange={(e) => form.setData('name', e.target.value)}
                        aria-invalid={form.errors.name ? true : undefined}
                    />
                    {form.errors.name && (
                        <p className="st-field__error">{form.errors.name}</p>
                    )}
                    <p className="st-field__hint">
                        Names are unique ignoring case and extra spaces. Unicode
                        is fine.
                    </p>
                </form>
            </StashDialog>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(o) => !o && setDeleting(null)}
                title="Delete tag?"
                destructive
                confirmLabel="Delete tag"
                body={
                    <>
                        The tag{' '}
                        <strong className="break-words">
                            {deleting?.name}
                        </strong>{' '}
                        will be removed from{' '}
                        {pluralize(deleting?.total ?? 0, 'resource')}. The
                        resources themselves are <strong>kept</strong>.
                    </>
                }
                onConfirm={() =>
                    deleting &&
                    router.delete(`/tags/${deleting.id}`, {
                        preserveScroll: true,
                        onSuccess: () => setDeleting(null),
                    })
                }
            />
        </div>
    );
}
