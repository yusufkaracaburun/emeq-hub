{{-- Dev-only index van partner-preview-pagina's. Niet via productie-routing. --}}
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Emeq Hub — Partner previews (dev)</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; max-width: 720px; margin: 3rem auto; padding: 0 1rem; color: #1f2937; line-height: 1.5; }
        h1 { font-size: 1.5rem; margin-bottom: 0.5rem; }
        .lede { color: #6b7280; font-size: 0.95rem; margin-bottom: 2rem; }
        ul { list-style: none; padding: 0; }
        li { border: 1px solid #e5e7eb; border-radius: 0.5rem; padding: 1rem 1.25rem; margin-bottom: 0.75rem; }
        li a { color: #d97706; text-decoration: none; font-weight: 600; }
        li a:hover { text-decoration: underline; }
        .meta { color: #6b7280; font-size: 0.85rem; margin-top: 0.25rem; }
        .missing { color: #9ca3af; font-style: italic; }
    </style>
</head>
<body>
    <h1>Partner previews</h1>
    <p class="lede">
        Voorbeeld-partnerpagina's voor certificerings- of partnership-aanvragen.
        Provider-set komt uit <code>config/hub-providers.php</code> — een nieuwe SDK
        verschijnt hier automatisch zodra de config-entry geland is.
    </p>

    <ul>
        @foreach ($providers as $provider)
            @php $view = "partners.{$provider}.example"; @endphp
            <li>
                @if (view()->exists($view))
                    <a href="{{ route('dev.partners.preview', $provider) }}">
                        {{ ucfirst($provider) }} — voorbeeldpagina
                    </a>
                    <div class="meta">{{ "resources/views/{$view}.blade.php" }}</div>
                @else
                    <span class="missing">{{ ucfirst($provider) }} — nog geen voorbeeldpagina</span>
                    <div class="meta">Maak <code>resources/views/partners/{{ $provider }}/example.blade.php</code></div>
                @endif
            </li>
        @endforeach
    </ul>
</body>
</html>
