import {
    ChevronDoubleLeftIcon,
    ChevronDoubleRightIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
} from '@heroicons/react/24/outline';

export const PAGE_SIZE_OPTIONS = [10, 20, 50, 100];

/** Números visibles con elipsis cuando hay muchas páginas. */
const pageItems = (current, total) => {
    if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);

    const pages = new Set([1, total, current, current - 1, current + 1]);
    if (current <= 3) [2, 3, 4].forEach((p) => pages.add(p));
    if (current >= total - 2) [total - 1, total - 2, total - 3].forEach((p) => pages.add(p));

    const sorted = [...pages].filter((p) => p >= 1 && p <= total).sort((a, b) => a - b);
    const items = [];
    let previous = 0;
    for (const p of sorted) {
        if (previous && p - previous > 1) items.push(`gap-${p}`);
        items.push(p);
        previous = p;
    }

    return items;
};

function NavButton({ label, onClick, disabled, children, className = '' }) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            aria-label={label}
            title={label}
            className={`inline-flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-40 sm:h-8 sm:min-w-8 ${className}`}
        >
            {children}
        </button>
    );
}

/**
 * Paginación compacta con resumen, selector de tamaño y navegación numerada.
 * El consumidor controla `page`, `limit` y los datos paginados.
 */
export function Pagination({
    page = 1,
    totalPages = 1,
    total = 0,
    limit = 20,
    onPageChange,
    onLimitChange,
    pageSizeOptions = PAGE_SIZE_OPTIONS,
    disabled = false,
    className = '',
}) {
    const lastPage = Math.max(1, totalPages);
    const current = Math.min(Math.max(1, page), lastPage);
    const start = total === 0 ? 0 : (current - 1) * limit + 1;
    const end = Math.min(current * limit, total);
    const items = pageItems(current, lastPage);

    const go = (target) => {
        if (disabled || target === current || target < 1 || target > lastPage) return;
        onPageChange?.(target);
    };

    const navClass =
        'text-stone-600 hover:bg-stone-100 hover:text-stone-900 dark:text-wa-muted dark:hover:bg-white/10 dark:hover:text-wa-text';

    return (
        <div
            className={`flex flex-col gap-3 border-t border-stone-200 px-3 py-2.5 dark:border-white/10 sm:flex-row sm:items-center sm:justify-between ${className}`}
        >
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-stone-500 dark:text-wa-muted">
                <span aria-live="polite">
                    {total === 0 ? (
                        'Sin registros'
                    ) : (
                        <>
                            Mostrando{' '}
                            <span className="font-semibold tabular-nums text-stone-700 dark:text-wa-text">
                                {start}–{end}
                            </span>{' '}
                            de <span className="font-semibold tabular-nums text-stone-700 dark:text-wa-text">{total}</span>{' '}
                            {total === 1 ? 'registro' : 'registros'}
                        </>
                    )}
                </span>

                <label className="inline-flex items-center gap-1.5">
                    <span>Por página</span>
                    <select
                        value={limit}
                        disabled={disabled}
                        onChange={(e) => onLimitChange?.(Number(e.target.value))}
                        aria-label="Registros por página"
                        className="h-8 rounded-lg border border-stone-300 bg-white px-1.5 pr-6 text-xs font-medium text-stone-700 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 disabled:opacity-50 dark:border-white/10 dark:bg-wa-header dark:text-wa-text"
                    >
                        {pageSizeOptions.map((size) => (
                            <option key={size} value={size}>
                                {size}
                            </option>
                        ))}
                    </select>
                </label>
            </div>

            <nav aria-label="Paginación de registros" className="flex items-center justify-between gap-1 sm:justify-end">
                <span className="text-xs text-stone-500 dark:text-wa-muted sm:hidden">
                    Página <span className="font-semibold tabular-nums text-stone-700 dark:text-wa-text">{current}</span> de{' '}
                    <span className="font-semibold tabular-nums text-stone-700 dark:text-wa-text">{lastPage}</span>
                </span>

                <div className="flex items-center gap-0.5">
                    <NavButton label="Primera página" onClick={() => go(1)} disabled={disabled || current <= 1} className={`hidden sm:inline-flex ${navClass}`}>
                        <ChevronDoubleLeftIcon className="h-4 w-4" />
                    </NavButton>
                    <NavButton label="Página anterior" onClick={() => go(current - 1)} disabled={disabled || current <= 1} className={navClass}>
                        <ChevronLeftIcon className="h-4 w-4" />
                    </NavButton>

                    <div className="hidden items-center gap-0.5 sm:flex">
                        {items.map((item) =>
                            typeof item === 'number' ? (
                                <button
                                    key={item}
                                    type="button"
                                    onClick={() => go(item)}
                                    disabled={disabled}
                                    aria-label={`Página ${item}`}
                                    aria-current={item === current ? 'page' : undefined}
                                    className={`inline-flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-medium tabular-nums transition disabled:cursor-not-allowed disabled:opacity-50 sm:h-8 sm:min-w-8 ${
                                        item === current
                                            ? 'bg-primary-600 text-white shadow-sm dark:bg-primary-600'
                                            : `${navClass}`
                                    }`}
                                >
                                    {item}
                                </button>
                            ) : (
                                <span key={item} aria-hidden="true" className="px-1 text-stone-400 dark:text-wa-muted">
                                    …
                                </span>
                            ),
                        )}
                    </div>

                    <NavButton label="Página siguiente" onClick={() => go(current + 1)} disabled={disabled || current >= lastPage} className={navClass}>
                        <ChevronRightIcon className="h-4 w-4" />
                    </NavButton>
                    <NavButton label="Última página" onClick={() => go(lastPage)} disabled={disabled || current >= lastPage} className={`hidden sm:inline-flex ${navClass}`}>
                        <ChevronDoubleRightIcon className="h-4 w-4" />
                    </NavButton>
                </div>
            </nav>
        </div>
    );
}

export default Pagination;
