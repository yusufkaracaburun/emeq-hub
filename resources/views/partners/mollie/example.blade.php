{{-- Voorbeeld-partnerpagina voor Mollie Connect Partner-aanvraag. --}}
{{-- Dit is NIET de productie-partnerpagina en wordt niet via productie-routing benaderd. --}}
{{-- Plan 08-05 — uitgebreid met domeinmodel-blokje + koppel-stappen + amber CTA + status-widget (UI-SPEC §S3). --}}
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Emeq Hub × Mollie</title>
</head>
<body class="max-w-3xl mx-auto px-4 py-12 text-gray-900 antialiased">
    <h1 class="text-3xl font-semibold leading-tight mb-4">Emeq Hub × Mollie</h1>

    <p class="text-base leading-normal mb-4">
        De Emeq Hub is een integratieplatform dat SaaS-apps koppelt aan Nederlandse
        boekhoud- en betaal-API's. Via één koppeling kun je vanuit jouw applicatie
        Mollie-betalingen, mandaten en subscriptions afhandelen zonder zelf de
        Connect-flow te implementeren.
    </p>

    <p class="text-base leading-normal mb-4">
        Deze koppeling is bedoeld voor ontwikkelaars en SaaS-leveranciers die hun
        eindgebruikers (boekhouders, scholen, verenigingen, MKB) willen ontsluiten
        naar Mollie. De Emeq Hub regelt OAuth-koppeling, multi-tenant
        token-opslag, webhook-fanout en audit-logging.
    </p>

    @include('partners.partials._domeinmodel')

    <h2 class="text-xl font-semibold leading-tight mt-8 mb-4">Koppelen via OAuth Connect</h2>
    <ol class="list-decimal pl-6 space-y-2 text-base">
        <li>Zorg dat school A een Mollie test-account heeft.</li>
        <li>Klik op 'Start OAuth-flow' hieronder &mdash; je wordt naar Mollie gestuurd.</li>
        <li>Na goedkeuring landt de access_token encrypted in de Connection.</li>
    </ol>

    <a href="{{ route('dev.partners.mollie.start-oauth') }}"
       class="inline-flex items-center gap-2 rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600 mt-4">
        Start OAuth-flow
    </a>

    <h2 class="text-xl font-semibold leading-tight mt-8 mb-4">Use-cases</h2>
    <ul class="list-disc pl-6 space-y-2 text-base">
        <li><strong>Account-level subscriptions</strong> &mdash; Consumer-apps factureren hun eigen eindgebruikers via Mollie Subscriptions (state-machine via Hub).</li>
        <li><strong>Payments-pass-through</strong> &mdash; Consumer-apps maken Mollie-payments via Hub-API; mandates blijven onder de Hub.</li>
        <li><strong>Connect-broker</strong> &mdash; OAuth-koppeling per Account met automatic refresh, encryption-at-rest en per-Connection webhook-secret.</li>
    </ul>

    @php
        $accountStatus = app(\App\Services\PartnerStatus::class)->forProvider('mollie');
    @endphp
    @include('partners.partials._status-widget', ['provider' => 'mollie', 'accountStatus' => $accountStatus])

    <h2 class="text-xl font-semibold leading-tight mt-8 mb-4">Documentatie</h2>
    <ul class="list-disc pl-6 space-y-1 text-base">
        <li><a href="https://emeq.nl/docs" class="text-amber-700 hover:underline">Developer-documentatie</a></li>
        <li><a href="https://emeq.nl/partners/mollie" class="text-amber-700 hover:underline">Partner-overzicht</a></li>
        <li><a href="https://emeq.nl/contact" class="text-amber-700 hover:underline">Contact</a></li>
    </ul>

    <h2 class="text-xl font-semibold leading-tight mt-8 mb-4">Compliance</h2>
    <ul class="list-disc pl-6 space-y-1 text-base">
        <li>Tokens encrypted at rest (Laravel encrypter, AES-256-GCM).</li>
        <li>Per-Connection webhook-secret &mdash; geen globale shared secret.</li>
        <li>Audit-log van elke pass-through-call en webhook-fanout.</li>
        <li>OAuth-state in pending Connection, TTL 30 min, replay-protected.</li>
    </ul>
</body>
</html>
