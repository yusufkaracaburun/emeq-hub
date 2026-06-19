<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f59e0b">
    <meta name="description" content="Eén Hub om je app te koppelen aan Nederlandse boekhoud- en betaal-API's: Exact Online, Mollie en SnelStart. OAuth, token-opslag, webhooks en audit-logging geregeld." inertia>

    {{-- Favicon + PWA --}}
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">

    {{-- Open Graph / Twitter — statische defaults; per-page <title> komt via Inertia. --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ config('app.name', 'Emeq Hub') }}">
    <meta property="og:locale" content="nl_NL">
    <meta property="og:title" content="Emeq Hub — integratieplatform voor NL boekhoud- en betaal-API's">
    <meta property="og:description" content="Eén REST-API: OAuth, multi-tenant token-opslag, webhooks en audit-logging voor Exact, Mollie en SnelStart.">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ url('/og-image.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Emeq Hub — integratieplatform voor NL boekhoud- en betaal-API's">
    <meta name="twitter:description" content="Eén REST-API: OAuth, token-opslag, webhooks en audit-logging voor Exact, Mollie en SnelStart.">
    <meta name="twitter:image" content="{{ url('/og-image.png') }}">

    {{-- Zet dark-mode vóór de eerste paint (geen flash). --}}
    <script>
        (function () {
            const a = localStorage.getItem('appearance') || 'system';
            const dark = a === 'dark' || (a === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>

    <title inertia>{{ config('app.name', 'Emeq Hub') }}</title>

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="min-h-screen bg-background text-foreground font-sans">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-primary-foreground focus:shadow-lg">
        Naar inhoud
    </a>
    @inertia
</body>
</html>
