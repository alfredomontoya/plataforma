import { createContext, useContext, useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import api from './api';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        if (!localStorage.getItem('accessToken')) {
            setLoading(false);
            return;
        }
        api.get('/auth/me')
            .then((r) => setUser(r.data.data))
            .catch(() => localStorage.removeItem('accessToken'))
            .finally(() => setLoading(false));
    }, []);

    const login = async (username, password) => {
        const r = await api.post('/auth/login', { username, password });
        localStorage.setItem('accessToken', r.data.data.accessToken);
        setUser(r.data.data.user);
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
        router.visit('/login');
    };

    return <AuthContext.Provider value={{ user, loading, login, logout }}>{children}</AuthContext.Provider>;
}

export const useAuth = () => useContext(AuthContext);
