@php
    /** @var \App\Books\Models\Invoice $invoice */
    $eur = static fn (int $cents): string => '€ '.number_format($cents / 100, 2, ',', '.');
    $qty = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    $client = $invoice->client;
@endphp
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 110px 50px 90px 50px; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 11px; color: #1f2937; margin: 0; }

        .header { width: 100%; border-collapse: collapse; margin-bottom: 28px; }
        .header td { vertical-align: top; }
        .issuer-name { font-size: 20px; font-weight: bold; color: #111827; }
        .muted { color: #6b7280; }
        .label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.06em; color: #9ca3af; }

        .parties { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        .parties td { vertical-align: top; width: 50%; }
        .party-name { font-weight: bold; font-size: 12px; color: #111827; }

        .meta { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .meta td { padding: 2px 0; }
        .meta .meta-label { color: #6b7280; width: 90px; }

        h1.title { font-size: 16px; color: #111827; margin: 0 0 14px 0; }

        table.lines { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.lines th {
            text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em;
            color: #6b7280; border-bottom: 1.5px solid #111827; padding: 6px 8px;
        }
        table.lines td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; }
        .right { text-align: right; }
        .center { text-align: center; }

        table.totals { width: 42%; border-collapse: collapse; margin-top: 14px; float: right; }
        table.totals td { padding: 4px 8px; }
        table.totals .t-label { color: #6b7280; }
        table.totals tr.grand td {
            border-top: 1.5px solid #111827; font-weight: bold; font-size: 13px; color: #111827; padding-top: 8px;
        }

        .notes { clear: both; margin-top: 60px; padding-top: 14px; border-top: 1px solid #e5e7eb; color: #4b5563; }
        .footer {
            position: fixed; bottom: -60px; left: 0; right: 0; text-align: center;
            font-size: 9px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 8px;
        }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <div class="issuer-name">{{ $issuer['name'] }}</div>
                <div class="muted">
                    @if ($issuer['address_line_1']){{ $issuer['address_line_1'] }}<br>@endif
                    @if ($issuer['address_line_2']){{ $issuer['address_line_2'] }}<br>@endif
                    @if ($issuer['postal_code'] || $issuer['city']){{ trim($issuer['postal_code'].'  '.$issuer['city']) }}<br>@endif
                    @if ($issuer['country']){{ $issuer['country'] }}@endif
                </div>
            </td>
            <td class="right muted">
                @if ($issuer['email']){{ $issuer['email'] }}<br>@endif
                @if ($issuer['phone']){{ $issuer['phone'] }}<br>@endif
                @if ($issuer['website']){{ $issuer['website'] }}<br>@endif
                @if ($issuer['coc_number'])KvK {{ $issuer['coc_number'] }}<br>@endif
                @if ($issuer['vat_number'])BTW {{ $issuer['vat_number'] }}@endif
            </td>
        </tr>
    </table>

    <h1 class="title">Factuur</h1>

    <table class="parties">
        <tr>
            <td>
                <div class="label">Factuur aan</div>
                @if ($client)
                    <div class="party-name">{{ $client->name }}</div>
                    <div class="muted">
                        @if ($client->address_line_1){{ $client->address_line_1 }}<br>@endif
                        @if ($client->address_line_2){{ $client->address_line_2 }}<br>@endif
                        @if ($client->postal_code || $client->city){{ trim($client->postal_code.'  '.$client->city) }}<br>@endif
                        @if ($client->vat_number)BTW {{ $client->vat_number }}@endif
                    </div>
                @else
                    <div class="muted">—</div>
                @endif
            </td>
            <td>
                <table class="meta">
                    <tr>
                        <td class="meta-label">Factuurnr.</td>
                        <td>{{ $invoice->invoice_number ?: '—' }}</td>
                    </tr>
                    <tr>
                        <td class="meta-label">Datum</td>
                        <td>{{ optional($invoice->date)->format('d-m-Y') ?: '—' }}</td>
                    </tr>
                    <tr>
                        <td class="meta-label">Vervaldatum</td>
                        <td>{{ optional($invoice->due_date)->format('d-m-Y') ?: '—' }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Omschrijving</th>
                <th class="right">Aantal</th>
                <th class="right">Prijs</th>
                <th class="right">BTW</th>
                <th class="right">Bedrag</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($invoice->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="right">{{ $qty($line->quantity) }}</td>
                    <td class="right">{{ $eur($line->unit_price) }}</td>
                    <td class="right">{{ $line->tax_rate }}%</td>
                    <td class="right">{{ $eur($line->subtotal) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="center muted">Geen factuurregels</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="t-label">Subtotaal</td>
            <td class="right">{{ $eur($invoice->subtotal) }}</td>
        </tr>
        <tr>
            <td class="t-label">BTW</td>
            <td class="right">{{ $eur($invoice->tax_total) }}</td>
        </tr>
        <tr class="grand">
            <td>Totaal</td>
            <td class="right">{{ $eur($invoice->total) }}</td>
        </tr>
    </table>

    @if ($invoice->notes || $issuer['iban'])
        <div class="notes">
            @if ($invoice->notes)<div>{{ $invoice->notes }}</div>@endif
            @if ($issuer['iban'])
                <div style="margin-top: 8px;">
                    Gelieve {{ $eur($invoice->total) }} over te maken op
                    <strong>{{ $issuer['iban'] }}</strong>
                    @if ($invoice->invoice_number) o.v.v. {{ $invoice->invoice_number }}@endif.
                </div>
            @endif
        </div>
    @endif

    <div class="footer">
        {{ $issuer['name'] }}@if ($issuer['vat_number']) · BTW {{ $issuer['vat_number'] }}@endif@if ($issuer['iban']) · {{ $issuer['iban'] }}@endif
    </div>
</body>
</html>
