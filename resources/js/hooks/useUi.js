import { useCallback, useEffect, useState } from 'react';

export function useMediaQuery(query) {
    const [matches, setMatches] = useState(() => (
        typeof window !== 'undefined' ? window.matchMedia(query).matches : false
    ));

    useEffect(() => {
        const mql = window.matchMedia(query);
        const onChange = (event) => setMatches(event.matches);

        setMatches(mql.matches);
        mql.addEventListener('change', onChange);

        return () => mql.removeEventListener('change', onChange);
    }, [query]);

    return matches;
}

export function useSidebarCollapsed() {
    const [collapsed, setCollapsed] = useState(() => {
        try {
            return localStorage.getItem('sidebar-collapsed') === 'true';
        } catch {
            return false;
        }
    });

    const toggle = useCallback(() => {
        setCollapsed((current) => {
            const next = !current;
            try {
                localStorage.setItem('sidebar-collapsed', String(next));
            } catch {
                /* almacenamiento no disponible */
            }
            return next;
        });
    }, []);

    return [collapsed, toggle];
}

export function useTheme() {
    const [dark, setDark] = useState(() => document.documentElement.classList.contains('dark'));
    const toggle = () => {
        const next = !dark;
        setDark(next);
        document.documentElement.classList.toggle('dark', next);
        localStorage.setItem('theme', next ? 'dark' : 'light');
    };
    return [dark, toggle];
}
