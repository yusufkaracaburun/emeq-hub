@php
    /** @var bool $success */
    /** @var \App\Enums\Provider|null $provider */
    /** @var \App\Models\Connection|null $connection */
    /** @var string|null $reason */
    /** @var string $backUrl */
    /** @var bool $isConsumerReturn */

    $providerLabel = $provider?->getLabel() ?? 'de partner';

    $eyebrows = [
        'access_denied' => 'Geen toestemming gegeven',
        'invalid_or_expired_state' => 'Sessie verlopen',
    ];
    $eyebrow = $success ? 'Koppeling gelukt' : ($eyebrows[$reason] ?? 'Koppeling mislukt');

    $reasons = [
        'access_denied' => 'Je hebt bij '.$providerLabel.' geen toestemming gegeven, of de sessie is afgebroken. Er is niets gewijzigd en er zijn geen gegevens uitgewisseld.',
        'invalid_or_expired_state' => 'De sessie is verlopen of ongeldig. Start de koppeling opnieuw, je bent zo weer terug.',
        'exchange_failed' => 'De token-uitwisseling met '.$providerLabel.' is mislukt. Probeer het later opnieuw.',
        'missing_parameters' => 'De callback van '.$providerLabel.' miste verplichte parameters. Probeer het opnieuw.',
    ];
    $reasonText = $reasons[$reason] ?? 'Er ging iets mis bij het koppelen. Probeer het opnieuw.';

    $adminLabel = ['exact' => 'Division', 'snelstart' => 'Administratie'][$provider?->value] ?? 'Administratie';

    $ctaLabel = match (true) {
        $success && $isConsumerReturn => 'Terug naar de app',
        $success => 'Terug naar Connections',
        $isConsumerReturn => 'Opnieuw proberen',
        default => 'Terug naar het admin-paneel',
    };

    $hint = match (true) {
        $success && $isConsumerReturn => 'Je wordt automatisch teruggestuurd…',
        $success => 'Je kunt dit venster ook sluiten.',
        $reason === 'access_denied' => 'Wil je liever niet koppelen? Dan hoef je niets te doen: sluit dit venster.',
        default => 'Blijft het misgaan? Neem contact op via support@emeq.nl.',
    };
@endphp
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $success ? 'Verbonden' : 'Koppeling niet voltooid' }} · emeq hub</title>
    @if ($success && $isConsumerReturn)
        {{-- Geslaagde consumer-connect: na een korte bevestiging automatisch
             terug naar de app (de "Terug naar de app"-knop blijft als fallback). --}}
        <meta http-equiv="refresh" content="3;url={{ $backUrl }}">
    @endif
    <style>
        /* Design-tokens uit landingspage.pen — gelijk aan de publieke site. */
        :root {
            --background: #fafafa;
            --card: #ffffff;
            --foreground: #171717;
            --muted: #f5f5f5;
            --muted-foreground: #666666;
            --border: #ebebeb;
            --brand: #a23696;
            --primary: #171717;
            --primary-foreground: #ffffff;
            --success: #15803d;
            --success-soft: #dcfce7;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: var(--background);
            color: var(--foreground);
            display: flex;
            flex-direction: column;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }
        .mono { font-family: "IBM Plex Mono", ui-monospace, SFMono-Regular, Menlo, monospace; }
        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 24px;
        }
        .wordmark { display: flex; align-items: center; gap: 8px; }
        .wordmark img { height: 18px; width: auto; display: block; }
        .wordmark span { font-size: 24px; font-weight: 700; letter-spacing: -.3px; }
        .secure {
            display: flex; align-items: center; gap: 7px;
            background: var(--muted);
            border-radius: 999px;
            padding: 7px 12px;
            font-size: 13px;
            color: var(--muted-foreground);
        }
        .secure svg { width: 14px; height: 14px; }
        main {
            position: relative;
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 64px 24px;
            overflow: hidden;
        }
        /* Dotgrid — zelfde patroon als de hero's van de publieke site. */
        main::before {
            content: "";
            position: absolute;
            inset: 0;
            opacity: .3;
            background-image: radial-gradient(circle, #17171720 1px, transparent 1px);
            background-size: 24px 24px;
            -webkit-mask-image: linear-gradient(to bottom, black, transparent 85%);
            mask-image: linear-gradient(to bottom, black, transparent 85%);
            pointer-events: none;
        }
        .column {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 32px;
            width: 100%;
            max-width: 560px;
            text-align: center;
            animation: rise .5s cubic-bezier(.21, .47, .32, .98) both;
        }
        @keyframes rise { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
        @media (prefers-reduced-motion: reduce) { .column { animation: none; } }
        .handoff {
            display: flex; align-items: center; gap: 8px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 7px 14px;
            font-size: 13px;
        }
        .handoff .app { font-weight: 600; }
        .handoff .glyph { color: var(--muted-foreground); }
        .handoff img { height: 14px; width: auto; display: block; }
        .handoff .hub { font-weight: 700; }
        .state-icon {
            width: 56px; height: 56px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: var(--muted);
            color: var(--muted-foreground);
        }
        .state-icon.ok { background: var(--success-soft); color: var(--success); }
        .state-icon svg { width: 24px; height: 24px; }
        .header { display: flex; flex-direction: column; gap: 16px; align-items: center; }
        .eyebrow {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--muted-foreground);
        }
        h1 { font-size: 34px; font-weight: 700; letter-spacing: -1px; line-height: 1.15; margin: 0; }
        .sub { font-size: 16px; line-height: 1.55; color: var(--muted-foreground); max-width: 480px; margin: 0; }
        .details {
            width: 100%;
            max-width: 400px;
            text-align: left;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }
        .details .r {
            display: flex; justify-content: space-between; gap: 16px;
            padding: 11px 16px;
            font-size: 13px;
            border-bottom: 1px solid var(--border);
        }
        .details .r:last-child { border-bottom: 0; }
        .details .k { color: var(--muted-foreground); }
        .details .v { font-weight: 600; font-variant-numeric: tabular-nums; }
        .pill {
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1px;
            padding: 2px 8px; border-radius: 999px;
            color: var(--success);
            background: var(--success-soft);
        }
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 24px;
            background: var(--primary);
            color: var(--primary-foreground);
            font-size: 14px; font-weight: 600;
            text-decoration: none;
            border-radius: 8px;
            transition: opacity .15s ease;
        }
        .btn:hover { opacity: .9; }
        .hint { font-size: 13px; color: var(--muted-foreground); margin: 0; }
        footer {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
            gap: 16px 24px;
            border-top: 1px solid var(--border);
            padding: 28px 24px;
            font-size: 13px;
            color: var(--muted-foreground);
        }
        footer nav { display: flex; flex-wrap: wrap; gap: 24px; }
        footer a { color: inherit; text-decoration: none; }
        footer a:hover { color: var(--foreground); }
        @media (min-width: 768px) {
            header { padding: 18px 48px; }
            footer { padding: 28px 48px; }
            h1 { font-size: 38px; }
        }
    </style>
</head>
<body>
    <header>
        <div class="wordmark">
            <img src="{{ asset('img/logo.png') }}" alt="" aria-hidden="true">
            <span>hub</span>
        </div>
        <div class="secure">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
            <span>Beveiligde koppeling</span>
        </div>
    </header>

    <main>
        <div class="column">
            @if ($provider)
                <div class="handoff">
                    <span class="app">{{ $providerLabel }}</span>
                    <span class="glyph" aria-hidden="true">⇄</span>
                    <img src="{{ asset('img/logo.png') }}" alt="" aria-hidden="true">
                    <span class="hub">emeq hub</span>
                </div>
            @endif

            @if ($success)
                <div class="state-icon ok" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                </div>
            @else
                <div class="state-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/></svg>
                </div>
            @endif

            <div class="header">
                <p class="eyebrow mono">{{ $eyebrow }}</p>
                @if ($success)
                    <h1>Verbonden met {{ $providerLabel }}</h1>
                    <p class="sub">De koppeling is actief. {{ $isConsumerReturn ? 'Je wordt teruggestuurd naar de app…' : 'Je kunt dit venster sluiten of teruggaan naar het paneel.' }}</p>
                @else
                    <h1>De koppeling is niet voltooid</h1>
                    <p class="sub">{{ $reasonText }}</p>
                @endif
            </div>

            @if ($success)
                <div class="details">
                    <div class="r"><span class="k">Status</span><span class="v"><span class="pill">{{ $connection->status }}</span></span></div>
                    <div class="r"><span class="k">Connection</span><span class="v mono">{{ \Illuminate\Support\Str::limit((string) $connection->id, 8, '…') }}</span></div>
                    @if ($connection->administratie_id)
                        <div class="r"><span class="k">{{ $adminLabel }}</span><span class="v mono">{{ $connection->administratie_id }}</span></div>
                    @endif
                    <div class="r"><span class="k">Verbonden op</span><span class="v mono">{{ $connection->updated_at?->format('d-m-Y H:i') }}</span></div>
                </div>
            @endif

            <a class="btn" href="{{ $backUrl }}">{{ $ctaLabel }} <span aria-hidden="true">→</span></a>

            <p class="hint">{{ $hint }}</p>
        </div>
    </main>

    <footer>
        <p style="margin:0">© {{ now()->year }} emeq</p>
        <nav>
            <a href="{{ url('/partners') }}">Partners</a>
            <a href="{{ url('/privacy') }}">Privacy</a>
            <a href="{{ url('/voorwaarden') }}">Voorwaarden</a>
            <a href="{{ url('/verwerkersovereenkomst') }}">Verwerkersovereenkomst</a>
            <a href="{{ url('/support') }}">Support</a>
        </nav>
    </footer>
</body>
</html>
