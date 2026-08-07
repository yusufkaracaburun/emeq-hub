# Exact App Center — Beoordeling: gegevens en beveiliging

> **Wat is dit?** Het invulblad voor Exact's Data & Security-review. Per veld staat hier
> wat je invult, waaróm dat het antwoord is, en welk bestand in deze repo dat bewijst.
> Neem dit mee als Exact doorvraagt.
>
> **Status**: alle 12 D&S-vragen staan op "ja", inclusief vraag 12 — de OVH ISO 27001-
> verklaring (cert 37383-7) + ISAE 3000 Datacenter zijn op 2026-07-23 ontvangen en klaar om
> bij te voegen. App-host is omgezet naar `hub.emeq.nl` ([#38](https://github.com/yusufkaracaburun/emeq-hub/issues/38) — dicht). Wat resteert is
> puur portaal-werk: de scope-matrix invullen/verifiëren ([#39](https://github.com/yusufkaracaburun/emeq-hub/issues/39)) en dan submitten ([#50](https://github.com/yusufkaracaburun/emeq-hub/issues/50)).
> Epic: [#36](https://github.com/yusufkaracaburun/emeq-hub/issues/36).
> **Laatst bijgewerkt**: 2026-07-25.

## Inhoud

- [Wanneer heb je deze review nodig?](#wanneer-heb-je-deze-review-nodig)
- [1. Doel (max 140 tekens, NL + ENG)](#1-doel-max-140-tekens-nl--eng)
- [2. Scopes](#2-scopes)
- [3. Beoordeling van gegevensbescherming en beveiliging](#3-beoordeling-van-gegevensbescherming-en-beveiliging)
  - [Vraag 1 — Openbaar privacybeleid](#vraag-1--openbaar-privacybeleid)
  - [Vraag 2 — Expliciete toestemming voor verzamelen en gebruiken](#vraag-2--expliciete-toestemming-voor-verzamelen-en-gebruiken)
  - [Vraag 3 — Akkoord met het privacybeleid](#vraag-3--akkoord-met-het-privacybeleid)
  - [Vraag 4 — Encryptie tegen ongeoorloofde toegang](#vraag-4--encryptie-tegen-ongeoorloofde-toegang)
  - [Vraag 5 — Wijzigingen gevalideerd en getest vóór doorvoeren](#vraag-5--wijzigingen-gevalideerd-en-getest-vóór-doorvoeren)
  - [Vraag 6 — Wijzigingsgeschiedenis](#vraag-6--wijzigingsgeschiedenis)
  - [Vraag 7 — Best practices voor beveiligd coderen (OWASP)](#vraag-7--best-practices-voor-beveiligd-coderen-owasp)
  - [Vraag 8 — Regelmatige vulnerability-evaluatie](#vraag-8--regelmatige-vulnerability-evaluatie)
  - [Vraag 9 — Data in eigen of cloud datacenter](#vraag-9--data-in-eigen-of-cloud-datacenter)
  - [Vraag 10 — Logische toegang alleen voor bevoegden](#vraag-10--logische-toegang-alleen-voor-bevoegden)
  - [Vraag 11 — Fysieke toegang alleen voor bevoegden](#vraag-11--fysieke-toegang-alleen-voor-bevoegden)
  - [Vraag 12 — Verklaring van derden (ISO 27001, ISAE 3402, SOC 1/2)](#vraag-12--verklaring-van-derden-iso-27001-isae-3402-soc-12)
- [4. Automatische koppelingen met derden](#4-automatische-koppelingen-met-derden)
- [5. Contactgegevens](#5-contactgegevens)
- [Wat er nog tussen jou en "Submit" staat](#wat-er-nog-tussen-jou-en-submit-staat)

## Wanneer heb je deze review nodig?

| Je wilt… | Nodig |
|---|---|
| **Je eigen** Exact-administratie koppelen | geregistreerde app + HTTPS-redirect-URI. **Geen review.** |
| **Klant**-administraties koppelen | volledige App-Center-publicatie **inclusief deze review** |

De Hub bestaat om het tweede te doen. Dus deze review is de poort.

---

## 1. Doel (max 140 tekens, NL + ENG)

> Omschrijf in maximaal 140 karakters het doel van je app en hoe je app de gegevens van
> Exact Online verwerkt.

**Nederlands** (concept — 128 tekens):

```
Boekt verkoop- en inkoopfacturen uit gekoppelde SaaS-apps automatisch in Exact Online,
en houdt relaties en grootboek synchroon.
```

**Engels** (concept — 129 tekens):

```
Posts sales and purchase invoices from connected SaaS apps into Exact Online, and keeps
relations and the general ledger in sync.
```

> ⚠️ Tel de tekens na elke wijziging. Exact kapt hard af op 140.

---

## 2. Scopes

Vul **alleen** in wat de Hub aantoonbaar aanroept. Alles daarbuiten blijft **Ongebruikt** —
minimale scopes zijn een sterk verhaal in de review, en Exact handhaaft ze server-side.

Afgeleid uit `vendor/emeq/exact-api/src/` en `app/`. Niet gegokt.

> **Gehandhaafd in code.** Deze resource-lijst leeft als
> `config('hub-providers.exact.allowed_paths')` en wordt afgedwongen op de generieke
> pass-through (`App\Support\Exact\ExactPathWhitelist`): een consumer die een pad
> buiten deze lijst aanroept krijgt `403 path_not_allowed` i.p.v. door te forwarden.
> Dit dicht het "logische toegang"-gat (vraag 10) — de technische API-oppervlakte
> valt nu samen met de gedeclareerde scopes. Lege lijst = kill-switch (whitelist uit).

| Exact-resource | Verbs | In te vullen scope | Zeker? |
|---|---|---|---|
| `crm/Accounts` | GET · POST · PUT | Crm → **accounts = Beheren** | ✅ |
| `financial/GLAccounts` | GET | Financial → **generalledgers = Lezen** | ✅ |
| `financial/Journals` | GET | Financial → generalledgers = Lezen | ⓘ |
| `financial/CostCenters` · `CostUnits` | GET | Financial → **costcenters = Lezen** | ✅ |
| `vat/VATCodes` | GET | Financial → accounting = Lezen | ⓘ |
| `salesentry/SalesEntries` | POST | Financial → **accounting = Beheren** | ⓘ |
| `purchaseentry/PurchaseEntries` | POST | Financial → **accounting = Beheren** | ⓘ |
| `documents/Documents` + `DocumentAttachments` | POST | Organization → **documents = Beheren** | ✅ |
| `webhooks/WebhookSubscriptions` | GET · POST · DELETE | geen eigen scope zichtbaar | ⓘ |
| webhook-topics `BankEntries`, `CashEntries` | — | Financial → cashflow óf accounting | ⓘ |

**Ongebruikt** (dus alles op de eerste kolom laten staan): Crm → marketing/opportunities/quotes ·
heel Sales · heel Purchase · heel Logistics · heel Manufacturing · heel Projects · heel Hrm ·
Financial → currencies/receivables/payables/returns/assets/budgets · Organization →
administration/workflow/search/officeapp/extensibility/googleworkspace/conversion ·
heel Accountancy · Communication → mailboxes.

> **De ⓘ-rijen moet je verifiëren**, niet overnemen. Elke scope in het formulier heeft een
> info-icoon dat vertelt welke API-endpoints eronder vallen. De mapping van `vat/VATCodes`,
> `salesentry`, `purchaseentry` en de webhook-topics is onzeker — Exact's functionele
> scope-namen komen niet 1-op-1 overeen met de REST-paden. Zie [#39](https://github.com/yusufkaracaburun/emeq-hub/issues/39).

**Over de DELETE-calls**: de generieke pass-through **weigert DELETE hard** —
`PassThroughController::ALLOWED_METHODS` bevat alleen `GET/POST/PUT/PATCH`, dus een
`DELETE /v1/exact/{path}` krijgt `405 method_not_allowed` en bereikt Exact nooit. De Hub kan
via de consumer-API dus technisch geen data bij Exact verwijderen — "wij verwijderen niet in
productie" is daarmee afgedwongen, geen belofte. `crm/Accounts`, `salesentry/SalesEntries` en
`purchaseentry/PurchaseEntries` hebben nog wél DELETE-implementaties in de SDK, maar die worden
uitsluitend aangeroepen door `app/Console/Commands/Exact/PurgeTestData.php` (operator-only
test-opruiming via de connector, buiten de pass-through om). Bewijs:
`tests/Feature/Api/V1/Exact/PassThroughTest.php::test_pass_through_blocks_delete_method_with_405`.

---

## 3. Beoordeling van gegevensbescherming en beveiliging

### Vraag 1 — Openbaar privacybeleid

> Heeft u een openbaar toegankelijk privacybeleid waarin expliciet is opgenomen: welke
> gegevens de app/dienst verzamelt en opslaat; hoe de app/dienst gegevens gebruikt; voor welk
> doel; de bewaartermijn(en) en het beleid m.b.t. verwijdering; hoe gebruikers hun toestemming
> kunnen intrekken en hoe zij een verzoek tot verwijdering kunnen indienen?

**Antwoord: 🟢 JA — live op https://hub.emeq.nl/privacy.**

De privacyverklaring is publiek, indexeerbaar en DB-beheerd (admin → Beheer → Juridische
teksten, markdown). Ze beschrijft: welke gegevens (koppelingstokens **encrypted at rest**,
pass-through niet bewaard, audit-metadata), doel + rechtsgrond, bewaartermijnen (**90 dagen**
audit-metadata; tokens tot ontkoppeling), verwijderbeleid + verzoek tot verwijdering,
intrekken van toestemming (= Connection ontkoppelen), sub-verwerkers (OVH, Cloudflare)
en expliciet dat de Hub **verwerker** is. Bedrijfsgegevens ingevuld (Emeq B.V.,
Tokyostraat 17, 1175 RB Lijnden, KvK 84148691, BTW NL863113114B01).

Bron: `App\Support\LegalDefaults::privacyStatement()`, `LegalController`,
`resources/js/pages/legal.tsx`. Retentie-mechanisme: `MassPrunable` op `PassThroughCall` +
`InboundWebhookEvent`, dagelijkse `model:prune`, venster in `config/hub.php` (default 90 dagen,
env-overridebaar).

→ [#41](https://github.com/yusufkaracaburun/emeq-hub/issues/41)

### Vraag 2 — Expliciete toestemming voor verzamelen en gebruiken

> Vraagt uw app gebruikers expliciete toestemming om hun gegevens te mogen verzamelen en te
> gebruiken?

**Antwoord: 🟢 JA.**

**Onderbouwing**: koppelen kan uitsluitend via Exact's eigen OAuth2-autorisatiescherm. De
gebruiker ziet daar welke scopes de app vraagt en moet actief goedkeuren. Zonder die
toestemming is er geen `Connection` en dus geen toegang. Intrekken kan op elk moment — dat
revoket de tokens bij Exact én zet de Connection op `revoked`.

**Bewijs**: `app/Services/Exact/ExactOAuthFlow.php` (authorize + callback + revoke),
`app/Models/Connection.php` (status-lifecycle).

### Vraag 3 — Akkoord met het privacybeleid

> Vraagt uw app gebruikers of ze met uw privacybeleid akkoord gaan?

**Antwoord: 🟢 JA — twee paden, beide geborgd.**

1. **Browser-flow** (gebruiker koppelt zelf via de Hub) → de publieke `/koppelen`-intake
   heeft een **verplichte consent-checkbox** met link naar `/privacy`; zonder akkoord geen
   submit (`accepted`-validatie). Het akkoord wordt vastgelegd als tijdstip
   (`access_requests.privacy_accepted_at`). Bron: `AccessRequestForm`,
   `StoreAccessRequestRequest`, `AccessRequestController`.
2. **Server-to-server** (een Consumer start `/init` namens een Account) → daar is geen
   browser en dus geen checkbox. Het akkoord ligt bij de **Consumer**, contractueel geborgd
   via de verwerkersovereenkomst en beschreven in de consumer-integratiegids.

De verwerkersovereenkomst is sinds 2026-08-07 ook publiek gepubliceerd op
`/verwerkersovereenkomst` (live op https://hub.emeq.nl/verwerkersovereenkomst) —
DB-beheerde markdown via dezelfde pijplijn als `/privacy` en `/voorwaarden`.

Beide expliciet — geen doen-alsof-één-pad.

→ [#42](https://github.com/yusufkaracaburun/emeq-hub/issues/42)

### Vraag 4 — Encryptie tegen ongeoorloofde toegang

> Beveiligt u de gegevens die uw app of gekoppelde systemen verzamelen, verwerken of opslaan,
> tegen ongeoorloofde toegang, gebruik of verstrekking (bijvoorbeeld door middel van encryptie)?

**Antwoord: 🟢 JA.** Dit is de sterkste kaart die je hebt. Wees concreet:

| Maatregel | Bewijs |
|---|---|
| OAuth-tokens **encrypted at rest** | `app/Models/Connection.php` — `encrypted`-casts op `access_token`, `refresh_token`, `client_key` |
| App-credentials **encrypted at rest** | `app/Settings/ExactSettings.php` — client_id/secret in de DB via spatie/laravel-settings, niet in `.env` |
| **Geen** rauwe secrets in logs | fingerprint-only (sha256, eerste 12 tekens) |
| Webhooks **HMAC-geverifieerd** | `verify.exact.signature`-middleware (uppercase hex) |
| Webhook-audit **metadata-only** | `InboundWebhookRecorder` → `inbound_webhook_events`; payload wordt bewust **niet** opgeslagen (AVG — de Hub is verwerker) |
| Multi-tenant isolatie | strikt `Consumer → Account → Connection`; nooit resolven via query-string of header zonder Consumer-validatie |
| API-auth | Sanctum PAT's met abilities (`integrations:manage`) |
| TLS in transit | Cloudflare-edge → tunnel (versleuteld) → app |
| Rate limiting | `throttle:api` op `/v1/*` |
| Idempotency | `Idempotency-Key`-header, verplicht op accounting-documents |

### Vraag 5 — Wijzigingen gevalideerd en getest vóór doorvoeren

> Heeft u een proces geïmplementeerd waarbij wijzigingen aan de app/dienst eerst worden
> gevalideerd en getest voordat ze worden doorgevoerd?

**Antwoord: 🟢 JA — afgedwongen poort, met run-history.**

Elke push draait een GitHub Actions-workflow (`.github/workflows/ci.yml`) die vier stappen als
faalpoort uitvoert vóórdat een wijziging naar productie kan: **Pint** (`--test`, code-style),
**~950 PHPUnit-tests**, **`composer audit`** (dependency-vulnerabilities) en **ShellCheck** op
de provisioning-scripts. Faalt één stap, dan is de run rood en gaat de merge niet door.

Daarbovenop de werkwijze: feature-branch → groen → fast-forward-merge naar `master` → deploy,
met een `branch-guard`-hook die directe edits op `master` blokkeert. Het is nu een proces met
zichtbare run-history, geen handmatige belofte.

**Bewijs**: `.github/workflows/ci.yml`; run-history op GitHub Actions.

→ [#44](https://github.com/yusufkaracaburun/emeq-hub/issues/44)

### Vraag 6 — Wijzigingsgeschiedenis

> Heeft u een systeem geïmplementeerd waarmee u de wijzigingsgeschiedenis beheert (zodat u
> bijvoorbeeld wijzigingen kunt traceren)?

**Antwoord: 🟢 JA.**

- **Code**: git, Conventional Commits, elke wijziging herleidbaar tot commit + reden.
- **Runtime**: `pass_through_calls` — één immutable rij per Consumer → Hub → Partner-call.
- **Webhooks**: `inbound_webhook_events` — getypeerde metadata (provider/topic/action/
  outcome/status/fanout) voor incident-triage.
- **Migraties**: forward-only in productie.

### Vraag 7 — Best practices voor beveiligd coderen (OWASP)

> Hanteert u best practices voor beveiligd coderen (zoals OWASP voor mobiele applicaties)?

**Antwoord: 🟢 JA.**

- SQL-injectie: alle DB-toegang via Eloquent/query-builder, geen string-geconcateneerde SQL.
- CSRF-bescherming op alle web-routes (Laravel default).
- Signed URLs op de OAuth-landing (blokkeert tampering en enumeratie).
- HMAC-signature-verificatie op elke inkomende webhook.
- Open-redirect-guard op de return-to-consumer-flow (`ReturnUrlResolver`).
- Secrets nooit in git; `.env*` gitignored.
- Dependency-scanning via `composer audit` (zie vraag 8).

### Vraag 8 — Regelmatige vulnerability-evaluatie

> Evalueert u uw app/dienst regelmatig om kwetsbaarheden te identificeren, te analyseren en te
> verhelpen?

**Antwoord: 🟢 JA — proces, niet belofte.**

`composer audit` meldt nu **"No security vulnerability advisories found"**: de eerder open 30
advisories (4 high) zijn getrieerd — gepatcht, of onderbouwd genegeerd in `composer.json`
([#33](https://github.com/yusufkaracaburun/emeq-hub/issues/33)). De audit draait niet alleen
ad-hoc: hij staat als **faalstap in CI** (`.github/workflows/ci.yml`), dus elke push wordt
opnieuw tegen de advisory-database gehouden en een nieuw lek breekt de build.

**Bewijs**: `composer audit` (lokaal schoon), `.github/workflows/ci.yml` stap "Composer security
audit", ignore-lijst met motivatie in `composer.json`.

→ [#43](https://github.com/yusufkaracaburun/emeq-hub/issues/43)

### Vraag 9 — Data in eigen of cloud datacenter

> Bewaart u de data die u ontvangt via de app/dienst in uw eigen, of cloud data center?
> Bijvoorbeeld AWS, Azure, Leaseweb, TransIP etc.

**Antwoord: 🟢 JA** — OVH, EU-regio.

> ⚠️ **Dit "ja" ontgrendelt vraag 10, 11 en 12.** Dat is geen valstrik, maar het is wel de
> reden waarom de Hub op een **dedicated** VPS hoort en niet naast andere applicaties.

### Vraag 10 — Logische toegang alleen voor bevoegden

> Hebt u processen en authenticatiemaatregelen geïmplementeerd, zodat alleen bevoegden logische
> toegang tot uw app/dienst hebben?

**Antwoord: 🟢 JA** — mits de VPS goed staat. Onderbouwing:

- Dedicated host: **alleen** de Hub draait erop.
- **Nul open poorten**. Verkeer loopt via een Cloudflare Tunnel (uitgaande connector), dus
  poort 80/443 staan niet open en het origin-IP is niet publiek.
- SSH: key-only, wachtwoord-login uit, root-login uit.
- App-toegang: Sanctum-PAT's per Consumer; admin via Filament met rollen
  (`super-admin` / `staff` / `boekhouder`).
- Secrets: `.env.prod` met `chmod 600`, gitignored, nooit in de image gebakken.
- Database + Redis: geen gepubliceerde poorten, alleen bereikbaar binnen het compose-netwerk.

**Backups**: de prod-database-dumps zijn nu **encrypted at rest** en worden **off-site**
weggeschreven (niet langer alleen op de server-schijf), scheduled via de `scheduler`-service
([#49](https://github.com/yusufkaracaburun/emeq-hub/issues/49) — afgerond). Wie bij de dumps
kan valt daarmee onder dezelfde logische-toegangscontrole als de rest van de host.

→ [#37](https://github.com/yusufkaracaburun/emeq-hub/issues/37)

### Vraag 11 — Fysieke toegang alleen voor bevoegden

> Hebt u processen geïmplementeerd, zodat alleen bevoegden fysieke toegang tot uw app/dienst
> hebben?

**Antwoord: 🟢 JA** — via de hostingprovider (OVH). Fysieke toegangscontrole tot het
datacenter valt onder OVH's ISO 27001-certificering (cert 37383-7) én is expliciet
getoetst in de **ISAE 3000 / CSA C5 Type II Datacenter**-verklaring die bij vraag 12 is
bijgevoegd. Beide zijn OVH-attestaties, niet die van Emeq.

### Vraag 12 — Verklaring van derden (ISO 27001, ISAE 3402, SOC 1/2)

> Hebt u een verklaring van derden beschikbaar, zoals ISO 27001, ISO 27002, ISAE 3402, SOC 1
> of SOC 2?

**Antwoord: 🟢 JA, indirect — verklaring bijgevoegd.**

Emeq zelf is niet ISO 27001-gecertificeerd. De hostingprovider (OVH) is dat wel voor de
cloud-diensten en datacenters waarop de Hub draait. De actuele verklaring is bij OVH
opgevraagd (support-ticket **CS16314299**, 2026-07-20) en op 2026-07-23 ontvangen.

**Bijgevoegde documenten:**

| Document | Wat het dekt |
|---|---|
| **ISO/IEC 27001:2022-certificaat 37383-7** (OVH Groupe, Roubaix) | ISMS voor *"design, development and security activities for cloud service offerings"* — VPS valt onder dit cloud-aanbod. Geldig **12-02-2026 t/m 03-02-2027**. Certificerende instantie BYCYB, COFRAC-geaccrediteerd (nr. 4-0660), LNE-merk. Annex rév. 7 lijst de OVH-datacenters, incl. **Limburg (DE)**. |
| **ISAE 3000 / CSA C5 Type II — Datacenter** | fysieke + logische datacenter-controls (onderbouwt vraag 10 en 11). |
| SOC 2 / CSA C5 — Public Cloud (Part 1+2) | ⚠️ dekt OVH **Public Cloud**, niet de VPS-productlijn. Meegestuurd voor volledigheid, niet als hoofdbewijs. |

**Waar de Hub draait**: OVH VPS-3, region **Frankfurt (DE)** / OpenStack-zone `os-de2`
(OVH's Duitse datacenter-operatie). De ISO-scope is *offering-based* ("cloud service
offerings"), dus de VPS valt onder cert 37383-7 ongeacht de exacte site; de Duitse OVH-DC
(**Limburg**) staat als "OVH DC" in de annex.

> ℹ️ In de annex staat "Frankfurt (DE)" als *Bureau* (kantoor) en "Limburg (DE)" als *OVH DC* —
> OVH's Duitse DC is Limburg, "Frankfurt" is de commerciële region-naam. Als Exact naar de
> exacte fysieke locatie vraagt: dat is de OVH-DC Limburg. Desgewenst bij OVH bevestigen.

Formuleer zonder te suggereren dat Emeq zelf gecertificeerd is — de certificering is die van
OVH als hostingprovider.

---

## 4. Automatische koppelingen met derden

> Geef aan met welke derde partijen je gegevens uitwisselt en geef voor elke partij het doel
> aan en welke gegevens worden uitgewisseld.

| Partij | Doel | Welke gegevens |
|---|---|---|
| **Consumer-apps** (emeq-app, planny, naschool) | de Hub forwardt partner-webhooks en API-antwoorden terug naar de SaaS-app die de koppeling gebruikt | financiële mutaties, relatie- en grootboekgegevens — uitsluitend van de eigen Account van die Consumer |
| **Cloudflare** | TLS-terminatie, DDoS-mitigatie, tunnel | verkeer in transit; geen opslag |
| **OVH** | hosting van applicatie en database (EU) | alle opgeslagen gegevens (zie vraag 9–12) |
| **Exact Online** | de gekoppelde partner-API zelf — bron én bestemming van de boekingen | verkoop-/inkoopmutaties, relaties, grootboek van de gekoppelde administratie |

Vul aan met de andere partner-API's zodra die live gaan (Mollie, Snelstart, Moneybird).

---

## 5. Contactgegevens

Al ingevuld in het formulier: Yusuf Karacaburun · 0624392795 · info@emeq.nl.

---

## Wat er nog tussen jou en "Submit" staat

| # | Blokker | Issue | Status |
|---|---|---|---|
| 1 | App-host `hub-dev` → `hub.emeq.nl` | [#38](https://github.com/yusufkaracaburun/emeq-hub/issues/38) | ✅ dicht |
| 2 | Privacybeleid (vraag 1) | [#41](https://github.com/yusufkaracaburun/emeq-hub/issues/41) | ✅ live |
| 3 | Consent in de connect-flow (vraag 3) | [#42](https://github.com/yusufkaracaburun/emeq-hub/issues/42) | ✅ |
| 4 | 30 composer-advisories, 4 high (vraag 8) | [#43](https://github.com/yusufkaracaburun/emeq-hub/issues/43) | ✅ |
| 5 | CI (vraag 5) | [#44](https://github.com/yusufkaracaburun/emeq-hub/issues/44) | ✅ |
| 6 | VPS + tunnel (vraag 9–12) | [#37](https://github.com/yusufkaracaburun/emeq-hub/issues/37) | ✅ live |
| 7 | OVH ISO 27001 + ISAE 3000 (vraag 12) | ticket CS16314299 | ✅ ontvangen 2026-07-23 |
| 8 | Scope-matrix invullen + 4 ⓘ-scopes verifiëren | [#39](https://github.com/yusufkaracaburun/emeq-hub/issues/39) | ⬜ open |
| 9 | Submit for review | [#50](https://github.com/yusufkaracaburun/emeq-hub/issues/50) | ⬜ laatste actie |

Alleen rij 8 en 9 resteren — beide portaal-werk. Vul de scopes in, submit, en voeg de drie
OVH-PDF's toe bij vraag 12. Een afgewezen review kost meer tijd dan het netjes afronden hiervan.
