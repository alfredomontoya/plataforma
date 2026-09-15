import { Link, usePage } from '@inertiajs/react';
import {
    ClipboardDocumentListIcon,
    UsersIcon,
    Cog6ToothIcon,
    ChartBarIcon,
    DocumentPlusIcon,
    ArchiveBoxIcon,
    ChevronLeftIcon,
    InformationCircleIcon,
    SunIcon,
    MoonIcon,
    ArrowRightOnRectangleIcon,
} from '@heroicons/react/24/outline';
import { useSidebarCollapsed, useTheme } from '../../hooks/useUi';
import { useAuth } from '../../lib/auth';
import { APP_NAME } from '../../lib/app';
import VehicleDoodles from './VehicleDoodles';

const ITEMS = [
    { href: '/operador/entrada', label: 'Registro', icon: ClipboardDocumentListIcon, roles: ['OPERATOR_INGRESO', 'OPERATOR_ENTREGA', 'ADMIN'] },
    { href: '/admin/usuarios', label: 'Usuarios', icon: UsersIcon, roles: ['ADMIN'] },
    { href: '/admin/servicios', label: 'Servicios', icon: Cog6ToothIcon, roles: ['ADMIN'] },
    { href: '/jefe/dashboard', label: 'Dashboard', icon: ChartBarIcon, roles: ['JEFE', 'ADMIN'] },
    { href: '/jefe/reportes', label: 'Reportes', icon: DocumentPlusIcon, roles: ['JEFE', 'ADMIN'] },
    { href: '/jefe/reportes/historial', label: 'Historial', icon: ArchiveBoxIcon, roles: ['JEFE', 'ADMIN'] },
    { href: '/acerca-de', label: 'Acerca de', icon: InformationCircleIcon, roles: ['OPERATOR_INGRESO', 'OPERATOR_ENTREGA', 'ADMIN', 'JEFE'] },
];

function Sidebar() {
    const [collapsed, toggle] = useSidebarCollapsed();
    const { user } = useAuth();
    const { url } = usePage();
    const visible = ITEMS.filter((i) => user && i.roles.includes(user.role));

    return (
        <aside
            id="sidebar"
            className={`flex flex-col border-r border-stone-200 bg-white transition-[width] duration-200 dark:border-white/10 dark:bg-wa-panel ${collapsed ? 'w-16' : 'w-64'}`}
        >
            <div className={`flex items-center p-3 ${collapsed ? 'justify-center' : 'justify-between'}`}>
                {collapsed ? (
                    <button
                        onClick={toggle}
                        aria-expanded={!collapsed}
                        aria-controls="sidebar"
                        className="rounded-lg hover:opacity-80"
                        title="Maximizar"
                    >
                        <img src="/android-chrome-192x192.png" alt="PlataformaRO" className="h-8 w-8" />
                    </button>
                ) : (
                    <>
                        <span className="flex items-center gap-2">
                            <img src="/android-chrome-192x192.png" alt="" className="h-8 w-8" />
                            <span className="font-bold text-primary-700 dark:text-primary-300">{APP_NAME}</span>
                        </span>
                        <button
                            onClick={toggle}
                            aria-expanded={!collapsed}
                            aria-controls="sidebar"
                            className="rounded p-1 text-stone-500 hover:bg-stone-100 dark:text-wa-muted dark:hover:bg-white/5"
                            title="Minimizar"
                        >
                            <ChevronLeftIcon className="h-5 w-5" />
                        </button>
                    </>
                )}
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
                                    ? 'bg-primary-100 text-primary-800 dark:bg-primary-900/50 dark:text-primary-200'
                                    : 'text-stone-600 hover:bg-stone-100 dark:text-wa-muted dark:hover:bg-white/5'
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
            <div className={`border-t border-stone-200 p-3 dark:border-white/10 ${collapsed ? 'text-center' : ''}`}>
                {collapsed ? (
                    <span className="text-[10px] text-stone-400" title={`${APP_NAME} © ${new Date().getFullYear()} · Todos los derechos reservados`}>©</span>
                ) : (
                    <>
                        <div className="text-xs font-semibold text-stone-700 dark:text-wa-text">{APP_NAME}</div>
                        <div className="text-[11px] text-stone-500 dark:text-wa-muted">© {new Date().getFullYear()} · Todos los derechos reservados</div>
                    </>
                )}
            </div>
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
                <header className="flex items-center justify-between border-b border-stone-200 bg-white px-4 py-2 dark:border-white/10 dark:bg-wa-panel">
                    <span className="text-sm text-stone-500">{user ? `${user.username} · ${user.role}` : ''}</span>
                    <div className="flex items-center gap-2">
                        <button onClick={toggleTheme} className="rounded p-1.5 text-stone-500 hover:bg-stone-100 dark:text-wa-muted dark:hover:bg-white/5" aria-label="Tema">
                            {dark ? <SunIcon className="h-5 w-5" /> : <MoonIcon className="h-5 w-5" />}
                        </button>
                        {user && (
                            <button onClick={logout} className="rounded p-1.5 text-stone-500 hover:bg-stone-100 dark:text-wa-muted dark:hover:bg-white/5" aria-label="Salir">
                                <ArrowRightOnRectangleIcon className="h-5 w-5" />
                            </button>
                        )}
                    </div>
                </header>
                <main className="relative flex-1 overflow-auto p-4">
                    <VehicleDoodles />
                    <div className="relative">{children}</div>
                </main>
            </div>
        </div>
    );
}
