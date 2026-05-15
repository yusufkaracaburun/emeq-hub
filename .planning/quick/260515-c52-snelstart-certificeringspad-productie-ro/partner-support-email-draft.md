---
purpose: Concept-email aan SnelStart partner-support — technische vragen vóór productie-certificeringsaanvraag
to: partner@snelstart.nl
from: info@emeq.nl
status: draft (te verzenden door user; geen actie van Hub-code afhankelijk)
linked_to: .docs/decisions/snelstart-certificering-pad.md (deliverable b: partner-support open items)
---

# Concept-email — Snelstart partner-support

> Verzenden zodra Phase 5c (webhook-handler) op de planning staat. De antwoorden bepalen `connections.administratie_id`-veldnaam en de HMAC-verificatie-implementatie.

---

**Onderwerp:** Technische vragen webhook-implementatie + voorbereiding productie-certificeringsaanvraag — Emeq

---

Hoi Snelstart partner-team,

Wij bouwen op dit moment de Snelstart-koppeling voor het Emeq Hub-platform (multi-tenant integration platform; één centrale OAuth/credential-/audit-laag waarop meerdere consumer-apps Snelstart-calls passeren). Wij zitten momenteel op de Ontwikkeling & Test-tier (subscription `emeq`) en bereiden de productie-certificeringsaanvraag voor.

Voordat we het certificeringsformulier op snelstart.nl/api invullen, hebben we een paar technische vragen waar de developer-portal-docs ons niet helemaal voorbij helpen:

## Webhook-implementatie

1. **HMAC-header-naam.** Welke HTTP-header bevat de signature van inkomende webhooks naar onze partner-webhookURL? Documentatie noemt het mechanisme maar niet de exacte header-naam (bv. `X-SnelStart-Signature`, `X-Signature`, of anders). En welk algoritme — `HMAC-SHA256` over de raw request-body?

2. **Webhook-secret-lifecycle.** Krijgen wij één partner-secret bij certificering die we via onze partner-portal kunnen roteren? Of wordt de secret per webhook-URL beheerd?

3. **Tenant-routing via één partner-URL.** Wij geven bij certificering één publieke URL door (`https://hub.emeq.nl/webhooks/snelstart`). Begrijpen wij goed dat *alle* Snelstart-administraties die via onze AppShortName gekoppeld zijn webhooks naar diezelfde URL sturen, en dat wij de juiste administratie afleiden uit de payload (vermoedelijk `administratieId` of vergelijkbaar)? Zo ja: welk veld in de payload is canonical voor die routing?

4. **Retry-policy.** Bij een niet-2xx-respons van onze kant: hoeveel retries doet Snelstart, met welke backoff, en wat is de eindstaat als alle retries falen (queue / DLQ / handmatige replay vanuit het portaal)?

5. **Event-typen voor v1.** Welke webhook-event-typen zijn er beschikbaar in de productie-tier — en welke (zo niet alle) raden jullie aan voor een eerste integratie die zich richt op Relaties + Verkoopfacturen?

## Productie-certificeringsaanvraag

6. **Verwachte doorlooptijd.** Wat is de typische tijd tussen het indienen van het certificeringsformulier en het ontvangen van een productiesleutel, ervan uitgaande dat de technische koppeling klaar is?

7. **Partnerpagina-content.** Wij hebben een eerste voorbeeld-view klaar (app-naam, beschrijving, support-contact, privacy/voorwaarden-links, SnelStart-vermelding). Hebben jullie een voorbeeld of template van wat een gecertificeerde partnerpagina minimaal moet bevatten qua copy, screenshots, of branding-elementen?

8. **Productie-sleutel + pakketeisen.** De certificeringspagina noemt "per-koppeling pakketeisen". Voor een koppeling die naar verwachting Relaties + Verkoopfacturen + later Boekstukken raakt — is daar een richtlijn voor de licentie-tiers die wij aan onze eindklanten moeten verplichten? Of bepaalt Snelstart dat zelf per administratie?

## Achtergrond

- Wij hosten op Laravel Cloud (Caddy + PHP 8.4 + Postgres 16 + Horizon-queues + Redis).
- Onze SDK-laag (`emeq/snelstart-api`) wrapt de B2B-API v2 via Saloon v4 — we volgen jullie OData-conventies (geen verzonnen endpoints) en hebben `.docs/partners/snelstart/`-snapshot van de officiële docs lokaal voor on-board AI-agents.
- Onze consumers zijn op dit moment 3 Emeq-eigen SaaS-apps; in v1.0+ openen we de Hub voor derden, mits dat past binnen jullie partner-voorwaarden.

Alvast bedankt voor de antwoorden. Op basis daarvan ronden we Phase 5c (webhook-handler) af en vullen het certificeringsformulier in.

Met vriendelijke groet,
Yusuf Karacaburun
Emeq · info@emeq.nl

---

## Antwoord-tracking (vul in na ontvangst)

| # | Vraag | Antwoord | Ontvangen | Verwerkt in |
|---|---|---|---|---|
| 1 | HMAC-header-naam + algoritme | — | — | Phase 5c CONTEXT |
| 2 | Webhook-secret-lifecycle | — | — | `connections`-migration of `.env` |
| 3 | Tenant-routing payload-veld | — | — | `connections.administratie_id`-veldnaam |
| 4 | Retry-policy | — | — | Phase 5c CONTEXT (DLQ-strategie) |
| 5 | Event-typen voor v1 | — | — | Phase 5c plan-scope |
| 6 | Doorlooptijd certificering | — | — | ROADMAP-timing |
| 7 | Partnerpagina-template | — | — | `resources/views/partners/snelstart/example.blade.php` finaliseren |
| 8 | Pakketeisen per endpoint | — | — | Productie-go-live checklist |
