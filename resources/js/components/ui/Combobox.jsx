import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { CheckIcon, ChevronUpDownIcon, XMarkIcon } from '@heroicons/react/24/outline';

const normalize = (value) =>
    String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();

/**
 * Select con búsqueda (combobox accesible y reutilizable).
 *
 * - Filtra por `label`, `description` y `keywords` (sin distinguir tildes/mayúsculas).
 * - Navegación con flechas ↑/↓, selección con Enter o clic, cierre con Escape.
 * - `onChange` recibe directamente el `value` de la opción elegida.
 *
 * options: [{ value, label, description?, keywords? }]
 */
export function Combobox({
    label,
    value,
    onChange,
    options = [],
    placeholder = 'Buscar…',
    error,
    helperText,
    disabled = false,
    emptyMessage = 'Sin resultados.',
    id,
    name,
    className = '',
}) {
    const generatedId = useId();
    const inputId = id || `combobox-${generatedId}`;
    const listboxId = `${inputId}-listbox`;

    const rootRef = useRef(null);
    const inputRef = useRef(null);
    const listRef = useRef(null);

    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [highlighted, setHighlighted] = useState(-1);

    const selected = useMemo(
        () => options.find((option) => option.value === value) ?? null,
        [options, value],
    );

    const filtered = useMemo(() => {
        const q = normalize(query);
        if (!q) return options;

        return options.filter((option) =>
            normalize(`${option.label} ${option.description ?? ''} ${option.keywords ?? ''}`).includes(q),
        );
    }, [options, query]);

    // Mantiene visible la opción resaltada dentro de la lista.
    useEffect(() => {
        if (!open || highlighted < 0) return;
        const node = listRef.current?.querySelector(`[data-index="${highlighted}"]`);
        node?.scrollIntoView({ block: 'nearest' });
    }, [open, highlighted, filtered.length]);

    // Cierra al hacer clic fuera.
    useEffect(() => {
        if (!open) return undefined;
        const onPointerDown = (event) => {
            if (rootRef.current && !rootRef.current.contains(event.target)) {
                setOpen(false);
                setQuery('');
            }
        };
        document.addEventListener('mousedown', onPointerDown);
        return () => document.removeEventListener('mousedown', onPointerDown);
    }, [open]);

    const openList = () => {
        if (disabled) return;
        const index = options.findIndex((option) => option.value === value);
        setOpen(true);
        setQuery('');
        setHighlighted(index >= 0 ? index : options.length > 0 ? 0 : -1);
    };

    const closeList = () => {
        setOpen(false);
        setQuery('');
        setHighlighted(-1);
    };

    const choose = (option) => {
        if (!option) return;
        onChange?.(option.value);
        closeList();
    };

    const handleKeyDown = (event) => {
        if (disabled) return;

        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                if (!open) {
                    openList();
                } else {
                    setHighlighted((i) => Math.min(filtered.length - 1, i + 1));
                }
                break;

            case 'ArrowUp':
                event.preventDefault();
                if (!open) {
                    openList();
                } else {
                    setHighlighted((i) => Math.max(0, i - 1));
                }
                break;

            case 'Home':
                if (open) {
                    event.preventDefault();
                    setHighlighted(0);
                }
                break;

            case 'End':
                if (open) {
                    event.preventDefault();
                    setHighlighted(filtered.length - 1);
                }
                break;

            case 'Enter':
                if (open) {
                    event.preventDefault();
                    choose(filtered[highlighted] ?? filtered[0]);
                }
                break;

            case 'Escape':
                if (open) {
                    event.preventDefault();
                    closeList();
                }
                break;

            case 'Tab':
                closeList();
                break;

            default:
                break;
        }
    };

    const handleChange = (event) => {
        if (!open) setOpen(true);
        setQuery(event.target.value);
        setHighlighted(0);
    };

    const clear = () => {
        onChange?.('');
        setQuery('');
        setHighlighted(-1);
    };

    const displayValue = open ? query : (selected?.label ?? '');
    const activeOption = open ? filtered[highlighted] : null;
    const showClear = !!selected && !disabled;

    return (
        <div ref={rootRef} className={`relative ${className}`}>
            {label && (
                <label htmlFor={inputId} className="mb-1 block text-sm font-medium">
                    {label}
                </label>
            )}

            <div className="relative">
                <input
                    ref={inputRef}
                    id={inputId}
                    name={name}
                    type="text"
                    role="combobox"
                    autoComplete="off"
                    aria-expanded={open}
                    aria-controls={listboxId}
                    aria-autocomplete="list"
                    aria-activedescendant={activeOption ? `${listboxId}-${highlighted}` : undefined}
                    aria-invalid={!!error}
                    aria-required
                    disabled={disabled}
                    value={displayValue}
                    placeholder={placeholder}
                    onFocus={openList}
                    onChange={handleChange}
                    onKeyDown={handleKeyDown}
                    className={`w-full rounded-lg border bg-white px-3 py-2 pr-16 text-sm outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 disabled:opacity-50 dark:bg-wa-header ${
                        error
                            ? 'border-primary-500'
                            : 'border-stone-300 dark:border-white/10'
                    }`}
                />

                <div className="absolute inset-y-0 right-0 flex items-center gap-0.5 pr-2">
                    {showClear && (
                        <button
                            type="button"
                            tabIndex={-1}
                            aria-label="Limpiar selección"
                            onMouseDown={(event) => event.preventDefault()}
                            onClick={clear}
                            className="rounded p-1 text-stone-400 hover:bg-stone-100 hover:text-stone-600 dark:hover:bg-white/10 dark:hover:text-stone-200"
                        >
                            <XMarkIcon className="h-4 w-4" />
                        </button>
                    )}
                    <button
                        type="button"
                        tabIndex={-1}
                        aria-label={open ? 'Cerrar lista' : 'Abrir lista'}
                        disabled={disabled}
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={() => {
                            if (open) {
                                closeList();
                                return;
                            }
                            openList();
                            inputRef.current?.focus();
                        }}
                        className="rounded p-1 text-stone-400 hover:bg-stone-100 hover:text-stone-600 dark:hover:bg-white/10 dark:hover:text-stone-200"
                    >
                        <ChevronUpDownIcon className="h-4 w-4" />
                    </button>
                </div>
            </div>

            {open && (
                <ul
                    ref={listRef}
                    id={listboxId}
                    role="listbox"
                    aria-label={label}
                    className="absolute z-30 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-stone-200 bg-white py-1 shadow-lg dark:border-white/10 dark:bg-wa-panel"
                >
                    {filtered.length === 0 ? (
                        <li className="px-3 py-2 text-sm text-stone-500 dark:text-wa-muted">{emptyMessage}</li>
                    ) : (
                        filtered.map((option, index) => {
                            const isSelected = option.value === value;
                            const isActive = index === highlighted;

                            return (
                                <li
                                    key={option.value}
                                    id={`${listboxId}-${index}`}
                                    role="option"
                                    aria-selected={isSelected}
                                    data-index={index}
                                    onMouseDown={(event) => event.preventDefault()}
                                    onMouseEnter={() => setHighlighted(index)}
                                    onClick={() => choose(option)}
                                    className={`flex cursor-pointer items-start gap-2 px-3 py-2 text-sm ${
                                        isActive
                                            ? 'bg-primary-50 text-primary-800 dark:bg-white/10 dark:text-white'
                                            : 'text-stone-700 dark:text-stone-200'
                                    }`}
                                >
                                    <span className="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center">
                                        {isSelected && <CheckIcon className="h-4 w-4 text-primary-600 dark:text-primary-400" />}
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block truncate font-medium">{option.label}</span>
                                        {option.description && (
                                            <span className="block truncate text-xs text-stone-500 dark:text-wa-muted">
                                                {option.description}
                                            </span>
                                        )}
                                    </span>
                                </li>
                            );
                        })
                    )}
                </ul>
            )}

            {error && <span className="mt-1 block text-xs text-primary-600 dark:text-primary-400">{error}</span>}
            {!error && helperText && <span className="mt-1 block text-xs text-stone-500">{helperText}</span>}
        </div>
    );
}
