import { usePage } from '@inertiajs/react';

/**
 * Badge de entorno (Desarrollo/Producción) con el nombre de la base de datos.
 * Datos desde las props compartidas de Inertia (disponible también en login).
 */
export default function EnvBadge({ collapsed = false, className = '' }) {
    const { env } = usePage().props;
    if (!env) return null;

    const isProd = !!env.isProduction;
    const label = isProd ? 'Producción' : 'Desarrollo';
    const title = `${label} · Base de datos: ${env.db || '—'}`;

    if (collapsed) {
        return (
            <span
                title={title}
                className={`mx-auto block h-2.5 w-2.5 rounded-full ${isProd ? 'bg-emerald-500' : 'bg-amber-500'} ${className}`}
            />
        );
    }

    return (
        <span
            title={title}
            className={`inline-flex max-w-full items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-semibold ${
                isProd
                    ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-300'
                    : 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-300'
            } ${className}`}
        >
            <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${isProd ? 'bg-emerald-500' : 'bg-amber-500'}`} />
            <span className="truncate">
                {label}
                {env.db ? ` · BD: ${env.db}` : ''}
            </span>
        </span>
    );
}
