import { Link, usePage } from '@inertiajs/react';
import {
    ClipboardDocumentListIcon,
    UsersIcon,
    Cog6ToothIcon,
    ChartBarIcon,
    DocumentPlusIcon,
    ArchiveBoxIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    SunIcon,
    MoonIcon,
    ArrowRightOnRectangleIcon,
} from '@heroicons/react/24/outline';
import { useSidebarCollapsed, useTheme } from '../../hooks/useUi';
import { useAuth } from '../../lib/auth';

const ITEMS = [
    { href: '/operador/entrada', label: 'Registro', icon: ClipboardDocumentListIcon, roles: ['OPERATOR_INGRESO', 'OPERATOR_ENTREGA', 'ADMIN'] },
    { href: '/admin/usuarios', label: 'Usuarios', icon: UsersIcon, roles: ['ADMIN'] },
    { href: '/admin/servicios', label: 'Servicios', icon: Cog6ToothIcon, roles: ['ADMIN'] },
    { href: '/jefe/dashboard', label: 'Dashboard', icon: ChartBarIcon, roles: ['JEFE', 'ADMIN'] },
    { href: '/jefe/reportes', label: 'Reportes', icon: DocumentPlusIcon, roles: ['JEFE', 'ADMIN'] },
    { href: '/jefe/reportes/historial', label: 'Historial', icon: ArchiveBoxIcon, roles: ['JEFE', 'ADMIN'] },
];

function Sidebar() {
    const [collapsed, toggle] = useSidebarCollapsed();
    const { user } = useAuth();
    const { url } = usePage();
    const visible = ITEMS.filter((i) => user && i.roles.includes(user.role));

    return (
        <aside
            id="sidebar"
            className={`flex flex-col border-r border-stone-200 bg-white transition-[width] duration-200 dark:border-white/10 dark:bg-[#1c1917] ${collapsed ? 'w-16' : 'w-64'}`}
        >
            <div className="flex items-center justify-between p-3">
                {!collapsed && <span className="font-bold text-primary-700 dark:text-primary-300">PlataformaRO</span>}
                <button
                    onClick={toggle}
                    aria-expanded={!collapsed}
                    aria-controls="sidebar"
                    className="rounded p-1 text-stone-500 hover:bg-stone-100 dark:hover:bg-white/5"
                    title={collapsed ? 'Maximizar' : 'Minimizar'}
                >
                    {collapsed ? <ChevronRightIcon className="h-5 w-5" /> : <ChevronLeftIcon className="h-5 w-5" />}
                </button>
            </div>
            <nav className="flex flex-1 flex-col gap-1 p-2">
                {visible.map((item) => {
                    const active = url.startsWith(item.href);
                    const Icon = item.icon;
                    const link = (
                        <Link
                            key={item.href}
                            href={item.href}
                            className={`group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm ${
                                active
                                    ? 'bg-primary-100 text-primary-800 dark:bg-primary-900/50 dark:text-red-200'
                                    : 'text-stone-600 hover:bg-stone-100 dark:text-stone-300 dark:hover:bg-white/5'
                            } ${collapsed ? 'justify-center' : ''}`}
                        aria-label={item.label}
                    >
                        <Icon className="h-5 w-5 shrink-0" />
                        {!collapsed && <span>{item.label}</span>}
                        {collapsed && (
                            <span
                                role="tooltip"
                                className="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded bg-stone-900 px-2 py-1 text-xs text-white group-hover:block group-focus:block"
                            >
                                {item.label}
                            </span>
                        )}
                    </Link>
                );
                return link;
            })}
            </nav>
        </aside>
    );
}

export default function Layout({ children }) {
    const { user, loading, logout } = useAuth();
    const [dark, toggleTheme] = useTheme();
    const { url } = usePage();

    if (url.startsWith('/login')) return <>{children}</>;
    if (loading) {
        return (
            <div className="flex h-screen items-center justify-center">
                <span className="h-8 w-8 animate-spin rounded-full border-4 border-stone-300 border-t-primary-600 dark:border-t-primary-500" />
            </div>
        );
    }

    return (
        <div className="flex h-screen overflow-hidden">
            {user && <Sidebar />}
            <div className="flex flex-1 flex-col overflow-hidden">
                <header className="flex items-center justify-between border-b border-stone-200 bg-white px-4 py-2 dark:border-white/10 dark:bg-[#1c1917]">
                    <span className="text-sm text-stone-500">{user ? `${user.username} · ${user.role}` : ''}</span>
                    <div className="flex items-center gap-2">
                        <button onClick={toggleTheme} className="rounded p-1.5 text-stone-500 hover:bg-stone-100 dark:hover:bg-white/5" aria-label="Tema">
                            {dark ? <SunIcon className="h-5 w-5" /> : <MoonIcon className="h-5 w-5" />}
                        </button>
                        {user && (
                            <button onClick={logout} className="rounded p-1.5 text-stone-500 hover:bg-stone-100 dark:hover:bg-white/5" aria-label="Salir">
                                <ArrowRightOnRectangleIcon className="h-5 w-5" />
                            </button>
                        )}
                    </div>
                </header>
                <main className="flex-1 overflow-auto p-4">{children}</main>
            </div>
        </div>
    );
}
