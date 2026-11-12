import {
    createContext,
    useCallback,
    useContext,
    useMemo,
    useState,
} from 'react';
import type { ReactNode } from 'react';

export type SavePrefill = {
    url?: string;
    title?: string;
    description?: string;
};

type SaveContextValue = {
    open: boolean;
    mode: 'link' | 'upload';
    initialFiles: File[];
    prefill: SavePrefill;
    openSave: (
        mode?: 'link' | 'upload',
        files?: File[],
        prefill?: SavePrefill,
    ) => void;
    setOpen: (open: boolean) => void;
};

const SaveContext = createContext<SaveContextValue | null>(null);

export function SaveProvider({ children }: { children: ReactNode }) {
    const [open, setOpen] = useState(false);
    const [mode, setMode] = useState<'link' | 'upload'>('link');
    const [initialFiles, setInitialFiles] = useState<File[]>([]);

    const [prefill, setPrefill] = useState<SavePrefill>({});

    const openSave = useCallback(
        (
            next: 'link' | 'upload' = 'link',
            files: File[] = [],
            data: SavePrefill = {},
        ) => {
            setMode(next);
            setInitialFiles(files);
            setPrefill(data);
            setOpen(true);
        },
        [],
    );

    const value = useMemo(
        () => ({ open, mode, initialFiles, prefill, openSave, setOpen }),
        [open, mode, initialFiles, prefill, openSave],
    );

    return (
        <SaveContext.Provider value={value}>{children}</SaveContext.Provider>
    );
}

export function useSave(): SaveContextValue {
    const ctx = useContext(SaveContext);
    if (!ctx) {
        throw new Error('useSave must be used inside SaveProvider');
    }

    return ctx;
}
