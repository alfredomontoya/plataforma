import { useCallback, useState } from 'react';

export function useSidebarCollapsed() {
    const [collapsed, setCollapsed] = useState(() => localStorage.getItem('sidebar-collapsed') === 'true');
    const toggle = useCallback(() => {
        setCollapsed((c) => {
            localStorage.setItem('sidebar-collapsed', String(!c));
            return !c;
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
