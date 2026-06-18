@php
    /** @var bool $success */
    /** @var \App\Enums\Provider|null $provider */
    /** @var \App\Models\Connection|null $connection */
    /** @var string|null $reason */
    /** @var string $backUrl */
    /** @var bool $isConsumerReturn */

    $providerLabel = $provider?->getLabel() ?? 'de partner';

    // Filament-kleurnamen → hex (accent voor de provider-badge).
    $palette = [
        'success' => '#10b981',
        'danger' => '#ef4444',
        'info' => '#3b82f6',
        'warning' => '#f59e0b',
        'primary' => '#f59e0b',
        'gray' => '#6b7280',
    ];
    $accent = $provider ? ($palette[$provider->getColor()] ?? '#6b7280') : '#6b7280';

    $reasons = [
        'access_denied' => 'Je hebt de koppeling geweigerd in het scherm van '.$providerLabel.'.',
        'invalid_or_expired_state' => 'De sessie is verlopen of ongeldig. Start de koppeling opnieuw vanuit het admin-paneel.',
        'exchange_failed' => 'De token-uitwisseling met '.$providerLabel.' is mislukt. Probeer het later opnieuw.',
        'missing_parameters' => 'De callback van '.$providerLabel.' miste verplichte parameters.',
    ];
    $reasonText = $reasons[$reason] ?? 'Er ging iets mis bij het koppelen. Probeer het opnieuw.';

    $adminLabel = ['exact' => 'Division', 'snelstart' => 'Administratie'][$provider?->value] ?? 'Administratie';
@endphp
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $success ? 'Verbonden' : 'Koppeling mislukt' }} · emeq hub</title>
    <style>
        :root {
            --bg: #f9fafb;
            --card: #ffffff;
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
            --row: #f9fafb;
            --primary: #f59e0b;
            --primary-text: #422006;
            --ok: #10b981;
            --bad: #ef4444;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #030712;
                --card: #111827;
                --text: #f9fafb;
                --muted: #9ca3af;
                --border: #1f2937;
                --row: #0b1220;
                --primary-text: #1c1207;
            }
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Inter, sans-serif;
            background: var(--bg);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }
        .card {
            width: 100%;
            max-width: 440px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, .08), 0 8px 10px -6px rgba(0, 0, 0, .04);
            overflow: hidden;
        }
        .head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
        }
        .brand { display: flex; align-items: center; gap: 8px; }
        .brand .dot {
            width: 10px; height: 10px; border-radius: 50%;
            background: var(--primary);
        }
        .badge {
            font-size: 11px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 999px;
            color: {{ $accent }};
            background: color-mix(in srgb, {{ $accent }} 12%, transparent);
            border: 1px solid color-mix(in srgb, {{ $accent }} 35%, transparent);
        }
        .body { padding: 32px 28px 28px; text-align: center; }
        .icon {
            width: 64px; height: 64px;
            margin: 0 auto 20px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
        }
        .icon.ok { background: color-mix(in srgb, var(--ok) 14%, transparent); color: var(--ok); }
        .icon.bad { background: color-mix(in srgb, var(--bad) 14%, transparent); color: var(--bad); }
        .icon svg { width: 32px; height: 32px; }
        h1 { font-size: 20px; font-weight: 700; margin: 0 0 8px; letter-spacing: -.01em; }
        .sub { color: var(--muted); font-size: 14px; margin: 0 auto 24px; max-width: 340px; }
        .details {
            text-align: left;
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 24px;
        }
        .details .r {
            display: flex; justify-content: space-between; gap: 16px;
            padding: 11px 16px;
            font-size: 13px;
            border-bottom: 1px solid var(--border);
        }
        .details .r:last-child { border-bottom: 0; }
        .details .r:nth-child(odd) { background: var(--row); }
        .details .k { color: var(--muted); }
        .details .v { font-weight: 600; font-variant-numeric: tabular-nums; }
        .pill {
            font-size: 11px; font-weight: 600;
            padding: 2px 8px; border-radius: 999px;
            color: var(--ok);
            background: color-mix(in srgb, var(--ok) 14%, transparent);
        }
        .btn {
            display: inline-block;
            width: 100%;
            padding: 11px 18px;
            background: var(--primary);
            color: var(--primary-text);
            font-size: 14px; font-weight: 600;
            text-decoration: none;
            border-radius: 10px;
            transition: filter .15s ease;
        }
        .btn:hover { filter: brightness(.95); }
    </style>
</head>
<body>
    <main class="card">
        <div class="head">
            <span class="brand"><span class="dot"></span> emeq hub · Integraties</span>
            @if ($provider)
                <span class="badge">{{ $providerLabel }}</span>
            @endif
        </div>
        <div class="body">
            @if ($success)
                <div class="icon ok" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                </div>
                <h1>Verbonden met {{ $providerLabel }}</h1>
                <p class="sub">De koppeling is actief. Je kunt dit venster sluiten of teruggaan naar {{ $isConsumerReturn ? 'de app' : 'het paneel' }}.</p>

                <div class="details">
                    <div class="r"><span class="k">Status</span><span class="v"><span class="pill">{{ $connection->status }}</span></span></div>
                    <div class="r"><span class="k">Connection</span><span class="v">{{ \Illuminate\Support\Str::limit((string) $connection->id, 8, '…') }}</span></div>
                    @if ($connection->administratie_id)
                        <div class="r"><span class="k">{{ $adminLabel }}</span><span class="v">{{ $connection->administratie_id }}</span></div>
                    @endif
                    <div class="r"><span class="k">Verbonden op</span><span class="v">{{ $connection->updated_at?->format('d-m-Y H:i') }}</span></div>
                </div>

                <a class="btn" href="{{ $backUrl }}">{{ $isConsumerReturn ? 'Terug naar de app' : 'Terug naar Connections' }}</a>
            @else
                <div class="icon bad" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </div>
                <h1>Koppeling mislukt</h1>
                <p class="sub">{{ $reasonText }}</p>

                <a class="btn" href="{{ $backUrl }}">{{ $isConsumerReturn ? 'Terug naar de app' : 'Terug naar het admin-paneel' }}</a>
            @endif
        </div>
    </main>
</body>
</html>
