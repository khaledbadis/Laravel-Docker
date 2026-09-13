import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const appUrl = new URL(env.APP_URL || 'http://localhost:8080');
    const browserPort = Number(env.VITE_PORT || 5173);

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
            }),
            tailwindcss(),
        ],
        server: {
            host: '0.0.0.0',
            port: 5173,
            strictPort: true,
            origin: `http://${appUrl.hostname}:${browserPort}`,
            cors: { origin: appUrl.origin },
            hmr: {
                host: appUrl.hostname,
                protocol: 'ws',
                clientPort: browserPort,
            },
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
