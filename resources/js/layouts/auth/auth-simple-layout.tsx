import { StashMark } from '@/components/stash/brand';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 p-4 sm:p-10">
            <main className="w-full max-w-[420px]">
                <div className="mb-6 flex flex-col items-center gap-3 text-center">
                    <span className="st-brand">
                        <StashMark className="st-brand__mark !size-10" />
                        <span className="st-brand__word !text-[2.25rem]">
                            Stash
                        </span>
                    </span>
                    <p className="font-bold text-muted-text">
                        Good finds,{' '}
                        <span className="text-orange-text">kept close.</span>
                    </p>
                </div>
                <div className="st-panel p-6 sm:p-8">
                    <div className="mb-6 space-y-1.5">
                        <h1 className="st-h2">{title}</h1>
                        {description && (
                            <p className="text-sm text-muted-text">
                                {description}
                            </p>
                        )}
                    </div>
                    {children}
                </div>
            </main>
        </div>
    );
}
