<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#f59e0b">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <title>@yield('title') · emeq hub</title>
    <style>
        :root {
            --bg: #f9fafb; --card: #ffffff; --text: #111827; --muted: #6b7280;
            --border: #e5e7eb; --primary: #f59e0b; --primary-text: #422006;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #030712; --card: #111827; --text: #f9fafb; --muted: #9ca3af;
                --border: #1f2937; --primary-text: #1c1207;
            }
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg);
            background-image:
                radial-gradient(40rem 40rem at 15% -12%, color-mix(in srgb, var(--primary) 16%, transparent), transparent 60%),
                radial-gradient(34rem 34rem at 90% 0%, color-mix(in srgb, var(--primary) 9%, transparent), transparent 62%);
            color: var(--text);
            display: flex; align-items: center; justify-content: center;
            padding: 24px; line-height: 1.5; -webkit-font-smoothing: antialiased;
        }
        .card {
            width: 100%; max-width: 440px; background: var(--card);
            border: 1px solid var(--border); border-radius: 20px;
            box-shadow: 0 24px 50px -12px rgba(0,0,0,.20), 0 8px 16px -8px rgba(0,0,0,.08);
            overflow: hidden;
            animation: rise .5s cubic-bezier(.21,.47,.32,.98) both;
        }
        @keyframes rise { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
        @media (prefers-reduced-motion: reduce) { .card { animation: none !important; } }
        .head {
            display: flex; align-items: center; gap: 8px;
            padding: 14px 20px; border-bottom: 1px solid var(--border);
            font-size: 13px; font-weight: 600; color: var(--muted);
        }
        .dot { width: 10px; height: 10px; border-radius: 50%; background: var(--primary); }
        .body { padding: 36px 28px 28px; text-align: center; }
        .code {
            font-size: 64px; font-weight: 700; letter-spacing: -.03em; line-height: 1;
            background: linear-gradient(to right, #fbbf24, #f59e0b);
            -webkit-background-clip: text; background-clip: text; color: transparent;
            font-variant-numeric: tabular-nums; margin-bottom: 12px;
        }
        h1 { font-size: 20px; font-weight: 700; margin: 0 0 8px; letter-spacing: -.01em; }
        .sub { color: var(--muted); font-size: 14px; margin: 0 auto 24px; max-width: 340px; }
        .btn {
            display: inline-block; padding: 12px 22px;
            background: linear-gradient(180deg, color-mix(in srgb, var(--primary) 92%, white), var(--primary));
            color: var(--primary-text); font-size: 14px; font-weight: 600;
            text-decoration: none; border-radius: 12px;
            box-shadow: 0 8px 18px -8px color-mix(in srgb, var(--primary) 70%, transparent);
            transition: filter .15s ease, transform .15s ease;
        }
        .btn:hover { filter: brightness(.97); transform: translateY(-1px); }
    </style>
</head>
<body>
    <main class="card">
        <div class="head"><span class="dot"></span> emeq hub</div>
        <div class="body">
            <div class="code">@yield('code')</div>
            <h1>@yield('title')</h1>
            <p class="sub">@yield('message')</p>
            <a class="btn" href="{{ url('/') }}">Terug naar home</a>
        </div>
    </main>
</body>
</html>
