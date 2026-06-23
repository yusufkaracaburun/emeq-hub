@php
    $eur = static fn (int $cents): string => '€ '.number_format($cents / 100, 2, ',', '.');
    $fmtDate = static fn (string $d): string => \Illuminate\Support\Carbon::parse($d)->format('d-m-Y');

    $rows = $report['rows'];
    $totals = $report['totals'];
    $isPayable = $report['kind'] === 'payable';
    $title = $isPayable ? 'Ouderdomsanalyse crediteuren' : 'Ouderdomsanalyse debiteuren';
    $relationHeader = $isPayable ? 'Leverancier' : 'Klant';
    $bucketKeys = array_keys($buckets);
@endphp
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 60px 40px 70px 40px; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 10px; color: #1f2937; margin: 0; }

        .head { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .head td { vertical-align: top; }
        .issuer-name { font-size: 15px; font-weight: bold; color: #111827; }
        .muted { color: #6b7280; }
        h1.title { font-size: 17px; color: #111827; margin: 0; }
        .period { color: #6b7280; margin-top: 2px; }

        table.aging { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.aging th {
            font-size: 8px; text-transform: uppercase; letter-spacing: 0.05em;
            color: #6b7280; padding: 5px 6px; border-bottom: 1px solid #d1d5db;
        }
        table.aging th.amount, table.aging td.amount { text-align: right; }
        table.aging th.rel, table.aging td.rel { text-align: left; }
        table.aging td { padding: 5px 6px; border-bottom: 1px solid #eef0f3; }
        td.rel { font-weight: bold; color: #111827; }
        td.zero { color: #c0c4cc; }
        td.over { color: #b91c1c; font-weight: bold; }

        tr.grand td {
            border-top: 1.5px solid #111827; border-bottom: none;
            font-weight: bold; font-size: 11px; color: #111827; padding-top: 7px;
        }
        td.total { font-weight: bold; color: #111827; }

        .empty { margin-top: 30px; text-align: center; color: #9ca3af; font-size: 11px; }

        .footer {
            position: fixed; bottom: -45px; left: 0; right: 0; text-align: center;
            font-size: 8px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 6px;
        }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <h1 class="title">{{ $title }}</h1>
                <div class="period">Peildatum {{ $fmtDate($report['as_of']) }}</div>
            </td>
            <td style="text-align: right;">
                <div class="issuer-name">{{ $issuer['name'] }}</div>
                @if ($issuer['vat_number'])<div class="muted">BTW {{ $issuer['vat_number'] }}</div>@endif
            </td>
        </tr>
    </table>

    @if (count($rows) === 0)
        <p class="empty">Geen openstaande posten op deze peildatum.</p>
    @else
        <table class="aging">
            <thead>
                <tr>
                    <th class="rel">{{ $relationHeader }}</th>
                    @foreach ($buckets as $label)
                        <th class="amount">{{ $label }}</th>
                    @endforeach
                    <th class="amount">Totaal</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td class="rel">{{ $row['relation'] }}</td>
                        @foreach ($bucketKeys as $bucketKey)
                            @php($value = $row['buckets'][$bucketKey] ?? 0)
                            <td class="amount {{ $value === 0 ? 'zero' : ($bucketKey === 'd90_plus' ? 'over' : '') }}">
                                {{ $value === 0 ? '—' : $eur($value) }}
                            </td>
                        @endforeach
                        <td class="amount total">{{ $eur($row['total']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="grand">
                    <td class="rel">Totaal</td>
                    @foreach ($bucketKeys as $bucketKey)
                        <td class="amount">{{ $eur($totals[$bucketKey] ?? 0) }}</td>
                    @endforeach
                    <td class="amount">{{ $eur($totals['total']) }}</td>
                </tr>
            </tfoot>
        </table>
    @endif

    <div class="footer">{{ $issuer['name'] }} · Ouderdomsanalyse gegenereerd op {{ $fmtDate($report['as_of']) }}</div>
</body>
</html>
