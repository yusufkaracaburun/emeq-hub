# Een Consumer aan de Hub koppelen

Hoe een SaaS-app Consumer van de emeq Hub wordt, en hoe die app vervolgens zijn
eigen eindklanten laat koppelen aan Exact, Mollie, Snelstart, …

Twee lezers, twee delen:

- **Deel A — Hub-kant.** Wat Emeq doet om een Consumer aan te melden. Intern.
- **Deel B — Consumer-kant.** Het integratiecontract dat elke consumer-app moet
  nakomen, ongeacht stack. Deelbaar met derden.
- **Deel C** is een uitgewerkt voorbeeld (emeq/system, Laravel, subdomein-per-tenant).

De endpoint-voor-endpoint-walkthrough staat **niet** hier maar in
[`consumer-integration-guide.md`](consumer-integration-guide.md) — die blijft de
enige plek waar request/response-shapes leven. Dit document beschrijft de rollen,
de volgorde en de invarianten eromheen.

Laravel-apps: installeer
[`emeq/hub-sdk`](https://github.com/yusufkaracaburun/emeq-hub-sdk) — provider-
agnostisch; nieuwe Hub-partners vragen geen SDK-release. Partner-packages
(`exact-api`, …) blijven Hub→partner.

---

## Rolmodel

| Begrip | Betekenis | Wie beheert het |
|---|---|---|
| **Consumer** | Eén SaaS-app. Authentiseert met een PAT. | Emeq (Hub-admin) |
| **Account** | Eén eindklant/tenant *binnen* die app, gesleuteld op de door de Consumer gekozen `external_id`. | Consumer, via de API |
| **Connection** | Eén koppeling tussen één Account en één provider. Tokens encrypted at rest. | Hub |
| **Provider** | Exact, Mollie, Snelstart, … | Emeq |

Eén ketting, strikt: **Consumer → Account → Connection**. De Hub leidt de
Consumer altijd af uit de Bearer-PAT, nooit uit een parameter. Een Account van
Consumer A is onbereikbaar met de PAT van Consumer B.

### Wat de Hub níét weet

De Hub kent de eindgebruikers van een Consumer niet. Er is geen Hub-login voor
hen, geen rollenmodel, geen "mag deze medewerker koppelen?".

Daaruit volgt de belangrijkste verantwoordelijkheidsgrens:

> **De Consumer bepaalt wie welke Account mag aanraken. De Hub controleert
> alleen dát de PAT bij de Consumer hoort.**

Een consumer-app die een `external_id` uit een request-body of header overneemt
zonder die te toetsen aan de ingelogde gebruiker, geeft elke gebruiker toegang
tot de boekhouding van elke andere tenant. Zie [B2](#b2--account-id-hoort-aan-de-serverkant-thuis).

---

# Deel A — Hub-kant

Checklist per nieuwe Consumer.

### A1. Consumer aanmaken

Twee paden, beide via `App\Services\ConsumerOnboarding` (Consumer + PAT in één
transactie):

- **`/admin` → Onboard consumer** — wizard, kan `app_url`, webhook-URL en
  webhook-secret meteen zetten. Standaardpad.
- **`php artisan hub:consumer:create --slug= --name= --abilities=`** — CLI, voor
  scripted/prod-setup. Let op: deze command zet **geen** `app_url`; die moet je
  daarna in `/admin` invullen (A2).

Velden die ertoe doen:

| Veld | Waarvoor |
|---|---|
| `name` / `slug` | Identificatie in admin en audit |
| `app_url` | Basisdomein van de Consumer — bepaalt waar de gebruiker na OAuth-consent heen mag |
| `webhook_callback_url` | Optioneel. Waar de Hub partner-events naartoe fan-out |
| `webhook_callback_secret` | Optioneel, encrypted. HMAC-signing van die fan-out |

### A2. `app_url` — de open-redirect-guard

Zonder `app_url` valt de terugkeer na consent terug op de Hub zelf; de gebruiker
landt niet in de consumer-app.

De guard (`app/Integrations/OAuth/ReturnUrlResolver.php`) accepteert een
`return_url` of browser-`Origin` als de host:

- gelijk is aan de `app_url`-host, **of**
- een subdomein is van hetzelfde registreerbare basisdomein.

Prioriteit: expliciete `return_url` → `Origin` van de init-call → `app_url`
(alleen bij OAuth-init). De hosted `/connect`-handoff (`POST /v1/connect-sessions`)
valt **niet** terug op bare `app_url` — anders landt "Terug naar …" op het
marketingdomein wanneer de consumer-host buiten de guard valt (lokaal /
eigen domein). Zonder geldige return-URL gebruikt de connect-pagina
`document.referrer`.

Gevolg voor multi-tenant consumers: één `app_url` op het basisdomein
(`https://voorbeeld.nl`) dekt álle tenant-subdomeinen (`klant1.voorbeeld.nl`, …).
Geen registratie per tenant.

**Uitzondering:** draait een tenant op een eigen domein (`planning.klantx.nl`),
dan valt die buiten de guard. Dan is een aparte Consumer nodig, of een
expliciete uitbreiding van de guard. Vraag dit uit vóór je de Consumer aanmaakt.

### A3. PAT uitgeven

`/admin` → Consumer → **Issue PAT** → preset kiezen. Het token is één keer
zichtbaar.

Presets (`ConsumerResource::PAT_PRESETS`):

| Preset | Abilities | Wanneer |
|---|---|---|
| `accounting-connect` | `integrations:manage`, `accounting:read`, `accounting:write`, `consumer:manage-accounts` | **Standaard voor een boekhoud-consumer.** Koppelen + provider-onafhankelijk lezen/boeken |
| `accounting-read` | `accounting:read` | Alleen uitlezen |
| `accounting-write` | `accounting:read`, `accounting:write`, `consumer:manage-accounts` | Boeken zonder zelf te koppelen |
| `integrations` | `integrations:manage`, `consumer:manage-accounts` | Alleen de koppel-lifecycle |
| `exact-*` / `mollie-* `/ `snelstart-*` | provider-specifieke read/write/connect | Consumer wil rechtstreekse pass-through op één provider |
| `admin` | `admin` | Intern |

Eén PAT per Consumer, niet per tenant. De tenant-scheiding zit in het Account,
niet in het token.

Geef het smalste preset dat de use-case dekt. Een consumer die alleen de
canonieke boekhoud-API gebruikt heeft géén `exact:*` nodig — die abilities geven
ongefilterde pass-through naar de partner-API.

### A4. Provider-beschikbaarheid

Providers staan aan/uit via Pennant (`feature.provider:{provider}` op `/v1/*`),
afgeleid uit `config/hub-providers.php`. Dit is een **globale kill-switch per
provider**, geen per-consumer-toggle. Een uitgezette provider geeft `503
provider_disabled`, ook met een geldige PAT.

### A5. Rooktest vóór overdracht

Verifieer met de verse PAT, vanaf het domein van de Consumer, in deze volgorde:

```bash
curl -s -X POST "$HUB/v1/accounts" \
  -H "Authorization: Bearer $PAT" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"external_id":"rooktest","display_name":"Rooktest"}'

curl -s "$HUB/v1/integrations?account_external_id=rooktest" \
  -H "Authorization: Bearer $PAT" -H 'Accept: application/json'
```

Verwacht `201`/`409` en daarna een providerlijst. Faalt dit, dan is er geen
enkel punt om aan de consumer-kant te gaan bouwen. Zonder
`Content-Type: application/json` parseert Laravel de body niet en krijg je
`external_id` required.

### A6. Overdracht

De Consumer krijgt: base-URL, PAT, de gekozen abilities, en een verwijzing naar
de integratiehandleiding en naar `/docs/api`. Verder niets — geen provider-
credentials, geen Hub-interne details. Geen `/admin`-login voor derden — de
Hub-admin blijft Emeq-intern.

---

# Deel B — Consumer-kant

Vier eigenschappen die elke consumer-integratie moet hebben. Stack-onafhankelijk;
alleen de invulling verschilt.

### B1 — De PAT blijft server-side

De PAT authentiseert de héle Consumer. Lekt hij, dan liggen alle tenants open.

```
Browser (eindgebruiker) → consumer-backend → Hub /v1/*
                          voegt Authorization: Bearer <PAT> toe
```

CORS op de Hub laat elke https-origin toe, dus een directe browser→Hub-call
wérkt — en lekt het token. Altijd via de eigen backend (of `emeq/hub-sdk`
BFF-routes achter je eigen auth).

### B2 — Account-id hoort aan de serverkant thuis

De Hub accepteert de Account-aanduiding als `account_external_id` (OAuth,
discovery) of `X-Account-Id` (accounting, pass-through), en vertrouwt die op
gezag van de PAT. De Hub kán niet zien of de juiste eindgebruiker erachter zat.

**Leid de waarde daarom altijd server-side af, uit de geauthenticeerde sessie of
uit de tenant-context — nooit uit een client-parameter die de gebruiker kan
zetten.**

| Tenancy-model | Afleiding |
|---|---|
| Subdomein per tenant | Host → tenant-lookup, server-side |
| Pad-prefix (`/t/{slug}`) | Route-param → lookup **plus** check dat de gebruiker lid is van die tenant |
| Eén DB, tenant aan de gebruiker | Tenant-id uit sessie/JWT-claim |
| Single-tenant app | Vaste constante |

Kies als `external_id` een sleutel die niet wijzigt: een interne id, niet een
e-mailadres, bedrijfsnaam of domein.

Dezelfde regel geldt voor het loskoppelen: een `connection_id` uit de request
mag alleen gehonoreerd worden nadat je hebt vastgesteld dat die Connection bij
de tenant van de ingelogde gebruiker hoort.

### B3 — Providerlijst data-driven renderen

`GET /v1/integrations` levert per provider `key`, `label`, `tagline`,
`category`, `logo`, `brand`, `connectable` en `status`. Render daarop.

Hardcode geen providerlijst en geen per-provider-takken. Een provider die de Hub
morgen toevoegt, verschijnt dan vanzelf — dat is de reden dat de Hub bestaat.
`connectable: false` = tonen, maar zonder koppelknop.

### B4 — Privacy-akkoord vóór de koppeling

`POST /v1/oauth/{provider}/init` is server-to-server: de Hub rendert in dit pad
géén consent-pagina. De Consumer haalt daarom zelf het akkoord op het
Hub-privacybeleid (<https://hub.emeq.nl/privacy>) op vóór de koppeling start —
in de praktijk een verplichte checkbox in het eigen koppelscherm. Contractueel
geborgd via de verwerkersovereenkomst tussen Emeq en de Consumer.

### De flow zelf

Vijf stappen, uitgeschreven met request/response in
[`consumer-integration-guide.md`](consumer-integration-guide.md):

1. **Account registreren** — `POST /v1/accounts`. `409` = bestaat al, geen fout.
   Optioneel: `init` provisiont het Account desnoods zelf.
2. **Integraties tonen** — `GET /v1/integrations?account_external_id=…`
3. **Koppelen** — `POST /v1/oauth/{provider}/init` → browser naar `redirect_url`
4. **Terugkomst** — poll `GET /v1/connections/{id}` tot `status: "active"` en
   `revoked_at: null`
5. **Loskoppelen** — `DELETE /v1/connections/{id}` → `204`. De Hub doet de
   volledige teardown (token-revoke + webhook-subscriptions); de Consumer ruimt
   niets zelf op.

Daarna boeken: `POST /v1/accounting/documents` met `X-Account-Id` en
`Idempotency-Key`, bij voorkeur voorafgegaan door een dry-run op
`/v1/accounting/documents/validate`.

### Wat de Consumer níét opslaat

Geen tokens, geen connection-state, geen provider-credentials. De Hub is de
bron. Sla hooguit een afgeleide vlag op ("heeft boekhoudkoppeling") om
UI-flikkering te vermijden, en ververs die uit `/v1/integrations`.

---

# Deel C — Uitgewerkt: emeq/system

Laravel 13, subdomein per tenant (`klant.voorbeeld.nl`), database per tenant.
`Instance` is het tenant-model; de tenant wordt geresolved uit de host.

**Mapping:** Consumer = de hele app · Account = één `Instance`
(`external_id = instance.id`) · Connection = Instance ↔ Exact.

**Hub-kant:** `app_url` op het basisdomein (dekt alle 12 tenant-subdomeinen),
één PAT met preset `accounting-connect`.

**system-kant, vier stukken:**

1. **Package** — `composer require emeq/hub-sdk:^0.3` (VCS:
   `https://github.com/yusufkaracaburun/emeq-hub-sdk.git`). Env:
   `EMEQ_HUB_BASE`, `EMEQ_HUB_PAT`, `EMEQ_HUB_ROUTES=true`,
   `EMEQ_HUB_ROUTES_MIDDLEWARE=api,auth:api`,
   `EMEQ_HUB_OAUTH_RETURN_PATH=/configuration/integraties?emeq=return`.

2. **Account-binding** — implementeer `Emeq\HubSdk\Contracts\ResolvesAccountId`
   (server-side tenant → Hub `external_id`, B2). In system: `config('instance.id')`
   via `App\Integrations\Hub\HubAccountIdResolver`.

3. **BFF-routes** — komen uit het package (`EMEQ_HUB_ROUTES`). Alleen:
   `GET …/integrations` (optionele status) en
   `POST …/integrations/connect-session` (mint Hub `/connect`). Geen
   per-provider connect/destroy in de consumer.

4. **Instellingen-scherm** — één CTA die `connect-session` mint en doorstuurt
   naar Hub. Connect / disconnect / status beheer je op Hub `/connect`
   (single source of truth). Privacy-checkbox (B4) hoort vóór die CTA.

---

## Valkuilen

| Symptoom | Oorzaak |
|---|---|
| Gebruiker landt na consent op de Hub i.p.v. de eigen app | `app_url` leeg, of return-host valt buiten het basisdomein |
| `403` op `init` met een geldige `return_url` | Tenant op een eigen domein → buiten de open-redirect-guard |
| `return_url` wordt genegeerd | Param heet `return_url` (snake_case), niet `returnUrl` |
| `503 provider_disabled` | Globale Pennant-kill-switch staat uit voor die provider |
| `403 insufficient_ability` | PAT-preset dekt de aangeroepen route niet |
| `400` op accounting-calls | `X-Account-Id` ontbreekt |
| Tenant ziet andermans boekhouding | Account-id kwam uit een client-parameter (B2) |
| Nieuwe provider verschijnt niet in de UI | Providerlijst hardcoded i.p.v. data-driven (B3) |

Pennant (`feature.provider:{provider}`) is een **globale** kill-switch in Hub
`/admin` / config — geen per-tenant toggle in de consumer-UI.

## Zie ook

- [`emeq/hub-sdk`](https://github.com/yusufkaracaburun/emeq-hub-sdk) — Laravel
  consumer SDK (BFF `connect-session`, `Hub::integrations()`, accounting)
- [`consumer-integration-guide.md`](consumer-integration-guide.md) — endpoints,
  payloads, foutenvelope, agent-prompts
- `/docs/api` — OpenAPI-referentie, auto-gegenereerd
- [`unified-api-architecture.md`](unified-api-architecture.md) — waarom de
  canonieke laag zo gesneden is
