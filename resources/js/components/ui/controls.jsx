import { useState } from 'react';
import { EyeIcon, EyeSlashIcon, MagnifyingGlassIcon, XMarkIcon } from '@heroicons/react/24/outline';

export function Button({ variant = 'primary', size = 'md', loading, disabled, className = '', ...props }) {
    const base = 'inline-flex items-center justify-center rounded-lg font-medium transition disabled:opacity-50';
    const variants = {
        primary: 'bg-primary-600 text-white hover:bg-primary-700 dark:bg-primary-600 dark:hover:bg-primary-500',
        secondary: 'border border-stone-300 hover:bg-stone-100 dark:border-white/10 dark:hover:bg-white/5',
        danger: 'bg-primary-600 text-white hover:bg-primary-700',
    };
    const sizes = { sm: 'px-2.5 py-1 text-sm', md: 'px-4 py-2 text-sm' };
    return (
        <button disabled={disabled || loading} className={`${base} ${variants[variant]} ${sizes[size]} ${className}`} {...props}>
            {loading && <span className="mr-2 h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white" />}
            {props.children}
        </button>
    );
}

export function Input({ label, error, helperText, ...props }) {
    return (
        <label className="block">
            {label && <span className="mb-1 block text-sm font-medium">{label}</span>}
            <input
                className="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-wa-header"
                {...props}
            />
            {error && <span className="mt-1 block text-xs text-primary-600 dark:text-primary-400">{error}</span>}
            {!error && helperText && <span className="mt-1 block text-xs text-stone-500">{helperText}</span>}
        </label>
    );
}

export function PasswordInput(props) {
    const [show, setShow] = useState(false);
    return (
        <div className="relative">
            <Input {...props} type={show ? 'text' : 'password'} />
            <button
                type="button"
                onClick={() => setShow(!show)}
                className="absolute right-2 top-8 text-stone-500"
                aria-label={show ? 'Ocultar' : 'Mostrar'}
            >
                {show ? <EyeSlashIcon className="h-5 w-5" /> : <EyeIcon className="h-5 w-5" />}
            </button>
        </div>
    );
}

export function Select({ label, options = [], error, placeholder, ...props }) {
    return (
        <label className="block">
            {label && <span className="mb-1 block text-sm font-medium">{label}</span>}
            <select
                className="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-wa-header"
                {...props}
            >
                {placeholder && <option value="">{placeholder}</option>}
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
            {error && <span className="mt-1 block text-xs text-primary-600 dark:text-primary-400">{error}</span>}
        </label>
    );
}

export function SearchInput({ value, onChange, onClear, placeholder = 'Buscar…', loading = false, className = '', ...props }) {
    return (
        <div className={`relative ${className}`}>
            <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-stone-400" />
            <input
                type="text"
                value={value}
                onChange={onChange}
                placeholder={placeholder}
                aria-label={props['aria-label'] || placeholder}
                aria-busy={loading || undefined}
                className="w-full rounded-lg border border-stone-300 bg-white py-2 pl-9 pr-9 text-sm outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-white/10 dark:bg-wa-header"
                {...props}
            />
            {loading ? (
                <span
                    aria-hidden="true"
                    className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin rounded-full border-2 border-stone-300 border-t-primary-600 dark:border-white/20 dark:border-t-primary-400"
                />
            ) : value ? (
                <button
                    type="button"
                    onClick={onClear}
                    aria-label="Limpiar búsqueda"
                    className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-stone-400 hover:bg-stone-100 hover:text-stone-600 dark:hover:bg-white/10 dark:hover:text-stone-200"
                >
                    <XMarkIcon className="h-4 w-4" />
                </button>
            ) : null}
        </div>
    );
}

export function DatePicker({ label, value, onChange, maxDate, ...props }) {
    return (
        <Input
            type="date"
            label={label}
            value={value}
            max={maxDate}
            onChange={(e) => onChange?.(e.target.value)}
            {...props}
        />
    );
}

export function Modal({ isOpen, onClose, title, size = 'md', children }) {
    if (!isOpen) return null;
    const widths = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-3xl' };
    return (
        <div className="fixed inset-0 z-40 flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4" onClick={onClose}>
            <div className={`card w-full ${widths[size]} max-h-[92vh] overflow-auto p-5`} onClick={(e) => e.stopPropagation()}>
                <div className="mb-4 flex items-center justify-between">
                    <h2 className="text-lg font-semibold">{title}</h2>
                    <button onClick={onClose} className="text-stone-500 hover:text-stone-800 dark:hover:text-stone-200" aria-label="Cerrar">
                        ✕
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}
