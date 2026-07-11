# Exact App Center — Beoordeling: gegevens en beveiliging

> **Wat is dit?** Het invulblad voor Exact's Data & Security-review. Per veld staat hier
> wat je invult, waaróm dat het antwoord is, en welk bestand in deze repo dat bewijst.
> Neem dit mee als Exact doorvraagt.
>
> **Status**: concept — drie vragen kunnen nog niet met "ja" beantwoord worden.
> Epic: [#36](https://github.com/yusufkaracaburun/emeq-hub/issues/36).
> **Laatst bijgewerkt**: 2026-07-11.

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

**Nederlands** (concept — 139 tekens):

```
Boekt verkoop- en inkoopfacturen uit gekoppelde SaaS-apps automatisch in Exact Online,
en houdt relaties en grootboek synchroon.
```

**Engels** (concept — 137 tekens):

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

**Over de DELETE-calls**: `crm/Accounts`, `salesentry/SalesEntries` en
`purchaseentry/PurchaseEntries` hebben DELETE-implementaties in de SDK, maar die worden
uitsluitend aangeroepen door `app/Console/Commands/Exact/PurgeTestData.php` — een
test-data-opruimcommando. Geen enkele productie-flow verwijdert iets bij Exact. Weeg dat
mee bij Lezen-vs-Beheren, en wees eerlijk als Exact ernaar vraagt.

---

## 3. Beoordeling van gegevensbescherming en beveiliging

### Vraag 1 — Openbaar privacybeleid

> Heeft u een openbaar toegankelijk privacybeleid waarin expliciet is opgenomen: welke
> gegevens de app/dienst verzamelt en opslaat; hoe de app/dienst gegevens gebruikt; voor welk
> doel; de bewaartermijn(en) en het beleid m.b.t. verwijdering; hoe gebruikers hun toestemming
> kunnen intrekken en hoe zij een verzoek tot verwijdering kunnen indienen?

**Antwoord vandaag: 🔴 NEE.** Dit bestaat niet.

**Wat nodig is** — een publieke pagina op `hub.emeq.nl` die per entiteit beschrijft wat er
wordt verwerkt:

| Entiteit | Wat er in staat |
|---|---|
| `Consumer` | de SaaS-app die de Hub gebruikt (naam, callback-URL, app-URL) |
| `Account` | eindgebruiker bij die Consumer (`consumer_id` + `external_id`) |
| `Connection` | de OAuth-koppeling: **encrypted** tokens, scopes, expiry |
| `PassThroughCall` | audit-log van elke Consumer → Hub → Partner-call |
| `InboundWebhookEvent` | **metadata-only** audit van partner-webhooks (bewust géén payload) |

Plus: rechtsgrond, bewaartermijnen, verwijderbeleid, intrekken van toestemming (= Connection
revoken via `OAuthFlow::revoke()`), sub-verwerkers (Cloudflare, OVH, de partner-API's), en
expliciet dat de Hub **verwerker** is en niet verwerkingsverantwoordelijke.

**Let op — bewaartermijnen zijn nu ongedefinieerd.** `pass_through_calls` en
`inbound_webhook_events` groeien onbeperkt. Je kunt geen bewaartermijn publiceren die je
niet handhaaft, dus daar hoort een opruim-command bij.

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

**Antwoord vandaag: 🔴 NEE.**

De OAuth-init (`/v1/oauth/{provider}/init`) en de publieke `/koppelen`-intake vragen nergens
om akkoord.

**Nuance die je in het antwoord moet maken**: er zijn twee paden.

1. **Browser-flow** (gebruiker koppelt zelf via de Hub) → hier hoort een checkbox met link
   naar het privacybeleid.
2. **Server-to-server** (een Consumer start `/init` namens een Account) → daar is geen
   browser en dus geen checkbox. Het akkoord hoort dan bij de **Consumer**, contractueel
   geborgd via de verwerkersovereenkomst en vastgelegd in de consumer-integratiegids.

Schrijf beide op. Doe niet alsof er één pad is.

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

**Antwoord: 🟡 JA — maar maak het verifieerbaar vóór je submit.**

**Vandaag**: ~950 geautomatiseerde tests (PHPUnit). Werkwijze is feature-branch → tests groen
→ fast-forward-merge naar `master` → deploy. Een `branch-guard`-hook blokkeert directe edits
op `master`. Code-style wordt afgedwongen met Pint.

**Het gat**: er is **geen CI**. Het is handmatige discipline, niet een afgedwongen poort. Dat
is precies wat deze vraag uitvraagt. Met een GitHub Actions-workflow wordt het antwoord
"ja, en hier is de run-history".

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

**Antwoord vandaag: 🔴 NEE — niet eerlijk met "ja" te beantwoorden.**

`composer audit` bestaat en draait, maar meldt op dit moment **27 advisories, waarvan 2 high**
([#33](https://github.com/yusufkaracaburun/emeq-hub/issues/33)). Een "ja" invullen terwijl die
openstaan is precies het soort antwoord waar een reviewer op doorvraagt.

**Wat je nodig hebt vóór "ja"**: de 2 high dicht, de rest getrieerd (gepatcht óf onderbouwd
genegeerd in `composer.json`), en `composer audit` als faalstap in CI — dán is het een proces
in plaats van een belofte.

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

**Wees eerlijk over de backups** — die staan nu op dezelfde schijf
([#49](https://github.com/yusufkaracaburun/emeq-hub/issues/49)).

→ [#37](https://github.com/yusufkaracaburun/emeq-hub/issues/37)

### Vraag 11 — Fysieke toegang alleen voor bevoegden

> Hebt u processen geïmplementeerd, zodat alleen bevoegden fysieke toegang tot uw app/dienst
> hebben?

**Antwoord: 🟢 JA** — via de hostingprovider (OVH). Fysieke toegangscontrole tot het
datacenter is onderdeel van hun dienstverlening en valt onder hun ISO 27001-certificering.
Verwijs naar de verklaring bij vraag 12.

### Vraag 12 — Verklaring van derden (ISO 27001, ISAE 3402, SOC 1/2)

> Hebt u een verklaring van derden beschikbaar, zoals ISO 27001, ISO 27002, ISAE 3402, SOC 1
> of SOC 2?

**Antwoord: 🟡 JA, indirect.**

Emeq zelf is niet ISO 27001-gecertificeerd. De hostingprovider (OVH) is dat wel voor de
datacenters en infrastructuur waarop de Hub draait.

**Actie**: download de actuele ISO 27001-verklaring van OVH en houd 'm bij de hand. Formuleer
het antwoord zonder te suggereren dat Emeq zelf gecertificeerd is — dat is precies het soort
overdrijving waar een reviewer op afrekent.

---

## 4. Automatische koppelingen met derden

> Geef aan met welke derde partijen je gegevens uitwisselt en geef voor elke partij het doel
> aan en welke gegevens worden uitgewisseld.

| Partij | Doel | Welke gegevens |
|---|---|---|
| **Consumer-apps** (emeq-app, planny, naschool) | de Hub forwardt partner-webhooks en API-antwoorden terug naar de SaaS-app die de koppeling gebruikt | financiële mutaties, relatie- en grootboekgegevens — uitsluitend van de eigen Account van die Consumer |
| **Cloudflare** | TLS-terminatie, DDoS-mitigatie, tunnel | verkeer in transit; geen opslag |
| **OVH** | hosting van applicatie en database (EU) | alle opgeslagen gegevens (zie vraag 9–12) |

Vul aan met de andere partner-API's zodra die live gaan (Mollie, Snelstart, Moneybird).

---

## 5. Contactgegevens

Al ingevuld in het formulier: Yusuf Karacaburun · 0624392795 · info@emeq.nl.

---

## Wat er nog tussen jou en "Submit" staat

| # | Blokker | Issue |
|---|---|---|
| 1 | App staat op `hub-dev.emeq.nl`, moet `hub.emeq.nl` | [#38](https://github.com/yusufkaracaburun/emeq-hub/issues/38) |
| 2 | Privacybeleid bestaat niet (vraag 1) | [#41](https://github.com/yusufkaracaburun/emeq-hub/issues/41) |
| 3 | Geen consent in de connect-flow (vraag 3) | [#42](https://github.com/yusufkaracaburun/emeq-hub/issues/42) |
| 4 | 27 composer-advisories, 2 high (vraag 8) | [#43](https://github.com/yusufkaracaburun/emeq-hub/issues/43) |
| 5 | Geen CI (vraag 5) | [#44](https://github.com/yusufkaracaburun/emeq-hub/issues/44) |
| 6 | VPS + tunnel (vraag 9–12) | [#37](https://github.com/yusufkaracaburun/emeq-hub/issues/37) |
| 7 | Vier ⓘ-scopes verifiëren | [#39](https://github.com/yusufkaracaburun/emeq-hub/issues/39) |

Werk ze af, werk dit document bij, en submit dan pas. Een afgewezen review kost meer tijd dan
het dichttrekken van deze zeven.
