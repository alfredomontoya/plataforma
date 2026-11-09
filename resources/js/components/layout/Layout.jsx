import { useEffect, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import {
    Bars3Icon,
    ClipboardDocumentListIcon,
    UsersIcon,
    Cog6ToothIcon,
    ChartBarIcon,
    DocumentDuplicateIcon,
    DocumentPlusIcon,
    ArchiveBoxIcon,
    ChevronLeftIcon,
    InformationCircleIcon,
    MagnifyingGlassIcon,
    SunIcon,
    MoonIcon,
    ArrowRightOnRectangleIcon,
    ServerStackIcon,
    ComputerDesktopIcon,
    XMarkIcon,
    TagIcon,
} from '@heroicons/react/24/outline';
import { useMediaQuery, useSidebarCollapsed, useTheme } from '../../hooks/useUi';
import { useAuth } from '../../lib/auth';
import { APP_NAME } from '../../lib/app';
import EnvBadge from './EnvBadge';
import VehicleDoodles from './VehicleDoodles';

const SECTIONS = [
    {
        id: 'operacion',
        label: 'Operación',
        items: [
            { href: '/operador/entrada', label: 'Registro', icon: ClipboardDocumentListIcon, roles: ['OPERATOR_INGRESO', 'OPERATOR_ENTREGA', 'ADMIN', 'JEFE'] },
        ],
    },
    {
        id: 'analisis',
        label: 'Análisis',
        items: [
            { href: '/jefe/dashboard', label: 'Panel de control', icon: ChartBarIcon, roles: ['JEFE', 'ADMIN'] },
            // { href: '/jefe/reportes', label: 'Reportes', icon: DocumentPlusIcon, roles: ['JEFE', 'ADMIN'] },
            // { href: '/jefe/reportes/historial', label: 'Historial', icon: ArchiveBoxIcon, roles: ['JEFE', 'ADMIN'] },
            // { href: '/jefe/plantillas', label: 'Plantillas', icon: DocumentDuplicateIcon, roles: ['JEFE', 'ADMIN'] },
        ],
    },
    {
        id: 'administracion',
        label: 'Administración',
        items: [
            { href: '/admin/usuarios', label: 'Usuarios', icon: UsersIcon, roles: ['ADMIN'] },
            { href: '/admin/servicios', label: 'Servicios', icon: Cog6ToothIcon, roles: ['ADMIN'] },
            { href: '/admin/tipos-entrega', label: 'Tipos de entrega', icon: TagIcon, roles: ['ADMIN'] },
            { href: '/admin/backups', label: 'Copias de seguridad', icon: ServerStackIcon, roles: ['ADMIN'] },
        ],
    },
    {
        id: 'sistema',
        label: 'Sistema',
        items: [
            { href: '/sesiones', label: 'Sesiones', icon: ComputerDesktopIcon, roles: ['OPERATOR_INGRESO', 'OPERATOR_ENTREGA', 'ADMIN', 'JEFE'] },
            { href: '/acerca-de', label: 'Acerca de', icon: InformationCircleIcon, roles: ['OPERATOR_INGRESO', 'OPERATOR_ENTREGA', 'ADMIN', 'JEFE'] },
        ],
    },
];

const normalizeText = (value) => String(value ?? '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();

const escapeRegex = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const ACCENT_CLASS = {
    a: '[aáàäâã]',
    c: '[cç]',
    e: '[eéèëê]',
    i: '[iíìïî]',
    n: '[nñ]',
    o: '[oóòöôõ]',
    u: '[uúùüû]',
};

const highlightPattern = (query) => query.trim().split('').map((char) => {
    const base = normalizeText(char);
    return ACCENT_CLASS[base] || escapeRegex(char);
}).join('');

function SidebarPanel({ compact, mobile = false, onExpand, onCollapse, onNavigate }) {
    const { user } = useAuth();
    const { url } = usePage();
    const [query, setQuery] = useState('');
    const searchRef = useRef(null);
    const pendingFocus = useRef(false);

    const allowedItems = SECTIONS
        .flatMap((section) => section.items)
        .filter((item) => user && item.roles.includes(user.role));
    // Activo = coincidencia de prefijo más larga ("/jefe/reportes/historial"
    // ya no marca también "/jefe/reportes").
    const isPrefix = (href) => url === href || url.startsWith(`${href}/`);
    const activeHref = allowedItems.reduce(
        (best, item) => (isPrefix(item.href) && item.href.length > best.length ? item.href : best),
        ''
    );

    const search = normalizeText(query);
    const sections = SECTIONS
        .map((section) => {
            const items = section.items.filter((item) => user && item.roles.includes(user.role));
            if (!search) return { ...section, items };

            const groupMatches = normalizeText(section.label).includes(search);
            return {
                ...section,
                items: groupMatches
                    ? items
                    : items.filter((item) => normalizeText(item.label).includes(search)),
            };
        })
        .filter((section) => section.items.length > 0);

    const requestSearchFocus = () => {
        if (compact) {
            pendingFocus.current = true;
            onExpand?.();
            return;
        }
        searchRef.current?.focus();
    };

    useEffect(() => {
        if (!compact && pendingFocus.current) {
            pendingFocus.current = false;
            searchRef.current?.focus();
        }
    }, [compact]);

    // Atajo Ctrl/Cmd + K para buscar en el menú.
    useEffect(() => {
        const onKey = (event) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                requestSearchFocus();
            }
        };

        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [compact, onExpand]);

    const highlight = (label) => {
        if (!search) return label;

        const match = label.match(new RegExp(`(${highlightPattern(query)})`, 'i'));
        if (!match) return label;

        const start = match.index ?? 0;
        const end = start + match[0].length;

        return (
            <>
                {label.slice(0, start)}
                <mark className="rounded bg-amber-200 px-0.5 text-stone-900 dark:bg-amber-300/80">
                    {label.slice(start, end)}
                </mark>
                {label.slice(end)}
            </>
        );
    };

    return (
        <>
            <div className={`flex items-center p-3 ${compact ? 'justify-center' : 'justify-between'}`}>
                {compact ? (
                    <button
                        onClick={onExpand}
                        aria-expanded="false"
                        aria-controls="sidebar"
                        className="rounded-lg hover:opacity-80"
                        title="Expandir menú"
                        aria-label="Expandir menú"
                    >
                        <img src="/android-chrome-192x192.png" alt={APP_NAME} className="h-8 w-8" />
                    </button>
                ) : (
                    <>
                        <span className="flex min-w-0 items-center gap-2">
                            <img src="/android-chrome-192x192.png" alt="" className="h-8 w-8 shrink-0" />
                            <span className="truncate font-bold text-primary-700 dark:text-primary-300">{APP_NAME}</span>
                        </span>
                        <button
                            onClick={onCollapse}
                            aria-expanded="true"
                            aria-controls="sidebar"
                            className="shrink-0 rounded p-1 text-stone-500 hover:bg-stone-100 dark:text-wa-muted dark:hover:bg-white/5"
                            title={mobile ? 'Cerrar menú' : 'Minimizar'}
                            aria-label={mobile ? 'Cerrar menú' : 'Minimizar menú'}
                        >
                            {mobile ? <XMarkIcon className="h-5 w-5" /> : <ChevronLeftIcon className="h-5 w-5" />}
                        </button>
                    </>
                )}
            </div>

            <div className="px-2 pb-2">
                {compact ? (
                    <div className="flex justify-center">
                        <button
                            type="button"
                            onClick={requestSearchFocus}
                            className="rounded-lg p-2 text-stone-500 hover:bg-stone-100 dark:text-wa-muted dark:hover:bg-white/5"
                            title="Buscar en el menú (Ctrl+K)"
                            aria-label="Buscar en el menú"
                        >
                            <MagnifyingGlassIcon className="h-5 w-5" />
                        </button>
                    </div>
                ) : (
                    <div className="relative">
                        <MagnifyingGlassIcon className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-stone-400" />
                        <input
                            ref={searchRef}
                            type="text"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Escape' && query) {
                                    event.stopPropagation();
                                    setQuery('');
                                }
                            }}
                            placeholder="Buscar en el menú…"
                            aria-label="Buscar en el menú"
                            className="w-full rounded-lg border border-stone-200 bg-stone-50 py-1.5 pl-8 pr-8 text-sm text-stone-700 placeholder:text-stone-400 focus:border-primary-400 focus:outline-none focus:ring-2 focus:ring-primary-100 dark:border-white/10 dark:bg-white/5 dark:text-wa-text dark:placeholder:text-wa-muted dark:focus:border-primary-500 dark:focus:ring-primary-900/40"
                        />
                        {query && (
                            <button
                                type="button"
                                onClick={() => {
                                    setQuery('');
                                    searchRef.current?.focus();
                                }}
                                className="absolute right-1.5 top-1/2 -translate-y-1/2 rounded p-1 text-stone-400 hover:bg-stone-200 hover:text-stone-600 dark:hover:bg-white/10 dark:hover:text-wa-text"
                                aria-label="Limpiar búsqueda"
                                title="Limpiar búsqueda"
                            >
                                <XMarkIcon className="h-4 w-4" />
                            </button>
                        )}
                    </div>
                )}
            </div>

            <nav className="flex flex-1 flex-col gap-0.5 overflow-y-auto p-2">
                {sections.length === 0 && (
                    <div className="px-2 py-3 text-xs text-stone-500 dark:text-wa-muted">
                        <p>No se encontraron opciones para “{query.trim()}”.</p>
                        <button
                            type="button"
                            onClick={() => setQuery('')}
                            className="mt-1 font-medium text-primary-700 hover:underline dark:text-primary-400"
                        >
                            Limpiar búsqueda
                        </button>
                    </div>
                )}

                {sections.map((section, index) => (
                    <div
                        key={section.id}
                        className={index > 0
                            ? (compact ? 'mt-2 border-t border-stone-200 pt-2 dark:border-white/10' : 'mt-3')
                            : ''}
                    >
                        {compact ? (
                            <span className="sr-only">{section.label}</span>
                        ) : (
                            <div className="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-stone-400 dark:text-wa-muted">
                                {highlight(section.label)}
                            </div>
                        )}

                        {section.items.map((item) => {
                            const active = item.href === activeHref;
                            const Icon = item.icon;

                            return (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    onClick={() => {
                                        setQuery('');
                                        onNavigate?.();
                                    }}
                                    className={`group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm ${
                                        active
                                            ? 'bg-primary-100 text-primary-800 dark:bg-primary-900/50 dark:text-primary-200'
                                            : 'text-stone-600 hover:bg-stone-100 dark:text-wa-muted dark:hover:bg-white/5'
                                    } ${compact ? 'justify-center' : ''}`}
                                    aria-label={item.label}
                                    title={compact ? item.label : undefined}
                                >
                                    <Icon className="h-5 w-5 shrink-0" />
                                    {!compact && <span>{highlight(item.label)}</span>}
                                    {compact && (
                                        <span
                                            role="tooltip"
                                            className="pointer-events-none absolute left-full top-1/2 z-50 ml-2 hidden -translate-y-1/2 whitespace-nowrap rounded bg-stone-900 px-2 py-1 text-xs text-white group-hover:block group-focus:block"
                                        >
                                            {item.label}
                                        </span>
                                    )}
                                </Link>
                            );
                        })}
                    </div>
                ))}
            </nav>
            <div className={`border-t border-stone-200 p-3 dark:border-white/10 ${compact ? 'text-center' : ''}`}>
                <div className={`mb-2 ${compact ? 'flex justify-center' : ''}`}>
                    <EnvBadge collapsed={compact} />
                </div>
                {compact ? (
                    <span className="text-[10px] text-stone-400" title={`${APP_NAME} © ${new Date().getFullYear()} · Todos los derechos reservados`}>©</span>
                ) : (
                    <>
                        <div className="text-xs font-semibold text-stone-700 dark:text-wa-text">{APP_NAME}</div>
                        <div className="text-[11px] text-stone-500 dark:text-wa-muted">© {new Date().getFullYear()} · Todos los derechos reservados</div>
                    </>
                )}
            </div>
        </>
    );
}

export default function Layout({ children }) {
    const { user, loading, logout, otherSessions } = useAuth();
    const [dark, toggleTheme] = useTheme();
    const { url } = usePage();
    const isMobile = useMediaQuery('(max-width: 767px)');
    const isDesktop = useMediaQuery('(min-width: 1280px)');
    const [collapsed, toggleCollapsed] = useSidebarCollapsed();
    const [mobileOpen, setMobileOpen] = useState(false);
    const [tabletOpen, setTabletOpen] = useState(false);

    const overlayOpen = isMobile ? mobileOpen : !isDesktop && tabletOpen;

    const closeOverlay = () => {
        setMobileOpen(false);
        setTabletOpen(false);
    };

    // Cierra el menú al navegar.
    useEffect(() => {
        closeOverlay();
    }, [url]);

    // Cierra al cambiar de breakpoint para evitar estados huérfanos.
    useEffect(() => {
        closeOverlay();
    }, [isMobile, isDesktop]);

    // Escape + bloqueo del scroll de fondo cuando el menú se superpone.
    useEffect(() => {
        if (!overlayOpen) return;

        const onKey = (event) => {
            if (event.key === 'Escape') closeOverlay();
        };
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = previousOverflow;
        };
    }, [overlayOpen]);

    if (url.startsWith('/login')) return <>{children}</>;
    if (loading) {
        return (
            <div className="flex h-screen items-center justify-center">
                <span className="h-8 w-8 animate-spin rounded-full border-4 border-stone-300 border-t-primary-600 dark:border-t-primary-500" />
            </div>
        );
    }

    const outsideClass =
        'flex flex-col border-r border-stone-200 bg-white dark:border-white/10 dark:bg-wa-panel';
    const asideWidth = isDesktop
        ? (collapsed ? 'w-16' : 'w-64')
        : (tabletOpen ? 'w-64' : 'w-16');

    return (
        <div className="flex h-screen overflow-hidden">
            {user && (
                isMobile ? (
                    mobileOpen && (
                        <>
                            <div
                                className="fixed inset-0 z-40 bg-black/50"
                                onClick={closeOverlay}
                                aria-hidden="true"
                            />
                            <aside
                                id="sidebar"
                                className={`${outsideClass} fixed inset-y-0 left-0 z-50 w-72 max-w-[85vw] shadow-xl`}
                            >
                                <SidebarPanel
                                    compact={false}
                                    mobile
                                    onCollapse={closeOverlay}
                                    onNavigate={closeOverlay}
                                />
                            </aside>
                        </>
                    )
                ) : (
                    <>
                        {!isDesktop && tabletOpen && (
                            <div
                                className="fixed inset-0 z-40 bg-black/50"
                                onClick={closeOverlay}
                                aria-hidden="true"
                            />
                        )}
                        <aside
                            id="sidebar"
                            className={`${outsideClass} ${asideWidth} ${
                                !isDesktop && tabletOpen
                                    ? 'fixed inset-y-0 left-0 z-50 shadow-xl'
                                    : 'static'
                            }`}
                        >
                            <SidebarPanel
                                compact={isDesktop ? collapsed : !tabletOpen}
                                onExpand={() => (isDesktop ? toggleCollapsed() : setTabletOpen(true))}
                                onCollapse={() => (isDesktop ? toggleCollapsed() : setTabletOpen(false))}
                                onNavigate={() => setTabletOpen(false)}
                            />
                        </aside>
                    </>
                )
            )}
            <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
                <header className="flex items-center justify-between gap-2 border-b border-stone-200 bg-white px-3 py-2 dark:border-white/10 dark:bg-wa-panel sm:px-4">
                    <div className="flex min-w-0 items-center gap-2">
                        {isMobile && (
                            <button
                                onClick={() => setMobileOpen(true)}
                                className="rounded p-1.5 text-stone-500 hover:bg-stone-100 dark:text-wa-muted dark:hover:bg-white/5"
                                aria-label="Abrir menú"
                                aria-expanded={mobileOpen}
                                aria-controls="sidebar"
                            >
                                <Bars3Icon className="h-5 w-5" />
                            </button>
                        )}
                        <span className="truncate text-xs text-stone-500 sm:text-sm">
                            {user ? (
                                <>
                                    <span>{user.username}</span>
                                    <span className="hidden sm:inline"> · {user.role}</span>
                                </>
                            ) : ''}
                        </span>
                    </div>
                    <div className="flex shrink-0 items-center gap-1 sm:gap-2">
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
                {user && otherSessions > 0 && !url.startsWith('/sesiones') && (
                    <div className="flex items-center justify-between gap-3 border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-amber-400/20 dark:bg-amber-400/10 dark:text-amber-200">
                        <span>
                            Tienes {otherSessions} {otherSessions === 1 ? 'sesión activa' : 'sesiones activas'} en otros dispositivos.
                        </span>
                        <Link href="/sesiones" className="shrink-0 font-medium underline underline-offset-2">
                            Administrar
                        </Link>
                    </div>
                )}
                <main className="relative flex-1 overflow-auto p-3 sm:p-4 lg:p-6">
                    <VehicleDoodles />
                    <div className="relative">{children}</div>
                </main>
            </div>
        </div>
    );
}
