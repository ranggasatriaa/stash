import { createInertiaApp } from '@inertiajs/react';
import { StashToaster } from '@/components/stash/toaster';
import { TooltipProvider } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Stash';

void createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={300}>
                {app}
                <StashToaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#FF7417',
        delay: 200,
    },
});
