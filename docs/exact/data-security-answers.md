# Exact App Center — Beoordeling: gegevens en beveiliging

> **Wat is dit?** Het invulblad voor Exact's Data & Security-review. Per veld staat eerst
> een **korte kopieertekst** voor het portaal (weinig tekens), daarna de onderbouwing als
> Exact doorvraagt.
>
> **Status**: alle 12 D&S-vragen op "ja", inclusief vraag 12 — OVH ISO 27001 (cert 37383-7)
>
> - ISAE 3000 Datacenter ontvangen 2026-07-23. App-host = `hub.emeq.nl`. Scope-matrix
> ingevuld 2026-08-08 (accounting = Beheren bevestigd via tooltip). Resterend: submit
> ([#50](https://github.com/yusufkaracaburun/emeq-hub/issues/50)). Epic: [#36](https://github.com/yusufkaracaburun/emeq-hub/issues/36).
>
> **Laatst bijgewerkt**: 2026-08-08.

---

## Snelkopie — alle korte antwoorden

### 1. Doel (max 140)

**NL:**

```
Boekt verkoop- en inkoopfacturen uit gekoppelde SaaS-apps automatisch in Exact Online, en houdt relaties en grootboek synchroon.
```

**EN:**

```
Posts sales and purchase invoices from connected SaaS apps into Exact Online, and keeps relations and the general ledger in sync.
```

### Vragen 1–12 (JA + korte toelichting)

**V1 — Privacybeleid**

```
Ja. Openbaar op https://hub.emeq.nl/privacy: welke data, doel, bewaartermijn (90 dagen audit), verwijdering, intrekken toestemming, sub-verwerkers. Hub is verwerker.
```

**V2 — Expliciete toestemming**

```
Ja. Koppelen alleen via Exact OAuth2-autorisatie. Gebruiker keurt scopes actief goed. Intrekken revoket tokens en Connection.
```

**V3 — Akkoord privacybeleid**

```
Ja. Browser-flow: verplichte checkbox + link naar /privacy vóór koppelen. Server-to-server: contractueel via verwerkersovereenkomst (/verwerkersovereenkomst).
```

**V4 — Encryptie**

```
Ja. OAuth-tokens en credentials encrypted at rest. TLS in transit. Webhooks HMAC-geverifieerd. Geen payloads in audit. Multi-tenant isolatie via Consumer→Account→Connection.
```

**V5 — Wijzigingen getest**

```
Ja. Elke push: CI met Pint, ~950 PHPUnit-tests, composer audit en ShellCheck. Rood = geen merge. Feature-branch → groen → deploy.
```

**V6 — Wijzigingsgeschiedenis**

```
Ja. Git (Conventional Commits). Runtime: immutable pass_through_calls. Webhooks: inbound_webhook_events (metadata-only). Migraties forward-only.
```

**V7 — OWASP / secure coding**

```
Ja. Eloquent (geen raw SQL), CSRF, signed OAuth-URLs, HMAC-webhooks, open-redirect-guard, secrets buiten git, dependency-scanning.
```

**V8 — Vulnerability-evaluatie**

```
Ja. composer audit in CI als faalstap. Advisories gepatcht of onderbouwd genegeerd. Elke push opnieuw tegen advisory-DB.
```

**V9 — Datacenter**

```
Ja. OVH cloud, EU-regio (Frankfurt/DE, DC Limburg). Dedicated VPS.
```

**V10 — Logische toegang**

```
Ja. Dedicated VPS, geen open poorten (Cloudflare Tunnel), SSH key-only. App: Sanctum-PAT + Filament-rollen. DB/Redis intern. Backups encrypted off-site.
```

**V11 — Fysieke toegang**

```
Ja. Via OVH: fysieke DC-toegang onder ISO 27001 (cert 37383-7) en ISAE 3000 Datacenter. Attestaties bij vraag 12.
```

**V12 — Derdenverklaring**

```
Ja, via hostingprovider OVH (niet Emeq zelf). Beschikbaar op verzoek: ISO 27001:2022 cert 37383-7 (12-02-2026 t/m 03-02-2027) + ISAE 3000/CSA C5 Type II Datacenter. Hub op OVH VPS Frankfurt/DE.
```

### 4. Derden (per partij: naam + doel NL/EN + gegevens NL/EN)

**Derde partij 1**

```
Consumer-apps (emeq-app, Planny, Naschool)
```

Doel (NL):

```
Doorsturen van partner-webhooks en API-antwoorden naar de SaaS-app die de koppeling gebruikt
```

Gegevens (NL):

```
Financiële mutaties, relatie- en grootboekgegevens van het eigen Account van die Consumer
```

Doel (EN):

```
Forward partner webhooks and API responses to the SaaS app that owns the connection
```

Gegevens (EN):

```
Financial mutations, relation and general ledger data of that Consumer's own Account
```

**Derde partij 2**

```
Cloudflare
```

Doel (NL):

```
TLS-terminatie, DDoS-mitigatie en tunnel naar de applicatie
```

Gegevens (NL):

```
Verkeer in transit; geen opslag van gegevens
```

Doel (EN):

```
TLS termination, DDoS mitigation and tunnel to the application
```

Gegevens (EN):

```
Traffic in transit; no data storage
```

**Derde partij 3**

```
OVH
```

Doel (NL):

```
Hosting van applicatie en database in de EU
```

Gegevens (NL):

```
Alle opgeslagen applicatiegegevens (encrypted tokens, audit-metadata)
```

Doel (EN):

```
Hosting of application and database in the EU
```

Gegevens (EN):

```
All stored application data (encrypted tokens, audit metadata)
```

**Derde partij 4**

```
Exact Online
```

Doel (NL):

```
Gekoppelde partner-API: bron en bestemming van de boekingen
```

Gegevens (NL):

```
Verkoop-/inkoopmutaties, relaties en grootboek van de gekoppelde administratie
```

Doel (EN):

```
Connected partner API: source and destination of the bookings
```

Gegevens (EN):

```
Sales/purchase mutations, relations and general ledger of the connected administration
```

### 5. Contact

```
Yusuf Karacaburun · 0624392795 · info@emeq.nl
```

---

## 2. Scopes (kort)

Ingevuld in Exact-portaal (2026-08-08). Tooltip `accounting` = *"Boekingen, saldi en financiële gegevens"* → dekt `salesentry`/`purchaseentry`.


| Scope in formulier         | Waarde                                             |
| -------------------------- | -------------------------------------------------- |
| Crm → accounts             | **Beheren**                                        |
| Financial → generalledgers | **Lezen**                                          |
| Financial → costcenters    | **Lezen**                                          |
| Financial → accounting     | **Beheren** (boekingen)                            |
| Financial → cashflow       | **Lezen** (webhook-topics BankEntries/CashEntries) |
| Organization → documents   | **Beheren**                                        |


Naast deze zes staan ook `Sales → invoices`, `Purchase → invoices` en `Logistics → items` op **Beheren**; de rest op **Ongebruikt**. De volledige matrix, met per endpoint welke Hub-code eraan hangt, staat in [`scope-audit.md`](scope-audit.md).

Sinds 2026-08-12 staat `Organization → administration` op **Beheren**, aangevraagd nadat de webhook-registratie live op die scope stukliep. Wacht op goedkeuring door Exact.

DELETE via consumer-API is hard geblokkeerd (`405`).

---

## Onderbouwing (naslag bij doorvragen)

### V1

Live: [https://hub.emeq.nl/privacy](https://hub.emeq.nl/privacy). DB-beheerd. Tokens encrypted; pass-through niet bewaard; audit-metadata 90 dagen (`MassPrunable`). Hub = verwerker. Bron: `LegalDefaults::privacyStatement()`.

### V2

Exact OAuth2-scherm verplicht. Zonder consent geen `Connection`. Revoke → tokens + status `revoked`. Bron: `ExactOAuthFlow`, `Connection`.

### V3

1. `/koppelen`: verplichte checkbox → `privacy_accepted_at`. 2) S2S: Consumer via VVO op `/verwerkersovereenkomst`.

### V4

Encrypted casts op Connection/ExactSettings; fingerprint-only logs; HMAC-webhooks; metadata-only audit; Sanctum PAT; Cloudflare TLS; `throttle:api`; Idempotency-Key.

### V5

`.github/workflows/ci.yml`: Pint, PHPUnit, composer audit, ShellCheck. Branch-guard blokkeert edits op master.

### V6

Git + `pass_through_calls` + `inbound_webhook_events` + forward-only migraties.

### V7

Eloquent, CSRF, signed URLs, HMAC, `ReturnUrlResolver`, geen secrets in git, composer audit.

### V8

`composer audit` schoon; ignore-lijst in `composer.json`; CI-faalstap.

### V9–12

OVH VPS-3, region Frankfurt (DE) / zone `os-de2`, fysiek DC Limburg. Ticket CS16314299. Documenten: ISO 37383-7 + ISAE 3000 Datacenter (+ SOC2 Public Cloud alleen ter volledigheid, niet hoofdbewijs).

### Derden (volledig)

Vier partijen in het formulier: Consumer-apps, Cloudflare, OVH, Exact Online — zie Snelkopie §4.

---

## App Center-listing (Voordelen e.d.)

Logo moet **exact 600×300 px** zijn. Bestand klaar:
`docs/exact/app-center-logo-600x300.png` (upload dat in het portaal).

### Wat is hub.emeq.nl?

**NL:**

```
Emeq Hub koppelt je SaaS-software veilig aan Exact Online. Verkoop- en inkoopfacturen uit je app worden automatisch geboekt; relaties en grootboek blijven synchroon. OAuth, tokens en audit regelt de Hub — jij houdt focus op je product.
```

**EN:**

```
Emeq Hub securely connects your SaaS software to Exact Online. Sales and purchase invoices from your app are posted automatically; relations and the general ledger stay in sync. OAuth, tokens and audit are handled by the Hub — you stay focused on your product.
```

### Wat zijn de voordelen?

**NL:**

```
• Automatisch boeken van verkoop- en inkoopfacturen in Exact Online
• Relaties (debiteuren/crediteuren) en grootboek synchroon houden
• Veilige OAuth-koppeling per administratie; tokens encrypted en automatisch vernieuwd
• Geen dubbele boekingen dankzij ingebouwde idempotency
• Volledige audit-trail van elke API-aanroep
• Één integratielaag — jij bouwt geen eigen Exact-OAuth of tokenbeheer
```

**EN:**

```
• Automatically post sales and purchase invoices into Exact Online
• Keep relations (debtors/creditors) and the general ledger in sync
• Secure OAuth per administration; tokens encrypted and auto-refreshed
• No duplicate bookings thanks to built-in idempotency
• Full audit trail of every API call
• One integration layer — no custom Exact OAuth or token management
```

### Integratie (tab)

**NL:**

```
1. Vraag een Hub-koppeling aan bij Emeq — je krijgt een API-token.
2. Laat je klant zijn Exact-administratie autoriseren via OAuth.
3. Vul één keer de boekhoud-mapping in (relaties, btw, grootboek, dagboek).
4. Stuur documenten naar de Hub-API — de Hub boekt ze in Exact als SalesEntry of PurchaseEntry.
```

**EN:**

```
1. Request a Hub connection from Emeq — you receive an API token.
2. Have your customer authorize their Exact administration via OAuth.
3. Configure the accounting mapping once (relations, VAT, ledger, journal).
4. Send documents to the Hub API — the Hub posts them in Exact as SalesEntry or PurchaseEntry.
```

### Prijs (tab)

Gratis proef: **Ja**, **30 dagen**. Opstartkosten: **€ 0**.

Omschrijving prijs (NL):

```
Geen opstartkosten. Tarief op aanvraag.
```

Omschrijving prijs (EN):

```
No setup fee. Pricing on request.
```

Details opstartkosten (NL):

```
Geen opstartkosten. Neem contact op via info@emeq.nl voor een offerte op maat.
```

Details opstartkosten (EN):

```
No setup fee. Contact info@emeq.nl for a tailored quote.
```

### Support (tab)

Hoe werkt support (NL):

```
Support via e-mail op werkdagen. Stuur je vraag naar info@emeq.nl; we reageren doorgaans binnen één werkdag. Documentatie en privacy staan op https://hub.emeq.nl.
```

Hoe werkt support (EN):

```
Support by email on business days. Send your question to info@emeq.nl; we typically reply within one business day. Documentation and privacy: https://hub.emeq.nl.
```

Contact:

```
Telefoon: +31624392795
E-mail: info@emeq.nl
Website: https://hub.emeq.nl
```

### Integratie-tab (auto vanuit D&S)

De secties *"Met de app kunt u"* en *"Welke gegevens worden uitgewisseld"* vult Exact zelf uit het D&S-formulier — daar hoef je niets te typen. *"Met welke Exact oplossingen"* wordt beheerd door de Exact Partner Manager.

### Appkoppeling / Seamless-URI's

`/dev/exact/*` is **alleen local/testing** (OAuth-tracer). Op `hub.emeq.nl` → **404**. Niet invullen in het App Center.

Zo ingevuld in het App Center:

| Veld | URI |
|---|---|
| Probeer nu | `https://hub.emeq.nl/partners/exact` |
| Starten | `https://hub.emeq.nl/partners/exact` |
| Meer informatie | `https://hub.emeq.nl/partners/exact` |
| Niet meer gebruiken | `https://hub.emeq.nl/exact/stop` (Exact appendt `?Country=&Language=&UserId=`) |

Drie van de vier wijzen bewust naar dezelfde partnerpagina: info én koppel-formulier zitten daar (`#koppelen`). Exact levert bij "Starten" geen Consumer-context mee, dus een directe OAuth-start zou de keten `Consumer → Account → Connection` overslaan. De aanvraag loopt daarom via het formulier; wij zetten de koppeling klaar en sturen de handoff-link (`/connect/{account}`). Optioneel dieper linken: `…/partners/exact#koppelen`.

"Niet meer gebruiken" is wél een echte flow: `/exact/stop` doet de deprovisioning (revoke + fan-out + audit).

---

## Status

| #                                              | Blokker                                                       | Status       |
| ---------------------------------------------- | ------------------------------------------------------------- | ------------ |
| Scope-matrix + ⓘ verifiëren                    | [#39](https://github.com/yusufkaracaburun/emeq-hub/issues/39) | ✅ 2026-08-08 |
| Listing: logo 600×300 + teksten                | [#47](https://github.com/yusufkaracaburun/emeq-hub/issues/47) | ✅ 2026-08-09 |
| Seamless-URI's ingevuld                        | [#48](https://github.com/yusufkaracaburun/emeq-hub/issues/48) | ✅ 2026-08-09 |
| Submit (OVH-PDF's op verzoek als Exact vraagt) | [#50](https://github.com/yusufkaracaburun/emeq-hub/issues/50) | ✅ 2026-08-09 — status *Wordt beoordeeld* |

Resteert: Exact's oordeel afwachten en de lifecycle doorlopen tot **PUBLICEREN**. Pas daarna kunnen externe tenants koppelen.


