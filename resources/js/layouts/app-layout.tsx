import { Link, router, usePage } from '@inertiajs/react';
import {
    Bookmark,
    BookOpen,
    Images,
    Layers,
    Menu,
    Plus,
    Search,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { Brand } from '@/components/stash/brand';
import { StashSheet } from '@/components/stash/overlay';
import { SaveDialog } from '@/components/stash/save-dialog';
import { SaveProvider, useSave } from '@/components/stash/save-context';
import { isActive, SidebarContent } from '@/components/stash/sidebar';

/**
 * Stash application shell.
 * Desktop: fixed dark sidebar + content. Mobile: compact top bar with Save,
 * bottom navigation with a "More" drawer for everything else.
 */
export default function AppLayout({ children }: { children: ReactNode }) {
    return (
        <SaveProvider>
            <Shell>{children}</Shell>
            <SaveDialog />
        </SaveProvider>
    );
}

function Shell({ children }: { children: ReactNode }) {
    const [drawer, setDrawer] = useState(false);
    const { url } = usePage();
    const { open, openSave } = useSave();

    useEffect(() => router.on('navigate', () => setDrawer(false)), []);

    // Ctrl/Cmd+V anywhere (outside inputs) opens the save dialog prefilled.
    useEffect(() => {
        const onPaste = (e: ClipboardEvent) => {
            if (open) return;
            const target = e.target as HTMLElement | null;
            if (
                target &&
                (target.closest('input, textarea, select, [contenteditable]') ||
                    target.isContentEditable)
            ) {
                return;
            }
            const data = e.clipboardData;
            if (!data) return;

            const image = Array.from(data.files).find((f) =>
                f.type.startsWith('image/'),
            );
            if (image) {
                e.preventDefault();
                openSave('upload', [image]);

                return;
            }

            const text = data.getData('text/plain').trim();
            if (!text) return;
            e.preventDefault();

            if (/^https?:\/\/\S+$/i.test(text)) {
                openSave('link', [], { url: text });

                return;
            }

            const words = text.split(/\s+/);
            const firstLine = text.split(/\r?\n/)[0].trim();
            openSave('link', [], {
                title:
                    firstLine.length > 100
                        ? `${firstLine.slice(0, 99).trimEnd()}…`
                        : firstLine,
                description:
                    words.slice(0, 10).join(' ') +
                    (words.length > 10 ? '…' : ''),
            });
        };
        document.addEventListener('paste', onPaste);

        return () => document.removeEventListener('paste', onPaste);
    }, [open, openSave]);

    const focusSearch = () => {
        const input = document.getElementById('library-search');
        if (input) {
            input.focus();
        } else {
            router.visit('/library?focus=search');
        }
    };

    const bottom = [
        { href: '/library', label: 'All saved', Icon: Layers },
        { href: '/library/bookmarks', label: 'Bookmarks', Icon: Bookmark },
        { href: '/library/screenshots', label: 'Screenshots', Icon: Images },
        { href: '/library/read-later', label: 'Read later', Icon: BookOpen },
    ];

    return (
        <div className="min-h-dvh lg:grid lg:grid-cols-[var(--st-sidebar-w)_minmax(0,1fr)]">
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[100] focus:rounded-[12px] focus:border-2 focus:border-ink focus:bg-orange focus:px-4 focus:py-3 focus:font-extrabold"
            >
                Skip to content
            </a>

            <aside
                className="sticky top-0 hidden h-dvh lg:block"
                aria-label="Sidebar"
            >
                <div className="st-sidebar">
                    <SidebarContent />
                </div>
            </aside>

            <header className="st-topbar lg:hidden">
                <Link
                    href="/library"
                    className="text-white no-underline"
                    aria-label="Stash home"
                >
                    <Brand size="sm" />
                </Link>
                <div className="ml-auto flex items-center gap-2">
                    <button
                        type="button"
                        className="st-btn st-btn--secondary st-btn--icon st-btn--sm"
                        onClick={focusSearch}
                        aria-label="Search"
                    >
                        <Search />
                    </button>
                    <button
                        type="button"
                        className="st-btn st-btn--primary st-btn--sm"
                        onClick={() => openSave('link')}
                    >
                        <Plus />
                        Save
                    </button>
                </div>
            </header>

            <main
                id="main"
                tabIndex={-1}
                className="min-w-0 px-4 pt-5 pb-32 outline-none sm:px-6 lg:px-8 lg:pt-7 lg:pb-12"
            >
                {children}
            </main>

            <nav className="st-bottomnav lg:hidden" aria-label="Primary">
                {bottom.map(({ href, label, Icon }) => (
                    <Link
                        key={href}
                        href={href}
                        className="st-bottomnav__item"
                        aria-current={isActive(url, href) ? 'page' : undefined}
                    >
                        <Icon aria-hidden="true" />
                        <span>{label}</span>
                    </Link>
                ))}
                <button
                    type="button"
                    className="st-bottomnav__item"
                    onClick={() => setDrawer(true)}
                    aria-haspopup="dialog"
                    aria-expanded={drawer}
                >
                    <Menu aria-hidden="true" />
                    <span>More</span>
                </button>
            </nav>

            <StashSheet
                open={drawer}
                onOpenChange={setDrawer}
                side="left"
                dark
                title="Navigation"
                hideTitle
                label="Navigation"
            >
                <div className="flex min-h-full flex-col gap-4">
                    <SidebarContent onNavigate={() => setDrawer(false)} />
                </div>
            </StashSheet>
        </div>
    );
}
