import { Link, usePage } from '@inertiajs/react';
import {
    Archive,
    Bookmark,
    BookOpen,
    Filter,
    Folder,
    Images,
    Inbox,
    Layers,
    LogOut,
    Settings,
    Tag,
    Trash2,
} from 'lucide-react';
import type { ComponentType } from 'react';
import { Brand } from '@/components/stash/brand';
import { filtersToQuery, toQueryString } from '@/lib/library';
import { cn } from '@/lib/utils';
import type { NavData, SectionKey } from '@/types/stash';

type Item = {
    key: string;
    label: string;
    href: string;
    Icon: ComponentType<{ className?: string }>;
    count?: number;
};

function pathOf(url: string): string {
    return url.split('?')[0] ?? url;
}

export function useNavItems() {
    const { nav } = usePage().props;
    const counts = (nav?.counts ?? {}) as Partial<Record<SectionKey, number>>;

    const main: Item[] = [
        {
            key: 'all',
            label: 'All saved',
            href: '/library',
            Icon: Layers,
            count: counts.all,
        },
        {
            key: 'bookmarks',
            label: 'Bookmarks',
            href: '/library/bookmarks',
            Icon: Bookmark,
            count: counts.bookmarks,
        },
        {
            key: 'screenshots',
            label: 'Screenshots',
            href: '/library/screenshots',
            Icon: Images,
            count: counts.screenshots,
        },
        {
            key: 'read-later',
            label: 'Read later',
            href: '/library/read-later',
            Icon: BookOpen,
            count: counts['read-later'],
        },
        {
            key: 'projects',
            label: 'Projects',
            href: '/projects',
            Icon: Folder,
            count: nav?.projects.length,
        },
    ];
    const secondary: Item[] = [
        {
            key: 'tags',
            label: 'Tags',
            href: '/tags',
            Icon: Tag,
            count: nav?.tags.length,
        },
        {
            key: 'archive',
            label: 'Archive',
            href: '/library/archive',
            Icon: Archive,
            count: counts.archive,
        },
        {
            key: 'trash',
            label: 'Trash',
            href: '/library/trash',
            Icon: Trash2,
            count: counts.trash,
        },
        {
            key: 'settings',
            label: 'Settings',
            href: '/settings/profile',
            Icon: Settings,
        },
    ];

    return { main, secondary, counts, nav: nav as NavData | null };
}

export function isActive(currentUrl: string, href: string): boolean {
    const path = pathOf(currentUrl);
    if (href === '/library') {
        return path === '/library';
    }
    if (href === '/settings/profile') {
        return path.startsWith('/settings');
    }
    if (href === '/projects' || href === '/tags') {
        return path === href;
    }

    return path === href || path.startsWith(`${href}/`);
}

function NavLink({
    item,
    current,
    onNavigate,
}: {
    item: Item;
    current: string;
    onNavigate?: () => void;
}) {
    const active = isActive(current, item.href);

    return (
        <li>
            <Link
                href={item.href}
                className="st-nav__item"
                aria-current={active ? 'page' : undefined}
                onClick={onNavigate}
            >
                <item.Icon />
                <span className="st-nav__label">{item.label}</span>
                {item.count !== undefined && (
                    <span className="st-nav__count">{item.count}</span>
                )}
            </Link>
        </li>
    );
}

/** Dark sidebar: navigation, saved views, projects and tags. */
export function SidebarContent({ onNavigate }: { onNavigate?: () => void }) {
    const { url } = usePage();
    const { main, secondary, counts, nav } = useNavItems();
    const topTags = [...(nav?.tags ?? [])]
        .sort((a, b) => b.count - a.count)
        .slice(0, 10);

    return (
        <>
            <Link
                href="/library"
                className="text-white no-underline"
                onClick={onNavigate}
                aria-label="Stash home"
            >
                <Brand slogan />
            </Link>

            <nav aria-label="Main">
                <ul className="st-nav">
                    {main.map((item) => (
                        <NavLink
                            key={item.key}
                            item={item}
                            current={url}
                            onNavigate={onNavigate}
                        />
                    ))}
                </ul>
                <hr className="st-nav__separator" />
                <ul className="st-nav">
                    {secondary.map((item) => (
                        <NavLink
                            key={item.key}
                            item={item}
                            current={url}
                            onNavigate={onNavigate}
                        />
                    ))}
                </ul>
            </nav>

            <nav aria-label="Saved views">
                <p className="st-nav__heading">Saved views</p>
                <ul className="st-nav">
                    <NavLink
                        item={{
                            key: 'inbox',
                            label: 'Inbox (untagged)',
                            href: '/library/inbox',
                            Icon: Inbox,
                            count: counts.inbox,
                        }}
                        current={url}
                        onNavigate={onNavigate}
                    />
                    {nav?.saved_views.map((view) => {
                        const base =
                            view.section === 'all'
                                ? '/library'
                                : `/library/${view.section}`;
                        const href =
                            base +
                            toQueryString(
                                filtersToQuery({
                                    ...view.filters,
                                    sort: view.sort,
                                }),
                            );

                        return (
                            <li key={view.id}>
                                <Link
                                    href={href}
                                    className="st-nav__item"
                                    aria-current={
                                        url === href ? 'page' : undefined
                                    }
                                    onClick={onNavigate}
                                >
                                    <Filter />
                                    <span className="st-nav__label">
                                        {view.name}
                                    </span>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </nav>

            {nav && nav.projects.length > 0 && (
                <nav aria-label="Projects">
                    <p className="st-nav__heading">Projects</p>
                    <ul className="st-nav">
                        {nav.projects.map((p) => {
                            const href = `/projects/${p.id}`;

                            return (
                                <li key={p.id}>
                                    <Link
                                        href={href}
                                        className="st-nav__item"
                                        aria-current={
                                            pathOf(url) === href
                                                ? 'page'
                                                : undefined
                                        }
                                        onClick={onNavigate}
                                    >
                                        <span
                                            className="st-nav__dot"
                                            style={{
                                                background:
                                                    p.color ?? 'transparent',
                                            }}
                                            aria-hidden="true"
                                        />
                                        <span className="st-nav__label">
                                            {p.name}
                                        </span>
                                        <span className="st-nav__count">
                                            {p.count}
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </nav>
            )}

            {topTags.length > 0 && (
                <nav aria-label="Tags">
                    <p className="st-nav__heading">
                        <span>Top tags</span>
                        <Link
                            href="/tags"
                            className="text-[11px] text-white underline underline-offset-2"
                            onClick={onNavigate}
                        >
                            All
                        </Link>
                    </p>
                    <ul className="flex flex-wrap gap-1.5 px-1 pt-1">
                        {topTags.map((t) => {
                            const href = `/tags/${t.id}`;
                            const active = pathOf(url) === href;

                            return (
                                <li key={t.id} className="max-w-full min-w-0">
                                    <Link
                                        href={href}
                                        onClick={onNavigate}
                                        aria-current={
                                            active ? 'page' : undefined
                                        }
                                        className={cn(
                                            'st-tag max-w-full',
                                            active ? '' : 'st-tag--white',
                                        )}
                                    >
                                        <span>{t.name}</span>
                                        <span className="opacity-70">
                                            {t.count}
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </nav>
            )}

            <div className="mt-auto pt-2">
                <UserFooter />
            </div>
        </>
    );
}

function UserFooter() {
    const user = usePage().props.auth?.user;

    return (
        <div className="flex items-center gap-2 rounded-[12px] border-2 border-[#34363c] p-2">
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-bold">{user?.name}</p>
                <p className="truncate text-xs text-[#b9bbc2]">{user?.email}</p>
            </div>
            <Link
                href="/logout"
                method="post"
                as="button"
                className="st-btn st-btn--ghost st-btn--on-dark st-btn--icon st-btn--sm"
                aria-label="Log out"
            >
                <LogOut />
            </Link>
        </div>
    );
}
