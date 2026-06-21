@php
    $eur = static fn (int $cents): string => '€ '.number_format($cents / 100, 2, ',', '.');
    $fmtDate = static fn (string $d): string => \Illuminate\Support\Carbon::parse($d)->format('d-m-Y');

    $revenue = $profitAndLoss['total_revenue'];
    $expense = $profitAndLoss['total_expense'];
    $result = $profitAndLoss['result'];

    $passiva = array_merge(
        $balanceSheet['liabilities'],
        $balanceSheet['equity'],
        [['code' => '', 'name' => 'Resultaat boekjaar', 'amount' => $balanceSheet['result']]],
    );
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
        table.stmt td { padding: 5px 8px; border-bottom: 1px solid #eef0f3; }
        table.stmt td.amount { text-align: right; }
        .code { color: #9ca3af; width: 48px; }
        .group-label {
            font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em;
            color: #6b7280; padding: 12px 8px 4px 8px;
        }
        tr.subtotal td { border-top: 1px solid #d1d5db; border-bottom: none; font-weight: bold; color: #111827; }
        tr.grand td {
            border-top: 1.5px solid #111827; border-bottom: none;
            font-weight: bold; font-size: 13px; color: #111827; padding-top: 8px;
        }
        .empty { color: #9ca3af; padding: 6px 8px; }
        .badge {
            display: inline-block; font-size: 9px; font-weight: bold; padding: 2px 8px;
            border-radius: 8px; margin-left: 8px;
        }
        .badge-ok { background: #ecfdf5; color: #047857; }
        .badge-off { background: #fef2f2; color: #b91c1c; }

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
                <h1 class="title">Financieel overzicht</h1>
                <div class="period">{{ $fmtDate($start) }} t/m {{ $fmtDate($end) }}</div>
            </td>
            <td class="right" style="text-align: right;">
                <div class="issuer-name">{{ $issuer['name'] }}</div>
                @if ($issuer['vat_number'])<div class="muted">BTW {{ $issuer['vat_number'] }}</div>@endif
            </td>
        </tr>
    </table>

    {{-- Winst & Verlies --}}
    <div class="section">
        <h2 class="section-title">Winst &amp; Verlies</h2>
        <table class="stmt">
            <tr><td class="group-label" colspan="3">Omzet</td></tr>
            @forelse ($profitAndLoss['revenue'] as $line)
                <tr>
                    <td class="code">{{ $line['code'] }}</td>
                    <td>{{ $line['name'] }}</td>
                    <td class="amount">{{ $eur($line['amount']) }}</td>
                </tr>
            @empty
                <tr><td class="empty" colspan="3">Geen omzet in deze periode.</td></tr>
            @endforelse
            <tr class="subtotal">
                <td colspan="2">Totaal omzet</td>
                <td class="amount">{{ $eur($revenue) }}</td>
            </tr>

            <tr><td class="group-label" colspan="3">Kosten</td></tr>
            @forelse ($profitAndLoss['expense'] as $line)
                <tr>
                    <td class="code">{{ $line['code'] }}</td>
                    <td>{{ $line['name'] }}</td>
                    <td class="amount">{{ $eur($line['amount']) }}</td>
                </tr>
            @empty
                <tr><td class="empty" colspan="3">Geen kosten in deze periode.</td></tr>
            @endforelse
            <tr class="subtotal">
                <td colspan="2">Totaal kosten</td>
                <td class="amount">{{ $eur($expense) }}</td>
            </tr>

            <tr class="grand">
                <td colspan="2">Resultaat</td>
                <td class="amount">{{ $eur($result) }}</td>
            </tr>
        </table>
    </div>

    {{-- Balans --}}
    <div class="section">
        <h2 class="section-title">
            Balans per {{ $fmtDate($end) }}
            @if ($balanceSheet['balances'])
                <span class="badge badge-ok">In balans</span>
            @else
                <span class="badge badge-off">Sluit niet</span>
            @endif
        </h2>
        <table class="stmt">
            <tr><td class="group-label" colspan="3">Activa</td></tr>
            @forelse ($balanceSheet['assets'] as $line)
                <tr>
                    <td class="code">{{ $line['code'] }}</td>
                    <td>{{ $line['name'] }}</td>
                    <td class="amount">{{ $eur($line['amount']) }}</td>
                </tr>
            @empty
                <tr><td class="empty" colspan="3">Geen activa.</td></tr>
            @endforelse
            <tr class="subtotal">
                <td colspan="2">Totaal activa</td>
                <td class="amount">{{ $eur($balanceSheet['total_assets']) }}</td>
            </tr>

            <tr><td class="group-label" colspan="3">Passiva</td></tr>
            @forelse ($passiva as $line)
                <tr>
                    <td class="code">{{ $line['code'] }}</td>
                    <td>{{ $line['name'] }}</td>
                    <td class="amount">{{ $eur($line['amount']) }}</td>
                </tr>
            @empty
                <tr><td class="empty" colspan="3">Geen passiva.</td></tr>
            @endforelse
            <tr class="subtotal">
                <td colspan="2">Totaal passiva</td>
                <td class="amount">{{ $eur($balanceSheet['total_liabilities_and_equity']) }}</td>
            </tr>
        </table>
    </div>

    <div class="footer">
        {{ $issuer['name'] }} · gegenereerd {{ \Illuminate\Support\Carbon::parse($end)->format('d-m-Y') }}
    </div>
</body>
</html>
