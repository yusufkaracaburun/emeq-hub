{{-- Voorbeeld-partnerpagina voor SnelStart-certificeringsaanvraag. --}}
{{-- Dit is NIET de productie-partnerpagina en wordt niet via routing benaderd. --}}
{{-- Doel: content + structuur tonen aan SnelStart bij certificeringsformulier-submit (zie .docs/decisions/snelstart-certificering-pad.md, deliverable (b)). --}}
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Emeq Hub × SnelStart</title>
</head>
<body>
    <h1>Emeq Hub × SnelStart</h1>

    <p>
        De Emeq Hub is een integratieplatform dat SaaS-apps koppelt aan Nederlandse
        boekhoud- en betaal-API's. Via één koppeling kun je vanuit jouw applicatie
        SnelStart-administraties uitlezen en bijwerken zonder zelf de B2B-API te
        implementeren.
    </p>

    <p>
        Deze koppeling is bedoeld voor ontwikkelaars en SaaS-leveranciers die hun
        eindgebruikers (boekhouders, scholen, verenigingen, MKB) willen ontsluiten
        naar SnelStart. De Emeq Hub regelt OAuth-koppeling, multi-tenant
        token-opslag en audit-logging.
    </p>

    <h2>Screenshots</h2>
    {{-- screenshot 1: dashboard met gekoppelde administraties --}}
    <img alt="Emeq Hub dashboard met gekoppelde SnelStart-administraties" src="">
    {{-- screenshot 2: koppelingsflow stap 1 (activeringslink) --}}
    <img alt="Activeringslink naar SnelStart vanuit Emeq Hub" src="">
    {{-- screenshot 3: audit-log per pass-through-call --}}
    <img alt="Audit-log met pass-through API-calls per administratie" src="">

    <h2>Contact</h2>
    <p>Support: <a href="mailto:support@emeq.nl">support@emeq.nl</a></p>

    <h2>Voorwaarden</h2>
    <ul>
        <li><a href="https://emeq.nl/privacy-policy/">Privacybeleid</a></li>
        <li><a href="https://emeq.nl/algemene-voorwaarden/">Gebruiksvoorwaarden</a></li>
    </ul>

    <hr>

    <p><small>Deze koppeling gebruikt de SnelStart B2B-API.</small></p>
</body>
</html>
