import { useEffect, useMemo, useRef, useState } from 'react';

/**
 * Búsqueda mientras se escribe, sin saturar al servidor.
 *
 * - `input` es el valor inmediato (lo que ve el usuario en el campo).
 * - `query` es el valor recortado que se estabiliza tras `delay` ms.
 * - `pending` indica que hay una búsqueda esperando a aplicarse.
 * - `onCommit(query)` se ejecuta al aplicarse la búsqueda; útil para volver a
 *   la primera página en el mismo render y evitar una petición intermedia.
 *
 * Con `input` vacío, `query` también queda vacío: la pantalla limpia el filtro
 * en lugar de buscar por cadena vacía.
 *
 * @param {string} initialValue
 * @param {{ delay?: number, onCommit?: (query: string) => void }} [options]
 */
export function useDebouncedSearch(initialValue = '', { delay = 300, onCommit } = {}) {
    const [input, setInput] = useState(initialValue);
    const [query, setQuery] = useState(() => initialValue.trim());
    const onCommitRef = useRef(onCommit);

    useEffect(() => {
        onCommitRef.current = onCommit;
    }, [onCommit]);

    useEffect(() => {
        const timer = setTimeout(() => {
            const next = input.trim();
            setQuery((previous) => (previous === next ? previous : next));
            onCommitRef.current?.(next);
        }, delay);

        return () => clearTimeout(timer);
    }, [input, delay]);

    const pending = useMemo(() => input.trim() !== query, [input, query]);

    const clear = () => setInput('');

    return { input, setInput, query, pending, clear };
}
