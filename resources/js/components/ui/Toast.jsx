import { createContext, useCallback, useContext, useState } from 'react';

const ToastContext = createContext(null);
let nextId = 1;

export function ToastProvider({ children }) {
    const [toasts, setToasts] = useState([]);
    const push = useCallback((type, message) => {
        const id = nextId++;
        setToasts((t) => [...t, { id, type, message }]);
        setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), 4500);
    }, []);
    return (
        <ToastContext.Provider value={{ toastSuccess: (m) => push('success', m), toastError: (m) => push('error', m) }}>
            {children}
            <div className="fixed bottom-4 right-4 z-50 flex flex-col gap-2">
                {toasts.map((t) => (
                    <div
                        key={t.id}
                        className={`card px-4 py-2 text-sm ${t.type === 'error' ? 'border-red-400 text-red-700 dark:text-red-300' : 'text-green-700 dark:text-green-300'}`}
                    >
                        {t.message}
                    </div>
                ))}
            </div>
        </ToastContext.Provider>
    );
}

export const useToast = () => useContext(ToastContext);
