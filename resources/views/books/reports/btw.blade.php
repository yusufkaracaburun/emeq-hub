@php
    $eur = static fn (int $cents): string => '€ '.number_format($cents / 100, 2, ',', '.');
    $fmtDate = static fn (string $d): string => \Illuminate\Support\Carbon::parse($d)->format('d-m-Y');

    $start = $declaration['period']['start'];
    $end = $declaration['period']['end'];
    $saldo = $declaration['saldo'];
    $terugvragen = $saldo < 0;

    $labels = [
        '1a' => 'Leveringen/diensten belast met hoog tarief (21%)',
        '1b' => 'Leveringen/diensten belast met laag tarief (9%)',
        '1e' => 'Leveringen/diensten belast met 0% of niet bij u belast',
    ];
@endphp
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 70px 50px 80px 50px; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 11px; color: #1f2937; margin: 0; }

        .head { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
        .head td { vertical-align: top; }
        .issuer-name { font-size: 16px; font-weight: bold; color: #111827; }
        .muted { color: #6b7280; }
        h1.title { font-size: 18px; color: #111827; margin: 0; }
        .period { color: #6b7280; margin-top: 2px; }

        .section { margin-top: 24px; }
        h2.section-title {
            font-size: 13px; color: #111827; margin: 0 0 6px 0;
            border-bottom: 1.5px solid #111827; padding-bottom: 6px;
        }

        table.stmt { width: 100%; border-collapse: collapse; }
        table.stmt th {
            font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em;
            color: #6b7280; text-align: left; padding: 4px 8px; border-bottom: 1px solid #d1d5db;
        }
        table.stmt th.amount, table.stmt td.amount { text-align: right; }
        table.stmt td { padding: 6px 8px; border-bottom: 1px solid #eef0f3; }
        .code { color: #9ca3af; width: 36px; }
        tr.subtotal td { border-top: 1px solid #d1d5db; border-bottom: none; font-weight: bold; color: #111827; }
        tr.grand td {
            border-top: 1.5px solid #111827; border-bottom: none;
            font-weight: bold; font-size: 13px; color: #111827; padding-top: 8px;
        }
        .badge {
            display: inline-block; font-size: 9px; font-weight: bold; padding: 2px 8px;
            border-radius: 8px; margin-left: 8px;
        }
        .badge-pay { background: #fef2f2; color: #b91c1c; }
        .badge-back { background: #ecfdf5; color: #047857; }

        .note { margin-top: 18px; font-size: 9px; color: #9ca3af; }

        .footer {
            position: fixed; bottom: -50px; left: 0; right: 0; text-align: center;
            font-size: 9px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 8px;
        }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <h1 class="title">BTW-aangifte</h1>
                <div class="period">{{ $fmtDate($start) }} t/m {{ $fmtDate($end) }}</div>
            </td>
            <td style="text-align: right;">
                <div class="issuer-name">{{ $issuer['name'] }}</div>
                @if ($issuer['vat_number'])<div class="muted">BTW {{ $issuer['vat_number'] }}</div>@endif
            </td>
        </tr>
    </table>

    {{-- Rubriek 1: prestaties binnenland --}}
    <div class="section">
        <h2 class="section-title">Rubriek 1 — Prestaties binnenland</h2>
        <table class="stmt">
            <tr>
                <th class="code">Nr</th>
                <th>Omschrijving</th>
                <th class="amount">Grondslag</th>
                <th class="amount">Omzetbelasting</th>
            </tr>
            @foreach ($declaration['rubrieken'] as $code => $rubriek)
                <tr>
                    <td class="code">{{ $code }}</td>
                    <td>{{ $labels[$code] ?? $code }}</td>
                    <td class="amount">{{ $eur($rubriek['grondslag']) }}</td>
                    <td class="amount">{{ $eur($rubriek['btw']) }}</td>
                </tr>
            @endforeach
        </table>
    </div>

    {{-- Rubriek 5: voorbelasting + saldo --}}
    <div class="section">
        <h2 class="section-title">Rubriek 5 — Voorbelasting en eindtotaal</h2>
        <table class="stmt">
            <tr>
                <td class="code">5a</td>
                <td>Verschuldigde omzetbelasting</td>
                <td class="amount">{{ $eur($declaration['verschuldigd']) }}</td>
            </tr>
            <tr>
                <td class="code">5b</td>
                <td>Voorbelasting</td>
                <td class="amount">{{ $eur($declaration['voorbelasting']) }}</td>
            </tr>
            <tr class="grand">
                <td colspan="2">
                    {{ $terugvragen ? 'Terug te vragen' : 'Te betalen' }}
                    <span class="badge {{ $terugvragen ? 'badge-back' : 'badge-pay' }}">{{ $terugvragen ? 'terug' : 'betalen' }}</span>
                </td>
                <td class="amount">{{ $eur(abs($saldo)) }}</td>
            </tr>
        </table>
    </div>

    <div class="note">
        Rubriek 2 (verleggingsregelingen), 3 (prestaties naar/in het buitenland) en 4
        (prestaties vanuit het buitenland) zijn niet van toepassing — deze administratie
        voert geen EU-/verlegd-transacties. Bedragen afgeleid uit de geboekte grootboekmutaties.
    </div>

    <div class="footer">
        {{ $issuer['name'] }} · gegenereerd {{ \Illuminate\Support\Carbon::parse($end)->format('d-m-Y') }}
    </div>
</body>
</html>
