import { useEffect, useState } from 'react';

export function useMedia(query: string, initial = false): boolean {
    const [matches, setMatches] = useState(() =>
        typeof window === 'undefined'
            ? initial
            : window.matchMedia(query).matches,
    );

    useEffect(() => {
        const mql = window.matchMedia(query);
        const onChange = () => setMatches(mql.matches);
        onChange();
        mql.addEventListener('change', onChange);

        return () => mql.removeEventListener('change', onChange);
    }, [query]);

    return matches;
}

export const useIsDesktop = () => useMedia('(min-width: 1024px)', true);
