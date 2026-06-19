<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Eén Hub om je app te koppelen aan Nederlandse boekhoud- en betaal-API's: Exact Online, Mollie en SnelStart. OAuth, token-opslag, webhooks en audit-logging geregeld." inertia>

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
    @inertia
</body>
</html>
