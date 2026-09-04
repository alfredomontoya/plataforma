import './bootstrap';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { AuthProvider } from './lib/auth';
import { ToastProvider } from './components/ui/Toast';
import Layout from './components/layout/Layout';

const pages = import.meta.glob('./pages/**/*.jsx', { eager: true });

const savedTheme = localStorage.getItem('theme');
if (savedTheme === 'dark' || (!savedTheme && matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.classList.add('dark');
}

createInertiaApp({
    resolve: (name) => {
        const page = pages[`./pages/${name}.jsx`];
        if (!page) throw new Error(`Página no encontrada: ${name}`);
        page.default.layout ??= (children) => <Layout>{children}</Layout>;
        return page;
    },
    setup({ el, App, props }) {
        createRoot(el).render(
            <AuthProvider>
                <ToastProvider>
                    <App {...props} />
                </ToastProvider>
            </AuthProvider>,
        );
    },
});
