import { useState } from 'react';
import { router } from '@inertiajs/react';
import { SunIcon, MoonIcon } from '@heroicons/react/24/outline';
import { useAuth } from '../lib/auth';
import { useTheme } from '../hooks/useUi';
import { Button, Input, PasswordInput } from '../components/ui/controls';
import { useToast } from '../components/ui/Toast';
import { APP_NAME } from '../lib/app';

export default function Login() {
    const { login } = useAuth();
    const { toastError } = useToast();
    const [dark, toggleTheme] = useTheme();
    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [loading, setLoading] = useState(false);

    // Líneas de velocidad solo abajo (efecto de avance hacia el espectador).
    const lines = [Math.PI / 4, Math.PI / 2, (3 * Math.PI) / 4].map((a, i) => {
        const cx = 72;
        const cy = 72;
        const r1 = 34;
        const r2 = 66;
        const f = (n) => Math.round(n * 10) / 10;
        return {
            x1: f(cx + r1 * Math.cos(a)),
            y1: f(cy + r1 * Math.sin(a)),
            x2: f(cx + r2 * Math.cos(a)),
            y2: f(cy + r2 * Math.sin(a)),
            delay: `${(i % 2) * 0.45}s`,
        };
    });

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
        <div className="relative flex min-h-screen items-center justify-center p-4">
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
                    <div className="car-run h-24 w-24 text-stone-400 dark:text-white/60" aria-hidden="true">
                        <svg
                            viewBox="0 0 144 144"
                            className="car-lines absolute -inset-6 h-[calc(100%+3rem)] w-[calc(100%+3rem)]"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth={4}
                            strokeLinecap="round"
                        >
                            {lines.map((l, i) => (
                                <line key={i} x1={l.x1} y1={l.y1} x2={l.x2} y2={l.y2} style={{ animationDelay: l.delay }} />
                            ))}
                        </svg>
                        <img src="/android-chrome-512x512.png" alt={APP_NAME} className="car-body relative h-full w-full" />
                        <div className="car-glow absolute -bottom-1 left-0 right-0 mx-auto h-3 w-4/5 rounded-full bg-amber-300/70 blur-[3px] dark:bg-amber-200/25" />
                    </div>
                    <h1 className="text-xl font-bold text-primary-700 dark:text-primary-300">{APP_NAME}</h1>
                </div>
                <Input label="Usuario" value={username} onChange={(e) => setUsername(e.target.value)} required />
                <PasswordInput label="Contraseña" value={password} onChange={(e) => setPassword(e.target.value)} required />
                <Button type="submit" loading={loading} className="w-full">
                    Ingresar
                </Button>
            </form>
        </div>
    );
}
