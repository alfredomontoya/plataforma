import { useState } from 'react';
import { router } from '@inertiajs/react';
import { useAuth } from '../lib/auth';
import { Button, Input, PasswordInput } from '../components/ui/controls';
import { useToast } from '../components/ui/Toast';

export default function Login() {
    const { login } = useAuth();
    const { toastError } = useToast();
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
        <div className="flex min-h-screen items-center justify-center p-4">
            <form onSubmit={submit} className="card w-full max-w-sm space-y-4 p-6">
                <h1 className="text-xl font-bold text-primary-700 dark:text-primary-300">PlataformaRO</h1>
                <Input label="Usuario" value={username} onChange={(e) => setUsername(e.target.value)} required />
                <PasswordInput label="Contraseña" value={password} onChange={(e) => setPassword(e.target.value)} required />
                <Button type="submit" loading={loading} className="w-full">
                    Ingresar
                </Button>
            </form>
        </div>
    );
}
