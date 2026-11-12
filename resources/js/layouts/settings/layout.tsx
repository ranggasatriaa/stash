import { Link, usePage } from '@inertiajs/react';
import {
    Bot,
    Database,
    KeyRound,
    Shield,
    SlidersHorizontal,
    UserRound,
} from 'lucide-react';
import type { PropsWithChildren } from 'react';

const items = [
    { href: '/settings/profile', label: 'Profile', Icon: UserRound },
    { href: '/settings/security', label: 'Security', Icon: Shield },
    {
        href: '/settings/preferences',
        label: 'Preferences',
        Icon: SlidersHorizontal,
    },
    { href: '/settings/tokens', label: 'API tokens', Icon: KeyRound },
    { href: '/settings/data', label: 'Data & export', Icon: Database },
    { href: '/settings/ai', label: 'AI summaries', Icon: Bot },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { url } = usePage();
    const path = url.split('?')[0];

    return (
        <div className="mx-auto flex max-w-[1100px] flex-col gap-6">
            <header>
                <h1 className="st-h2">Settings</h1>
                <p className="mt-1 text-muted-text">
                    Account, security, API access, data and optional AI.
                </p>
            </header>
            <div className="flex flex-col gap-6 lg:flex-row lg:gap-10">
                <nav aria-label="Settings" className="lg:w-56 lg:shrink-0">
                    <ul className="flex gap-2 overflow-x-auto pb-1 lg:flex-col lg:overflow-visible">
                        {items.map(({ href, label, Icon }) => (
                            <li key={href} className="shrink-0">
                                <Link
                                    href={href}
                                    className="st-nav-light__item whitespace-nowrap"
                                    aria-current={
                                        path === href ? 'page' : undefined
                                    }
                                >
                                    <Icon
                                        className="size-[18px]"
                                        aria-hidden="true"
                                    />
                                    {label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </nav>
                <div className="min-w-0 flex-1">
                    <section className="flex max-w-3xl flex-col gap-8">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
