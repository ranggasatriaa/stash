import type { NavData, StashUser } from '@/types/stash';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            app_version: string | null;
            auth: { user: StashUser };
            nav: NavData | null;
            ai: { enabled: boolean } | null;
            limits: {
                upload_max_bytes: number;
                upload_max_video_bytes: number;
                upload_max_pixels: number;
            };
            [key: string]: unknown;
        };
    }
}
