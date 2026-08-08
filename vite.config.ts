import path from 'node:path';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.tsx',
                'resources/css/filament/admin/theme.css',
            ],
            ssr: 'resources/js/ssr.tsx',
            refresh: true,
            // Self-hosted via de fonts-plugin — dit zijn de families die
            // resources/css/app.css declareert. Een externe stylesheet naar
            // fonts.bunny.net zou render-blocking zijn en een derde partij in het
            // kritieke pad zetten.
            fonts: [
                // preload staat uit. De default preload't élke subset-variant —
                // 38 onvoorwaardelijke font-downloads, waarvan het merendeel
                // cyrillisch/grieks/vietnamees is en op een NL-site nooit
                // rendert. De `subsets`-optie filtert dat bij deze provider niet
                // weg. De @font-face-regels staan inline in de head (1,5 kB
                // gzip), dus de browser ontdekt ze zonder extra roundtrip.
                bunny('Inter', {
                    weights: [400, 500, 600, 700],
                    preload: false,
                }),
                bunny('IBM Plex Mono', {
                    weights: [400, 500],
                    preload: false,
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
