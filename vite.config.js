import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    // El navegador debe cargar Vite desde el mismo host con el que entra a la
    // app (p. ej. http://splaadm23p-10:8084). Si Vite se anuncia como
    // localhost/[::1], un cliente remoto intenta cargar los assets desde su
    // propia máquina y el navegador lo bloquea por CORS.
    const env = loadEnv(mode, process.cwd(), '');
    let hmrHost = '';

    try {
        hmrHost = env.APP_URL ? new URL(env.APP_URL).hostname : '';
    } catch {
        hmrHost = '';
    }

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.jsx'],
                refresh: true,
            }),
            react(),
            tailwindcss(),
        ],
        server: {
            // Escucha en todas las interfaces (IPv4 e IPv6) para acceso por LAN.
            host: true,
            port: 5173,
            strictPort: true,
            cors: true,
            hmr: hmrHost ? { host: hmrHost } : undefined,
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
