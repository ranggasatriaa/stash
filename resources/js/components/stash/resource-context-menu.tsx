import { router } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    BookmarkMinus,
    BookmarkPlus,
    BookOpenCheck,
    CircleDot,
    ExternalLink,
    RotateCcw,
    Tags,
    Trash2,
} from 'lucide-react';
import type { MouseEvent as ReactMouseEvent, ReactNode } from 'react';
import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { StashDialog } from '@/components/stash/overlay';
import { TagInput } from '@/components/stash/tag-input';
import type { StashResource } from '@/types/stash';

type MenuState = { resource: StashResource; x: number; y: number } | null;

/**
 * Right-click menu for a single resource. Posts to the same bulk endpoint
 * the BulkBar uses, with a one-element id list.
 */
export function useResourceContextMenu(onOpen: (r: StashResource) => void) {
    const [menu, setMenu] = useState<MenuState>(null);
    const [tagTarget, setTagTarget] = useState<StashResource | null>(null);
    const [tags, setTags] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);

    const open = useCallback(
        (r: StashResource, e: ReactMouseEvent) => {
            e.preventDefault();
            e.stopPropagation();
            setMenu({ resource: r, x: e.clientX, y: e.clientY });
        },
        [],
    );

    const close = () => setMenu(null);

    const run = (
        id: string,
        operation: string,
        extra: Record<string, unknown> = {},
        done?: () => void,
    ) => {
        setBusy(true);
        router.post(
            '/resources/bulk',
            { operation, ids: [id], ...extra } as never,
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => done?.(),
                onFinish: () => setBusy(false),
            },
        );
    };

    const element = (
        <>
            {menu && (
                <MenuPopup
                    menu={menu}
                    onClose={close}
                    onOpen={onOpen}
                    onAddTags={(r) => {
                        setTags([]);
                        setTagTarget(r);
                    }}
                    onRun={(op) => run(menu.resource.id, op)}
                />
            )}
            <StashDialog
                open={tagTarget !== null}
                onOpenChange={(o) => !o && setTagTarget(null)}
                title="Add tags"
                size="sm"
                footer={
                    <>
                        <button
                            type="button"
                            className="st-btn st-btn--secondary"
                            onClick={() => setTagTarget(null)}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="st-btn st-btn--primary"
                            disabled={!tags.length || busy}
                            aria-busy={busy || undefined}
                            onClick={() =>
                                tagTarget &&
                                run(tagTarget.id, 'add_tags', { tags }, () =>
                                    setTagTarget(null),
                                )
                            }
                        >
                            Add tags
                        </button>
                    </>
                }
            >
                <TagInput value={tags} onChange={setTags} id="ctx-tags" />
            </StashDialog>
        </>
    );

    return { openMenu: open, element };
}

function Item({
    icon,
    children,
    onClick,
    danger,
}: {
    icon: ReactNode;
    children: ReactNode;
    onClick: () => void;
    danger?: boolean;
}) {
    return (
        <button
            type="button"
            role="menuitem"
            className={
                danger
                    ? 'st-menu__item st-menu__item--danger'
                    : 'st-menu__item'
            }
            onClick={onClick}
        >
            {icon}
            {children}
        </button>
    );
}

function MenuPopup({
    menu,
    onClose,
    onOpen,
    onAddTags,
    onRun,
}: {
    menu: NonNullable<MenuState>;
    onClose: () => void;
    onOpen: (r: StashResource) => void;
    onAddTags: (r: StashResource) => void;
    onRun: (operation: string) => void;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [pos, setPos] = useState({ left: menu.x, top: menu.y });
    const r = menu.resource;

    // Keep the menu inside the viewport.
    useLayoutEffect(() => {
        const el = ref.current;

        if (!el) {
            return;
        }

        const { width, height } = el.getBoundingClientRect();
        setPos({
            left: Math.max(8, Math.min(menu.x, window.innerWidth - width - 8)),
            top: Math.max(8, Math.min(menu.y, window.innerHeight - height - 8)),
        });
        el.querySelector<HTMLElement>('[role="menuitem"]')?.focus();
    }, [menu.x, menu.y]);

    useEffect(() => {
        const down = (e: MouseEvent) => {
            if (!ref.current?.contains(e.target as Node)) {
                onClose();
            }
        };
        const key = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                onClose();
            }

            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                const items = [
                    ...(ref.current?.querySelectorAll<HTMLElement>(
                        '[role="menuitem"]',
                    ) ?? []),
                ];
                const i = items.indexOf(document.activeElement as HTMLElement);
                const next =
                    e.key === 'ArrowDown'
                        ? (i + 1) % items.length
                        : (i - 1 + items.length) % items.length;
                items[next]?.focus();
            }
        };
        // Defer so the very event that opened the menu (React flushes the
        // render synchronously, before the native event reaches document)
        // can't close it again.
        const timer = window.setTimeout(() => {
            document.addEventListener('mousedown', down);
            document.addEventListener('contextmenu', down);
            window.addEventListener('blur', onClose);
            window.addEventListener('resize', onClose);
            window.addEventListener('scroll', onClose, true);
        }, 0);
        document.addEventListener('keydown', key);

        return () => {
            window.clearTimeout(timer);
            document.removeEventListener('mousedown', down);
            document.removeEventListener('contextmenu', down);
            document.removeEventListener('keydown', key);
            window.removeEventListener('blur', onClose);
            window.removeEventListener('resize', onClose);
            window.removeEventListener('scroll', onClose, true);
        };
    }, [onClose]);

    const act = (fn: () => void) => () => {
        onClose();
        fn();
    };

    const trashed = Boolean(r.deleted_at);
    const archived = Boolean(r.archived_at);
    const article = r.type === 'article';

    return (
        <div
            ref={ref}
            role="menu"
            aria-label={`Actions for ${r.title}`}
            className="st-menu fixed"
            style={{ left: pos.left, top: pos.top }}
        >
            <Item
                icon={<ExternalLink aria-hidden="true" />}
                onClick={act(() => onOpen(r))}
            >
                Open details
            </Item>
            {trashed ? (
                <Item
                    icon={<RotateCcw aria-hidden="true" />}
                    onClick={act(() => onRun('restore'))}
                >
                    Restore
                </Item>
            ) : (
                <>
                    <Item
                        icon={<Tags aria-hidden="true" />}
                        onClick={act(() => onAddTags(r))}
                    >
                        Add tags…
                    </Item>
                    {article && (
                        <Item
                            icon={
                                r.reading_status === 'read' ? (
                                    <CircleDot aria-hidden="true" />
                                ) : (
                                    <BookOpenCheck aria-hidden="true" />
                                )
                            }
                            onClick={act(() =>
                                onRun(
                                    r.reading_status === 'read'
                                        ? 'mark_unread'
                                        : 'mark_read',
                                ),
                            )}
                        >
                            {r.reading_status === 'read'
                                ? 'Mark as unread'
                                : 'Mark as read'}
                        </Item>
                    )}
                    <Item
                        icon={
                            r.read_later ? (
                                <BookmarkMinus aria-hidden="true" />
                            ) : (
                                <BookmarkPlus aria-hidden="true" />
                            )
                        }
                        onClick={act(() =>
                            onRun(
                                r.read_later
                                    ? 'read_later_off'
                                    : 'read_later_on',
                            ),
                        )}
                    >
                        {r.read_later ? 'Remove from read later' : 'Read later'}
                    </Item>
                    <Item
                        icon={
                            archived ? (
                                <ArchiveRestore aria-hidden="true" />
                            ) : (
                                <Archive aria-hidden="true" />
                            )
                        }
                        onClick={act(() =>
                            onRun(archived ? 'unarchive' : 'archive'),
                        )}
                    >
                        {archived ? 'Unarchive' : 'Archive'}
                    </Item>
                    <Item
                        danger
                        icon={<Trash2 aria-hidden="true" />}
                        onClick={act(() => onRun('trash'))}
                    >
                        Move to trash
                    </Item>
                </>
            )}
        </div>
    );
}
