{{-- Voorbeeld-partnerpagina voor Mollie Connect Partner-aanvraag. --}}
{{-- Dit is NIET de productie-partnerpagina en wordt niet via productie-routing benaderd. --}}
{{-- Doel: content + structuur tonen aan Mollie bij Connect Partner-aanvraag (zelfde patroon als snelstart-voorbeeld). --}}
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Emeq Hub × Mollie</title>
</head>
<body>
    <h1>Emeq Hub × Mollie</h1>

    <p>
        De Emeq Hub is een integratieplatform dat SaaS-apps koppelt aan Nederlandse
        boekhoud- en betaal-API's. Via één koppeling kun je vanuit jouw applicatie
        Mollie-betalingen, mandaten en subscriptions afhandelen zonder zelf de
        Connect-flow te implementeren.
    </p>

    <p>
        Deze koppeling is bedoeld voor ontwikkelaars en SaaS-leveranciers die hun
        eindgebruikers (boekhouders, scholen, verenigingen, MKB) willen ontsluiten
        naar Mollie. De Emeq Hub regelt OAuth-koppeling, multi-tenant
        token-opslag, webhook-fanout en audit-logging.
    </p>

    <h2>Use-cases</h2>
    <ul>
        <li><strong>Account-level subscriptions</strong> — Consumer-apps factureren hun eigen eindgebruikers via Mollie Subscriptions (state-machine via Hub).</li>
        <li><strong>Payments-pass-through</strong> — Consumer-apps maken Mollie-payments via Hub-API; mandates blijven onder de Hub.</li>
        <li><strong>Connect-broker</strong> — OAuth-koppeling per Account met automatic refresh, encryption-at-rest en per-Connection webhook-secret.</li>
    </ul>

    <h2>Screenshots</h2>
    {{-- screenshot 1: dashboard met gekoppelde Mollie-accounts --}}
    {{-- screenshot 2: subscription-overzicht per Account --}}
    {{-- screenshot 3: webhook-audit-log --}}
    <p><em>(screenshots volgen — admin-paneel staat live op /admin)</em></p>

    <h2>Documentatie</h2>
    <ul>
        <li><a href="https://emeq.nl/docs">Developer-documentatie</a></li>
        <li><a href="https://emeq.nl/partners/mollie">Partner-overzicht</a></li>
        <li><a href="https://emeq.nl/contact">Contact</a></li>
    </ul>

    <h2>Compliance</h2>
    <ul>
        <li>Tokens encrypted at rest (Laravel encrypter, AES-256-GCM).</li>
        <li>Per-Connection webhook-secret — geen globale shared secret.</li>
        <li>Audit-log van elke pass-through-call en webhook-fanout.</li>
        <li>OAuth-state in pending Connection, TTL 30 min, replay-protected.</li>
    </ul>
</body>
</html>
