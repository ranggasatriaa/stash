import { router, usePage } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    BookOpenCheck,
    CircleDot,
    Folder,
    RotateCcw,
    Tag,
    Tags,
    Trash2,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog, StashDialog } from '@/components/stash/overlay';
import { ProjectSelect, TagInput } from '@/components/stash/tag-input';
import type { SectionKey, StashResource } from '@/types/stash';

/**
 * Sticky bulk-action bar. The server validates ownership for every id and
 * reports skipped (ineligible) items; the toast offers Undo when reliable.
 */
export function BulkBar({
    section,
    selected,
    resources,
    onClear,
}: {
    section: SectionKey;
    selected: string[];
    resources: StashResource[];
    onClear: () => void;
}) {
    const [dialog, setDialog] = useState<
        null | 'add-tags' | 'remove-tags' | 'project' | 'delete'
    >(null);
    const [tags, setTags] = useState<string[]>([]);
    const [removeIds, setRemoveIds] = useState<string[]>([]);
    const [projectId, setProjectId] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const known = usePage().props.nav?.tags ?? [];

    const run = (operation: string, extra: Record<string, unknown> = {}) => {
        setBusy(true);
        router.post(
            '/resources/bulk',
            { operation, ids: selected, ...extra } as never,
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setDialog(null);
                    onClear();
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    const selectedTagIds = new Set(
        resources
            .filter((r) => selected.includes(r.id))
            .flatMap((r) => r.tags.map((t) => t.id)),
    );
    const removable = known.filter((t) => selectedTagIds.has(t.id));
    const trash = section === 'trash';

    return (
        <>
            <div
                className="fixed inset-x-2 bottom-[calc(84px+env(safe-area-inset-bottom))] z-30 lg:sticky lg:inset-x-auto lg:bottom-4 lg:mt-4"
                role="region"
                aria-label="Bulk actions"
            >
                <div className="st-panel flex flex-wrap items-center gap-2 !bg-ink p-2 pl-4 text-white">
                    <p
                        className="mr-auto text-sm font-extrabold"
                        aria-live="polite"
                    >
                        {selected.length} selected
                    </p>
                    {trash ? (
                        <>
                            <button
                                type="button"
                                className="st-btn st-btn--secondary st-btn--sm"
                                onClick={() => run('restore')}
                                disabled={busy}
                            >
                                <RotateCcw aria-hidden="true" />
                                Restore
                            </button>
                            <button
                                type="button"
                                className="st-btn st-btn--danger-solid st-btn--sm"
                                onClick={() => setDialog('delete')}
                                disabled={busy}
                            >
                                <Trash2 aria-hidden="true" />
                                Delete permanently
                            </button>
                        </>
                    ) : (
                        <>
                            <button
                                type="button"
                                className="st-btn st-btn--secondary st-btn--sm"
                                onClick={() => {
                                    setTags([]);
                                    setDialog('add-tags');
                                }}
                                disabled={busy}
                            >
                                <Tag aria-hidden="true" />
                                <span className="hidden sm:inline">
                                    Add tags
                                </span>
                                <span className="sm:hidden">Tag</span>
                            </button>
                            {removable.length > 0 && (
                                <button
                                    type="button"
                                    className="st-btn st-btn--secondary st-btn--sm"
                                    onClick={() => {
                                        setRemoveIds([]);
                                        setDialog('remove-tags');
                                    }}
                                    disabled={busy}
                                >
                                    <Tags aria-hidden="true" />
                                    <span className="hidden sm:inline">
                                        Remove tags
                                    </span>
                                    <span className="sm:hidden">Untag</span>
                                </button>
                            )}
                            <button
                                type="button"
                                className="st-btn st-btn--secondary st-btn--sm"
                                onClick={() => {
                                    setProjectId(null);
                                    setDialog('project');
                                }}
                                disabled={busy}
                            >
                                <Folder aria-hidden="true" />
                                Project
                            </button>
                            <button
                                type="button"
                                className="st-btn st-btn--secondary st-btn--sm"
                                onClick={() => run('mark_read')}
                                disabled={busy}
                                title="Applies to articles"
                            >
                                <BookOpenCheck aria-hidden="true" />
                                <span className="hidden md:inline">
                                    Mark read
                                </span>
                            </button>
                            <button
                                type="button"
                                className="st-btn st-btn--secondary st-btn--sm"
                                onClick={() => run('mark_unread')}
                                disabled={busy}
                                title="Applies to articles"
                            >
                                <CircleDot aria-hidden="true" />
                                <span className="hidden md:inline">
                                    Mark unread
                                </span>
                            </button>
                            {section === 'archive' ? (
                                <button
                                    type="button"
                                    className="st-btn st-btn--secondary st-btn--sm"
                                    onClick={() => run('unarchive')}
                                    disabled={busy}
                                >
                                    <ArchiveRestore aria-hidden="true" />
                                    Unarchive
                                </button>
                            ) : (
                                <button
                                    type="button"
                                    className="st-btn st-btn--secondary st-btn--sm"
                                    onClick={() => run('archive')}
                                    disabled={busy}
                                >
                                    <Archive aria-hidden="true" />
                                    Archive
                                </button>
                            )}
                            <button
                                type="button"
                                className="st-btn st-btn--danger st-btn--sm"
                                onClick={() => run('trash')}
                                disabled={busy}
                            >
                                <Trash2 aria-hidden="true" />
                                Trash
                            </button>
                        </>
                    )}
                    <button
                        type="button"
                        className="st-icon-btn hover:!bg-ink-2 text-white"
                        onClick={onClear}
                        aria-label="Clear selection"
                    >
                        <X aria-hidden="true" />
                    </button>
                </div>
            </div>

            <StashDialog
                open={dialog === 'add-tags'}
                onOpenChange={(o) => !o && setDialog(null)}
                title={`Add tags to ${selected.length} item${selected.length === 1 ? '' : 's'}`}
                size="sm"
                footer={
                    <>
                        <button
                            type="button"
                            className="st-btn st-btn--secondary"
                            onClick={() => setDialog(null)}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="st-btn st-btn--primary"
                            disabled={!tags.length || busy}
                            aria-busy={busy || undefined}
                            onClick={() => run('add_tags', { tags })}
                        >
                            Add tags
                        </button>
                    </>
                }
            >
                <TagInput value={tags} onChange={setTags} id="bulk-tags" />
            </StashDialog>

            <StashDialog
                open={dialog === 'remove-tags'}
                onOpenChange={(o) => !o && setDialog(null)}
                title="Remove tags"
                size="sm"
                footer={
                    <>
                        <button
                            type="button"
                            className="st-btn st-btn--secondary"
                            onClick={() => setDialog(null)}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="st-btn st-btn--primary"
                            disabled={!removeIds.length || busy}
                            onClick={() =>
                                run('remove_tags', { tag_ids: removeIds })
                            }
                        >
                            Remove
                        </button>
                    </>
                }
            >
                <p className="st-meta mb-3">
                    Choose tags to remove from the selected items. Resources are
                    kept.
                </p>
                <div className="flex flex-wrap gap-2">
                    {removable.map((t) => {
                        const on = removeIds.includes(t.id);

                        return (
                            <button
                                key={t.id}
                                type="button"
                                aria-pressed={on}
                                className={
                                    on
                                        ? 'st-tag st-tag--selected'
                                        : 'st-tag st-tag--white'
                                }
                                onClick={() =>
                                    setRemoveIds((ids) =>
                                        on
                                            ? ids.filter((i) => i !== t.id)
                                            : [...ids, t.id],
                                    )
                                }
                            >
                                <span>{t.name}</span>
                            </button>
                        );
                    })}
                </div>
            </StashDialog>

            <StashDialog
                open={dialog === 'project'}
                onOpenChange={(o) => !o && setDialog(null)}
                title="Assign project"
                size="sm"
                footer={
                    <>
                        <button
                            type="button"
                            className="st-btn st-btn--secondary"
                            onClick={() => setDialog(null)}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="st-btn st-btn--primary"
                            disabled={busy}
                            onClick={() =>
                                run('set_project', { project_id: projectId })
                            }
                        >
                            {projectId ? 'Assign' : 'Unassign'}
                        </button>
                    </>
                }
            >
                <ProjectSelect
                    value={projectId}
                    onChange={setProjectId}
                    id="bulk-project"
                />
            </StashDialog>

            <ConfirmDialog
                open={dialog === 'delete'}
                onOpenChange={(o) => !o && setDialog(null)}
                title={`Delete ${selected.length} item${selected.length === 1 ? '' : 's'} permanently?`}
                destructive
                busy={busy}
                confirmLabel="Delete permanently"
                body="The selected resources and their stored files will be removed for good. This can’t be undone."
                onConfirm={() => run('delete_permanently', { confirm: true })}
            />
        </>
    );
}
