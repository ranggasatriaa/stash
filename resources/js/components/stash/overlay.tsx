import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { useRef } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Remember the element focused when an overlay opens and return focus to it
 * on close. Radix only does this for its own Trigger; our overlays are opened
 * from state (buttons, shortcuts, URL), so we restore focus explicitly.
 */
function useReturnFocus(open: boolean) {
    const previous = useRef<HTMLElement | null>(null);
    const wasOpen = useRef(false);
    if (open && !wasOpen.current && typeof document !== 'undefined') {
        previous.current = document.activeElement as HTMLElement | null;
    }
    wasOpen.current = open;

    return (event: Event) => {
        const target = previous.current;
        if (target && target.isConnected && target !== document.body) {
            event.preventDefault();
            target.focus();
        }
    };
}

/**
 * Accessible dialog/sheet built on Radix Dialog (focus trap, focus
 * restoration, Escape, aria wiring) and styled with the master CSS.
 */
export function StashDialog({
    open,
    onOpenChange,
    title,
    description,
    children,
    footer,
    size = 'md',
    className,
    hideTitle = false,
    onOpenAutoFocus,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: ReactNode;
    description?: ReactNode;
    children?: ReactNode;
    footer?: ReactNode;
    size?: 'sm' | 'md' | 'lg';
    className?: string;
    hideTitle?: boolean;
    onOpenAutoFocus?: (e: Event) => void;
}) {
    const onCloseAutoFocus = useReturnFocus(open);

    return (
        <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="st-overlay data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0" />
                <DialogPrimitive.Content
                    className={cn(
                        'st-dialog data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95',
                        size === 'sm' && 'st-dialog--sm',
                        size === 'lg' && 'st-dialog--lg',
                        className,
                    )}
                    onOpenAutoFocus={onOpenAutoFocus}
                    onCloseAutoFocus={onCloseAutoFocus}
                    {...(description ? {} : { 'aria-describedby': undefined })}
                >
                    <div className="st-dialog__header">
                        <DialogPrimitive.Title
                            className={cn(
                                'st-dialog__title',
                                hideTitle && 'st-sr-only',
                            )}
                        >
                            {title}
                        </DialogPrimitive.Title>
                        <DialogPrimitive.Close
                            className="st-icon-btn"
                            aria-label="Close"
                        >
                            <X aria-hidden="true" />
                        </DialogPrimitive.Close>
                    </div>
                    {description && (
                        <DialogPrimitive.Description className="st-dialog__description">
                            {description}
                        </DialogPrimitive.Description>
                    )}
                    {children && (
                        <div className="st-dialog__body">{children}</div>
                    )}
                    {footer && (
                        <div className="st-dialog__footer">{footer}</div>
                    )}
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}

export function StashSheet({
    open,
    onOpenChange,
    side = 'right',
    title,
    hideTitle = false,
    header,
    children,
    footer,
    dark = false,
    className,
    label,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    side?: 'right' | 'left' | 'bottom' | 'full';
    title: ReactNode;
    hideTitle?: boolean;
    header?: ReactNode;
    children: ReactNode;
    footer?: ReactNode;
    dark?: boolean;
    className?: string;
    label?: string;
}) {
    const slide =
        side === 'right'
            ? 'data-[state=open]:slide-in-from-right data-[state=closed]:slide-out-to-right'
            : side === 'left'
              ? 'data-[state=open]:slide-in-from-left data-[state=closed]:slide-out-to-left'
              : side === 'bottom'
                ? 'data-[state=open]:slide-in-from-bottom data-[state=closed]:slide-out-to-bottom'
                : 'data-[state=open]:fade-in-0';
    const onCloseAutoFocus = useReturnFocus(open);

    return (
        <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="st-overlay data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0" />
                <DialogPrimitive.Content
                    className={cn(
                        'st-sheet data-[state=closed]:animate-out data-[state=open]:animate-in motion-reduce:animate-none',
                        `st-sheet--${side}`,
                        dark && 'st-sheet--dark',
                        slide,
                        className,
                    )}
                    aria-describedby={undefined}
                    onCloseAutoFocus={onCloseAutoFocus}
                    aria-label={label}
                >
                    <div
                        className={cn(
                            'st-sheet__header',
                            dark && 'border-[#34363c]',
                        )}
                    >
                        <DialogPrimitive.Title
                            className={cn(
                                'text-lg font-extrabold',
                                hideTitle && 'st-sr-only',
                            )}
                        >
                            {title}
                        </DialogPrimitive.Title>
                        {header}
                        <DialogPrimitive.Close
                            className="st-icon-btn ml-auto"
                            aria-label="Close"
                        >
                            <X aria-hidden="true" />
                        </DialogPrimitive.Close>
                    </div>
                    <div className="st-sheet__body">{children}</div>
                    {footer && <div className="st-sheet__footer">{footer}</div>}
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}

/** Confirmation dialog (keyboard accessible; destructive variant). */
export function ConfirmDialog({
    open,
    onOpenChange,
    title,
    body,
    confirmLabel = 'Confirm',
    destructive = false,
    busy = false,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    body: ReactNode;
    confirmLabel?: string;
    destructive?: boolean;
    busy?: boolean;
    onConfirm: () => void;
}) {
    return (
        <StashDialog
            open={open}
            onOpenChange={onOpenChange}
            title={title}
            size="sm"
            footer={
                <>
                    <button
                        type="button"
                        className="st-btn st-btn--secondary"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        className={cn(
                            'st-btn',
                            destructive
                                ? 'st-btn--danger-solid'
                                : 'st-btn--primary',
                        )}
                        aria-busy={busy || undefined}
                        disabled={busy}
                        onClick={onConfirm}
                    >
                        {confirmLabel}
                    </button>
                </>
            }
        >
            <div className="text-[15px] leading-relaxed">{body}</div>
        </StashDialog>
    );
}
