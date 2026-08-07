import path from 'node:path';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/filament/admin/theme.css'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': path.resolve(import.meta.dirname, 'resources/js'),
        },
    },
    server: {
        host: '0.0.0.0',
        port: 5173,
        // Browser bereikt de dev-server op localhost:5173 (gepubliceerde poort);
        // de container luistert op 0.0.0.0. usePolling = betrouwbare FS-events
        // op macOS-bind-mounts.
        hmr: { host: 'localhost' },
        watch: {
            // usePolling = betrouwbare FS-events op macOS-bind-mounts. Polling stat't
            // élk niet-genegeerd bestand per interval; vendor/ (42k) + storage zonder
            // ignore = 100% CPU. interval verlaagt de poll-frequentie, ignored sluit
            // de zware dirs uit. Vite negeert node_modules/.git al by default.
            usePolling: true,
            interval: 1000,
            binaryInterval: 1500,
            ignored: [
                '**/vendor/**',
                '**/storage/**',
                '**/bootstrap/cache/**',
                '**/public/build/**',
                '**/.git/**',
            ],
        },
    },
});
