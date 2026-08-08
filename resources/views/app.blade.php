<!DOCTYPE html>
<html lang="nl" class="h-full">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />

        {{-- Titel, description, canonical, OpenGraph en JSON-LD komen uit
             App\Support\Seo\SeoMeta via de <Seo>-component (@inertiaHead).
             Hier alleen wat op elke pagina identiek is. --}}
        <link rel="icon" href="/favicon.ico" sizes="32x32" />
        <link rel="icon" href="/favicon.svg" type="image/svg+xml" />
        <link rel="apple-touch-icon" href="/apple-touch-icon.png" />
        <link rel="manifest" href="/site.webmanifest" />

        {{-- Self-hosted Inter + IBM Plex Mono (vite.config.ts → fonts). Rendert
             preload-links + inline @font-face; vervangt de render-blocking
             stylesheet naar fonts.bunny.net. --}}
        {{ Vite::fonts() }}

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body class="h-full">
        @inertia
    </body>
</html>
