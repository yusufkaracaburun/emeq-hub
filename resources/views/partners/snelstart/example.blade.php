{{-- Voorbeeld-partnerpagina voor SnelStart-certificeringsaanvraag. --}}
{{-- Dit is NIET de productie-partnerpagina en wordt niet via routing benaderd. --}}
{{-- Plan 08-05 — uitgebreid met domeinmodel-blokje + koppel-stappen + cURL-snippet + status-widget (UI-SPEC §S3). --}}
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Emeq Hub × SnelStart</title>
</head>
<body class="max-w-3xl mx-auto px-4 py-12 text-gray-900 antialiased">
    <h1 class="text-3xl font-semibold leading-tight mb-4">Emeq Hub × SnelStart</h1>

    <p class="text-base leading-normal mb-4">
        De Emeq Hub is een integratieplatform dat SaaS-apps koppelt aan Nederlandse
        boekhoud- en betaal-API's. Via één koppeling kun je vanuit jouw applicatie
        SnelStart-administraties uitlezen en bijwerken zonder zelf de B2B-API te
        implementeren.
    </p>

    <p class="text-base leading-normal mb-4">
        Deze koppeling is bedoeld voor ontwikkelaars en SaaS-leveranciers die hun
        eindgebruikers (boekhouders, scholen, verenigingen, MKB) willen ontsluiten
        naar SnelStart. De Emeq Hub regelt OAuth-koppeling, multi-tenant
        token-opslag en audit-logging.
    </p>

    @include('partners.partials._domeinmodel')

    <h2 class="text-xl font-semibold leading-tight mt-8 mb-4">Koppelen via credential-form</h2>
    <ol class="list-decimal pl-6 space-y-2 text-base">
        <li>Vraag bij SnelStart de drie credentials op (client key, subscription key, subscription ID).</li>
        <li>POST naar <code>/v1/connections</code> met provider=snelstart en de drie velden.</li>
        <li>De Hub encrypt de credentials at rest; alleen de fingerprint is daarna nog leesbaar.</li>
    </ol>

    <pre class="bg-gray-50 dark:bg-neutral-900 p-4 rounded-lg my-4 text-sm overflow-x-auto"><code>curl -X POST {APP_URL}/v1/connections \
  -H "Authorization: Bearer {PAT}" \
  -H "Content-Type: application/json" \
  -d '{"account_external_id":"school1","provider":"snelstart","client_key":"…","subscription_key":"…","subscription_id":"…"}'</code></pre>

    @php
        $accountStatus = app(\App\Services\PartnerStatus::class)->forProvider('snelstart');
    @endphp
    @include('partners.partials._status-widget', ['provider' => 'snelstart', 'accountStatus' => $accountStatus])

    <h2 class="text-xl font-semibold leading-tight mt-8 mb-4">Contact</h2>
    <p class="text-base">Support: <a href="mailto:support@emeq.nl" class="text-amber-700 hover:underline">support@emeq.nl</a></p>

    <h2 class="text-xl font-semibold leading-tight mt-8 mb-4">Voorwaarden</h2>
    <ul class="list-disc pl-6 space-y-1 text-base">
        <li><a href="https://emeq.nl/privacy-policy/" class="text-amber-700 hover:underline">Privacybeleid</a></li>
        <li><a href="https://emeq.nl/algemene-voorwaarden/" class="text-amber-700 hover:underline">Gebruiksvoorwaarden</a></li>
    </ul>

    <hr class="my-6 border-gray-200">

    <p class="text-sm text-gray-500"><small>Deze koppeling gebruikt de SnelStart B2B-API.</small></p>
</body>
</html>
