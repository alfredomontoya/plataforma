import { router } from '@inertiajs/react';
import { useAuth } from '../../lib/auth';

export function RoleGuard({ allowed, children }) {
    const { user, loading } = useAuth();
    if (loading) return null;
    if (!user) {
        router.visit('/login');
        return null;
    }
    if (allowed && !allowed.includes(user.role)) {
        router.visit('/');
        return null;
    }
    return <>{children}</>;
}
