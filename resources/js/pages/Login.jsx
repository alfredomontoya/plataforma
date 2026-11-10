import { useState } from 'react';
import { router } from '@inertiajs/react';
import { SunIcon, MoonIcon } from '@heroicons/react/24/outline';
import { useAuth } from '../lib/auth';
import { useTheme } from '../hooks/useUi';
import { Button, Input, PasswordInput } from '../components/ui/controls';
import { useToast } from '../components/ui/Toast';
import EnvBadge from '../components/layout/EnvBadge';
import { APP_NAME } from '../lib/app';

export default function Login() {
    const { login } = useAuth();
    const { toastError } = useToast();
    const [dark, toggleTheme] = useTheme();
    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [loading, setLoading] = useState(false);

    const submit = async (e) => {
        e.preventDefault();
        setLoading(true);
        try {
            await login(username, password);
            router.visit('/');
        } catch {
            toastError('Credenciales inválidas.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="relative flex min-h-screen flex-col items-center justify-center gap-4 p-4">
            <button
                onClick={toggleTheme}
                className="absolute right-4 top-4 rounded-lg border border-stone-200 bg-white p-2 text-stone-500 shadow-sm hover:bg-stone-100 dark:border-white/10 dark:bg-wa-panel dark:text-wa-muted dark:hover:bg-white/5"
                aria-label={dark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'}
                title={dark ? 'Modo claro' : 'Modo oscuro'}
            >
                {dark ? <SunIcon className="h-5 w-5" /> : <MoonIcon className="h-5 w-5" />}
            </button>
            <form onSubmit={submit} className="card w-full max-w-sm space-y-4 p-6">
                <div className="flex flex-col items-center gap-2">
                    <div className="logo-sway h-24 w-24" aria-hidden="true">
                        <div className="logo-sway__inner h-full w-full">
                            <img
                                src="/android-chrome-512x512.png"
                                alt={APP_NAME}
                                className="h-full w-full"
                            />
                        </div>
                    </div>
                    <h1 className="text-xl font-bold text-primary-700 dark:text-primary-300">{APP_NAME}</h1>
                </div>
                <Input label="Usuario" value={username} onChange={(e) => setUsername(e.target.value)} required />
                <PasswordInput label="Contraseña" value={password} onChange={(e) => setPassword(e.target.value)} required />
                <Button type="submit" loading={loading} className="w-full">
                    Ingresar
                </Button>
            </form>
            <EnvBadge />
        </div>
    );
}
