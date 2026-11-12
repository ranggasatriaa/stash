import { router } from '@inertiajs/react';
import { CircleCheck, CircleX, Info, TriangleAlert, X } from 'lucide-react';
import { useEffect } from 'react';
import { toast, Toaster as Sonner } from 'sonner';
import type { ToastPayload } from '@/types/stash';

const ICONS = {
    success: CircleCheck,
    info: Info,
    warning: TriangleAlert,
    error: CircleX,
};

/** Render a Stash toast (status shown by icon + text, not colour alone). */
export function showToast(payload: ToastPayload) {
    const Icon = ICONS[payload.type] ?? Info;
    const undo = payload.undo;

    toast.custom(
        (id) => (
            <div
                className={`st-toast st-toast--${payload.type}`}
                role={payload.type === 'error' ? 'alert' : 'status'}
            >
                <Icon className="st-toast__icon" aria-hidden="true" />
                <span className="st-toast__message">{payload.message}</span>
                {undo && (
                    <button
                        type="button"
                        className="st-toast__action"
                        onClick={() => {
                            toast.dismiss(id);
                            router.visit(undo.url, {
                                method: undo.method,
                                data: (undo.data ?? {}) as Record<
                                    string,
                                    never
                                >,
                                preserveScroll: true,
                                preserveState: true,
                            });
                        }}
                    >
                        {undo.label ?? 'Undo'}
                    </button>
                )}
                <button
                    type="button"
                    className="st-toast__close"
                    aria-label="Dismiss notification"
                    onClick={() => toast.dismiss(id)}
                >
                    <X size={18} aria-hidden="true" />
                </button>
            </div>
        ),
        { duration: undo ? 7000 : payload.type === 'error' ? 8000 : 4000 },
    );
}

export function StashToaster() {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash as
                | { toast?: ToastPayload }
                | undefined;
            if (flash?.toast) {
                showToast(flash.toast);
            }
        });
    }, []);

    return (
        <Sonner
            position="bottom-right"
            offset={{ bottom: 24, right: 24 }}
            mobileOffset={{ bottom: 96, left: 12, right: 12 }}
            toastOptions={{ unstyled: true }}
            containerAriaLabel="Notifications"
        />
    );
}
