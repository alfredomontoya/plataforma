import { createContext, useContext, useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import api from './api';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);
    const [otherSessions, setOtherSessions] = useState(0);

    // Cuenta las sesiones distintas a la actual y devuelve el listado completo.
    const refreshSessions = async () => {
        try {
            const r = await api.get('/auth/sessions');
            const sessions = r.data.data ?? [];
            setOtherSessions(sessions.filter((s) => !s.current).length);
            return sessions;
        } catch {
            return null;
        }
    };

    useEffect(() => {
        if (!localStorage.getItem('accessToken')) {
            setLoading(false);
            return;
        }
        api.get('/auth/me')
            .then((r) => {
                setUser(r.data.data);
                return refreshSessions();
            })
            .catch(() => localStorage.removeItem('accessToken'))
            .finally(() => setLoading(false));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const login = async (username, password) => {
        const r = await api.post('/auth/login', { username, password });
        localStorage.setItem('accessToken', r.data.data.accessToken);
        setUser(r.data.data.user);
        setOtherSessions(r.data.data.otherSessions ?? 0);
        return r.data.data.user;
    };

    const logout = async () => {
        try {
            await api.post('/auth/logout');
        } catch {
            /* token ya inválido */
        }
        localStorage.removeItem('accessToken');
        setUser(null);
        setOtherSessions(0);
        router.visit('/login');
    };

    return (
        <AuthContext.Provider value={{ user, loading, login, logout, otherSessions, refreshSessions }}>
            {children}
        </AuthContext.Provider>
    );
}

export const useAuth = () => useContext(AuthContext);
