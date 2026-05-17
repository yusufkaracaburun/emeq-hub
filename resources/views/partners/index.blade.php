{{-- Dev-only index van partner-preview-pagina's. Niet via productie-routing. --}}
{{-- Plan 08-05 — uitgebreid met domeinmodel-blokje + per-provider status-totaal (UI-SPEC §S3). --}}
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Emeq Hub — Partner previews (dev)</title>
    {{-- Vite-compiled Tailwind v4 utility-stylesheet leeft niet noodzakelijk in test/dev — --}}
    {{-- Tailwind-classes in HTML zijn semantisch correct ook zonder gebuilde stylesheet. --}}
</head>
<body class="max-w-3xl mx-auto px-4 py-12 text-gray-900 antialiased">
    <h1 class="text-3xl font-semibold leading-tight mb-2">Partner previews</h1>
    <p class="text-gray-500 text-base mb-8">
        Voorbeeld-partnerpagina's voor certificerings- of partnership-aanvragen.
        Provider-set komt uit <code>config/hub-providers.php</code> &mdash; een nieuwe SDK
        verschijnt hier automatisch zodra de config-entry geland is.
    </p>

    @include('partners.partials._domeinmodel')

    <ul class="space-y-3">
        @foreach ($providers as $provider)
            @php
                $view = "partners.{$provider}.example";
                $totals = app(\App\Services\PartnerStatus::class)->totalsForProvider($provider);
            @endphp
            <li class="border border-gray-200 rounded-lg p-4">
                @if (view()->exists($view))
                    <a href="{{ route('dev.partners.preview', $provider) }}"
                       class="text-amber-700 font-semibold no-underline hover:underline">
                        {{ ucfirst($provider) }} &mdash; voorbeeldpagina
                    </a>
                    <div class="text-sm text-gray-500 mt-1">
                        {{ ucfirst($provider) }}: {{ $totals['connected'] }}/{{ $totals['total'] }} Accounts gekoppeld
                    </div>
                    <div class="text-xs text-gray-400 mt-1">{{ "resources/views/{$view}.blade.php" }}</div>
                @else
                    <span class="text-gray-400 italic">{{ ucfirst($provider) }} &mdash; nog geen voorbeeldpagina</span>
                    <div class="text-xs text-gray-400 mt-1">Maak <code>resources/views/partners/{{ $provider }}/example.blade.php</code></div>
                @endif
            </li>
        @endforeach
    </ul>
</body>
</html>
