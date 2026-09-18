import { createContext, useCallback, useContext, useState } from 'react';
import { XMarkIcon } from '@heroicons/react/24/outline';

const ToastContext = createContext(null);
let nextId = 1;

/**
 * Toasts arriba al centro con cierre manual.
 * Mensaje string (compat) o {title, description} para confirmaciones.
 */
export function ToastProvider({ children }) {
    const [toasts, setToasts] = useState([]);
    const dismiss = useCallback((id) => {
        setToasts((t) => t.filter((x) => x.id !== id));
    }, []);
    const push = useCallback((type, message, duration = 6000) => {
        const id = nextId++;
        const structured = message && typeof message === 'object';
        setToasts((t) => [...t, {
            id,
            type,
            title: structured ? message.title : null,
            text: structured ? message.description : String(message ?? ''),
        }]);
        setTimeout(() => dismiss(id), duration);
    }, [dismiss]);
    return (
        <ToastContext.Provider value={{ toastSuccess: (m, d) => push('success', m, d), toastError: (m, d) => push('error', m, d) }}>
            {children}
            <div className="fixed left-1/2 top-4 z-50 flex w-full max-w-md -translate-x-1/2 flex-col gap-2 px-4">
                {toasts.map((t) => (
                    <div
                        key={t.id}
                        role="status"
                        className={`card flex items-start gap-3 px-4 py-3 shadow-lg ${t.type === 'error' ? 'border-primary-400' : 'border-green-500'}`}
                    >
                        <span className={`mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ${t.type === 'error' ? 'bg-primary-600' : 'bg-green-600'}`} />
                        <div className="min-w-0 flex-1">
                            {t.title && <div className="text-sm font-semibold">{t.title}</div>}
                            {t.text && (
                                <div className={`text-sm ${t.type === 'error' ? 'text-primary-700 dark:text-primary-300' : 'text-stone-600 dark:text-wa-muted'}`}>
                                    {t.text}
                                </div>
                            )}
                        </div>
                        <button
                            onClick={() => dismiss(t.id)}
                            aria-label="Cerrar aviso"
                            className="rounded p-0.5 text-stone-400 hover:bg-stone-100 hover:text-stone-600 dark:hover:bg-white/10 dark:hover:text-wa-text"
                        >
                            <XMarkIcon className="h-4 w-4" />
                        </button>
                    </div>
                ))}
            </div>
        </ToastContext.Provider>
    );
}

export const useToast = () => useContext(ToastContext);
