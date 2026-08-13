# emeq Hub — Consumer-integratiehandleiding

Voor ontwikkelaars die een (multi-tenant) consumer-app aan de emeq Hub koppelen.
Eén integratie → alle huidige én toekomstige providers (Exact, Mollie, …). Nieuwe
providers verschijnen vanzelf; je past je code niet aan per provider.

Laravel-consumers: gebruik **[`emeq/hub-sdk`](https://github.com/yusufkaracaburun/emeq-hub-sdk)**
(provider-agnostische Saloon-client). Partner-SDK’s (`emeq/exact-api`, …) blijven
Hub-intern — niet in de consumer-app.

> Altijd-actuele API-referentie: **`/docs/api`** (OpenAPI, auto-gegenereerd).
> Deze handleiding is de narratieve laag eromheen.

> 🤖 **Agent-prompts** — elke sectie sluit af met een copy-paste-prompt voor je
> AI-coding-agent. Vervang `{…}`-placeholders, plak in je agent, en laat 'm dat
> stuk in je consumer-app bouwen. De harde regels (PAT server-side, account-id
> server-side afleiden, data-driven rendering, snake_case-params) zitten in de
> prompts verwerkt.
>
> Liever in één keer de hele koppel-flow? Gebruik
> [Snelstart — één prompt voor je AI-agent](#snelstart--één-prompt-voor-je-ai-agent).

## Inhoudsopgave

- [Snelstart — één prompt voor je AI-agent](#snelstart--één-prompt-voor-je-ai-agent)
- [Concepten](#concepten)
- [Auth — backend-proxy (aanbevolen)](#auth--backend-proxy-aanbevolen)
- [Stap 1 — Account registreren](#stap-1--account-registreren-eenmalig-per-tenant)
- [Stap 2 — Integraties tonen (discovery)](#stap-2--integraties-tonen-discovery)
- [Stap 3 — Koppelen](#stap-3--koppelen)
- [Stap 4 — Terugkomst + status](#stap-4--terugkomst--status)
- [Stap 5 — Loskoppelen](#stap-5--loskoppelen)
- [Boekhouden — documenten valideren & boeken](#boekhouden--documenten-valideren--boeken)
  - [Boekhoud-mapping (zelf-service, optioneel)](#boekhoud-mapping-zelf-service-optioneel)
- [Webhooks ontvangen](#webhooks-ontvangen)
- [Valkuilen](#valkuilen)

## Snelstart — één prompt voor je AI-agent

Wil je de koppel-flow in één keer laten bouwen: vul de `{…}`-placeholders in en
geef onderstaande prompt aan je coding-agent, samen met de URL van deze pagina.
Het levert stap 1 t/m 5 op (registreren → tonen → koppelen → status → loskoppelen).

Boeken, mapping-UI en webhooks staan bewust niet in deze prompt — die hebben hun
eigen prompt verderop, en je wilt eerst een werkende koppeling zien.

```text
Bouw in mijn {stack}-app een integratie met de emeq Hub, zodat elke tenant zijn
eigen boekhoudpakket (Exact Online, …) kan koppelen. Lees eerst
{https://hub.emeq.nl/docs/api} voor de exacte request/response-shapes.

CONTEXT
- Base-URL: {https://hub.emeq.nl}. Auth: één Personal Access Token (PAT) voor
  mijn hele app, uit env `{EMEQ_PAT}`. Alle calls: `Authorization: Bearer <PAT>`
  + `Accept: application/json`. Geen cookies.
- De Hub kent mijn eindgebruikers niet. Hij vertrouwt de Account-aanduiding op
  gezag van de PAT. Mijn app bepaalt dus wie welke tenant mag aanraken.
- Mijn tenants worden onderscheiden door: {beschrijf: subdomein / pad-prefix /
  tenant-id op de ingelogde gebruiker / single-tenant}.
- Als Account-sleutel (`external_id`) gebruik ik: {stabiele interne tenant-id —
  geen e-mail, bedrijfsnaam of domein, want die wijzigen}.

HARDE EISEN — hier niet van afwijken
1. De PAT blijft server-side. Bouw een proxy in mijn backend; de browser praat
   nooit rechtstreeks met de Hub. (CORS staat het toe — en lekt het token.)
2. Leid `X-Account-Id` en `account_external_id` server-side af uit de
   tenant-context hierboven. NOOIT overnemen uit een request-header, body of
   query: anders boekt tenant A in de administratie van tenant B.
3. Render de providerlijst volledig data-driven op de velden die de Hub
   teruggeeft. Geen hardcoded providerlijst, geen `if (provider === 'exact')`.
   Een provider die de Hub morgen toevoegt moet vanzelf verschijnen.
4. Toon vóór het koppelen een verplichte akkoord-checkbox met een link naar
   {https://hub.emeq.nl/privacy}. Zonder vinkje geen connect-call.
5. Sla geen tokens, connection-state of provider-credentials op. De Hub is de
   bron; status komt live uit `GET /v1/integrations`.

TE BOUWEN
a. Proxy-route `/api/emeq/{path}` → `{BASE}/v1/{path}`. Injecteer de PAT en
   `Accept`. Forward query-string, JSON-body en de headers `Idempotency-Key` en
   `Prefer`. Zet `X-Account-Id` zelf, server-side (eis 2). Beveilig de route met
   mijn bestaande auth, plus een rolcheck: {wie mag koppelen/loskoppelen}.
b. Bij de eerste koppel-actie van een tenant éénmalig
   `POST /v1/accounts` met `{ external_id, display_name }`. Behandel `409` als
   "bestaat al", geen fout.
c. Instellingen-scherm: `GET /v1/integrations?account_external_id={tenant}` →
   kaarten met `label`, `tagline`, `logo`, `brand`, `category`. Gebruik `status`
   (connected/pending/disconnected) voor de knopstaat en toon `connectable:false`
   zonder koppelknop.
d. Koppelen: `POST /v1/oauth/{provider}/init` met body
   `{ account_external_id, return_url }` — let op: snake_case. Bouw `return_url`
   server-side uit mijn eigen host, niet uit de request-body. Redirect de browser
   naar de `redirect_url` uit de respons; bewaar `connection_id`.
e. Terugkomst: poll `GET /v1/connections/{id}` tot `status:"active"` en
   `revoked_at:null`, werk de UI bij naar "gekoppeld". Toon een wachtstaat zolang
   het `pending` is; stop na een redelijke timeout met "probeer opnieuw".
f. Loskoppelen: `DELETE /v1/connections/{id}`, verwacht `204`, UI terug naar
   niet-gekoppeld. Controleer eerst dat die Connection bij de tenant van de
   ingelogde gebruiker hoort. Doe zelf geen token- of webhook-opruiming — de Hub
   doet de volledige teardown.

FOUTAFHANDELING
Alle `/v1/*`-fouten zijn JSON met `{ code, message }`. Vertaal minstens:
`401 unauthenticated` (PAT stuk/ontbreekt), `403 insufficient_ability` (PAT mist
een ability), `404 unknown_provider` / `provider_not_connectable`,
`503 provider_disabled` (provider staat uit) naar nette meldingen.

TESTS
Schrijf minstens een test die bewijst dat een meegestuurde `X-Account-Id`-header
in de inkomende request wordt genegeerd en de server-side afleiding wint.
```

## Concepten

| Begrip | Betekenis |
|---|---|
| **Consumer** | Jouw app (admin.emeq.nl / admin.planny.nl / …). Authentiseert met een PAT. |
| **Account** | Een eindklant/tenant van jouw app, geïdentificeerd door jouw eigen `external_id` (bv. `bob`, `school1`). |
| **Connection** | Eén koppeling tussen één Account en één provider. |
| **Provider** | Boekhoud-/betaalpartner (Exact, Mollie, Snelstart, …). |

## Auth — backend-proxy (aanbevolen)

> ✅ **Live bewezen** met de emeq-app: Exact is end-to-end gekoppeld én geboekt
> via exact dit patroon (proxy + PAT + `X-Account-Id`).

De PAT is een **server-side secret** — zet 'm nooit in de browser. Patroon:

```
Browser (tenant-SPA)  →  jouw backend (/api/emeq/*)  →  emeq Hub (/v1/*)
                          injecteert Authorization: Bearer <PAT>
```

- PAT-ability: **`integrations:manage`** — één token koppelt + beheert alle
  providers. Aanvragen bij emeq (admin → Consumer → "Issue PAT" → preset
  *Integraties*). Boeken vereist daarnaast `accounting:write` (zie Boekhouden).
- Base-URL: `https://hub.emeq.nl` (prod) · `https://hub-dev.emeq.nl` (dev).
- Alle requests: `Authorization: Bearer <PAT>`, `Accept: application/json`.
  Geen cookies.

> CORS staat elke https-origin toe, dus een directe browser→Hub-call mét PAT
> werkt technisch ook — maar lekt je PAT. Gebruik de proxy.

### Backend-proxy (Laravel)

```php
Route::any('/api/emeq/{path}', function (Request $r, string $path) {
    return Http::withToken(config('services.emeq.pat'))
        ->withHeaders(array_filter([
            'Accept'          => 'application/json',
            // Multi-tenant? Vervang dit door een server-side afleiding — zie hieronder.
            'X-Account-Id'    => $r->header('X-Account-Id'),
            'Idempotency-Key' => $r->header('Idempotency-Key'),
            'Prefer'          => $r->header('Prefer'),
        ]))
        ->send($r->method(), config('services.emeq.base')."/v1/{$path}", [
            'query' => $r->query(),
            'json'  => $r->isJson() ? $r->json()->all() : null,
        ])->toPsrResponse();
})->where('path', '.*')->middleware('auth'); // jouw eigen tenant-auth
```

### Backend-proxy (Node/Express)

```js
app.use('/api/emeq', requireTenantAuth, async (req, res) => {
  const r = await fetch(`${process.env.EMEQ_BASE}/v1${req.url}`, {
    method: req.method,
    headers: {
      Authorization: `Bearer ${process.env.EMEQ_PAT}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
      // forward zoals aangeleverd:
      // Multi-tenant? Vervang dit door een server-side afleiding — zie hieronder.
      ...(req.get('X-Account-Id')    && { 'X-Account-Id': req.get('X-Account-Id') }),
      ...(req.get('Idempotency-Key') && { 'Idempotency-Key': req.get('Idempotency-Key') }),
      ...(req.get('Prefer')          && { Prefer: req.get('Prefer') }),
    },
    body: ['GET', 'HEAD'].includes(req.method) ? undefined : JSON.stringify(req.body),
  })
  res.status(r.status).send(await r.text())
})
```

### Multi-tenant: leid het Account server-side af

De voorbeelden hierboven forwarden `X-Account-Id` zoals aangeleverd. Dat mag
alleen als jouw proxy die waarde zelf zet of hem toetst aan de ingelogde
gebruiker.

De Hub kent jouw eindgebruikers niet en vertrouwt `X-Account-Id` en
`account_external_id` op gezag van de PAT. Neem je die klakkeloos over uit een
request-header, body of query, dan zet gebruiker van tenant A hem op tenant B en
boekt in diens administratie.

**Bepaal de Account-sleutel server-side, uit de geauthenticeerde sessie of de
tenant-context:**

| Tenancy-model | Afleiding |
|---|---|
| Subdomein per tenant | Host → tenant-lookup, server-side |
| Pad-prefix (`/t/{slug}`) | Route-param → lookup **plus** check dat de gebruiker lid is van die tenant |
| Eén DB, tenant aan de gebruiker | Tenant-id uit sessie/JWT-claim |
| Single-tenant app | Vaste constante |

In Laravel wordt de proxy dan bijvoorbeeld:

```php
->withHeaders(['X-Account-Id' => $this->resolveTenantId()])  // niet $r->header(...)
```

Zelfde regel bij loskoppelen: honoreer een `connection_id` uit de request pas
nadat je hebt vastgesteld dat die Connection bij de tenant van de ingelogde
gebruiker hoort.

**🤖 Agent-prompt**

```text
Bouw in mijn {stack}-app een server-side proxy op `/api/emeq/{path}` die elke
request 1-op-1 doorzet naar `{EMEQ_BASE}/v1/{path}`. Injecteer server-side
`Authorization: Bearer {EMEQ_PAT}` (PAT uit env — NOOIT naar de browser sturen)
en `Accept: application/json`. Forward query-string, JSON-body én de headers
`Idempotency-Key` en `Prefer` ongewijzigd door. Zet `X-Account-Id` NIET door
vanaf de client: leid die server-side af uit {hoe mijn app de tenant bepaalt} —
anders kan tenant A in de administratie van tenant B boeken. Beveilig de route
met mijn bestaande tenant-auth. Geen cookies, geen credentials. Een directe
browser→Hub-call is verboden (lekt de PAT).
```

## Stap 1 — Account registreren (eenmalig per tenant)

```http
POST /v1/accounts
{ "external_id": "bob", "display_name": "Bob B.V." }
```

`201` → aangemaakt. `409` → bestaat al (idempotent te behandelen). `external_id`
is jouw sleutel; gebruik 'm overal hierna.

> Niet strikt nodig: `POST /v1/oauth/{provider}/init` (Stap 3) maakt het Account
> automatisch aan als het nog niet bestaat. Expliciet registreren geeft je wel
> een vroege foutmelding en een nette `display_name`.

**🤖 Agent-prompt**

```text
Roep bij de eerste koppel-actie van een tenant éénmalig `POST /v1/accounts` aan
via mijn emeq-proxy met `{ external_id, display_name }`, waarbij `external_id`
mijn eigen stabiele tenant-sleutel is (geen e-mail/naam die kan wijzigen).
Behandel `409` als "bestaat al" — geen fout. Onthoud lokaal dat de tenant
geregistreerd is zodat ik dit niet herhaal.
```

## Stap 2 — Integraties tonen (discovery)

```http
GET /v1/integrations?account_external_id=bob
```
```json
[
  { "key": "exact", "label": "Exact Online", "tagline": "Boekhouden — NL/BE",
    "category": "Boekhouden", "logo": "/img/partners/exact.svg", "brand": "#e1141d",
    "connectable": true, "status": "connected",
    "connection_id": "con_01JQZ8F4XK2N7RVB3TDW6MPYAC" },
  { "key": "mollie", "label": "Mollie", "connectable": true,
    "status": "disconnected", "connection_id": null },
  { "key": "snelstart", "label": "SnelStart", "connectable": false,
    "status": "disconnected", "connection_id": null }
]
```

- Render deze lijst **data-driven** — een nieuwe provider verschijnt automatisch,
  zonder code-wijziging.
- `connectable: false` → toon als niet-koppelbaar (bv. Snelstart = geen OAuth) of
  "binnenkort".
- `status` ∈ `connected` / `pending` / `disconnected`.
- `account_external_id` is optioneel: zonder param krijg je de catalogus met
  alles op `disconnected`.

**🤖 Agent-prompt**

```text
Haal `GET /v1/integrations?account_external_id={tenant}` op via de proxy en render
de providers volledig **data-driven** als kaarten (label, tagline, logo, brand,
category). Hardcode GEEN providerlijst en geen per-provider-logica — een nieuwe
provider die de Hub teruggeeft moet vanzelf verschijnen. Toon `connectable:false`
als niet-koppelbaar (geen connect-knop) en gebruik `status`
(connected/pending/disconnected) voor de knop-staat.
```

## Stap 3 — Koppelen

```http
POST /v1/oauth/{provider}/init
{ "account_external_id": "bob" }
```
→ `{ "connection_id": "con_01JQZ8F4XK2N7RVB3TDW6MPYAC", "redirect_url": "https://…partner-consent…" }`

Stuur de browser naar `redirect_url`. **`return_url` is optioneel** — laat je 'm
weg, dan stuurt de Hub de gebruiker na consent terug naar de **Origin** van de
init-call (jouw tenant-domein). Wil je een specifiek pad:

```json
{ "account_external_id": "bob", "return_url": "https://bob.emeq.nl/instellingen?emeq=return" }
```

De host van `return_url` moet op jouw consumer-basisdomein liggen (open-redirect-
guard). Let op: param is `return_url` (snake_case), niet `returnUrl`.

Foutcodes: `404 unknown_provider` / `provider_not_connectable` ·
`503 provider_disabled` · `403 insufficient_ability`.

**🤖 Agent-prompt**

```text
Implementeer een "Koppelen"-knop per provider die `POST /v1/oauth/{provider}/init`
aanroept via de proxy met body `{ account_external_id, return_url }` — let op:
snake_case. Zet `return_url` op een pad op mijn eigen domein (anders valt 't terug
op de Origin). Redirect de browser daarna naar de `redirect_url` uit de respons en
bewaar de teruggegeven `connection_id`. Handel `404`/`503`/`403` af met een nette
melding.
```

### Privacy-akkoord (verplicht)

`POST /v1/oauth/{provider}/init` is server-to-server: er is **geen door de Hub
gerenderde consent-pagina** in deze flow. Jij (de Consumer) bent daarom
verantwoordelijk voor het verkrijgen van het akkoord van de eindgebruiker op het
Hub-privacybeleid (<https://hub.emeq.nl/privacy>) **vóór** je de koppeling start —
bijvoorbeeld met een verplichte checkbox in je eigen koppel-UI. Dit akkoord is
contractueel geborgd via de verwerkersovereenkomst tussen Emeq en jou.

De browser-flow op de Hub zelf (`/koppelen`-intake) heeft een eigen verplichte
consent-checkbox; die geldt alleen voor dat pad.

## Stap 4 — Terugkomst + status

Na consent toont de Hub een bevestigingspagina en **redirect automatisch (±3s)**
terug naar je return/Origin. Bevestig de status:

```http
GET /v1/connections/con_01JQZ8F4XK2N7RVB3TDW6MPYAC
→ { "id": 12, "public_id": "con_01JQZ8F4XK2N7RVB3TDW6MPYAC",
    "provider": "exact", "status": "active",
    "revoked_at": null, "fingerprint": "…" }
```

Gebruik de `connection_id` die je uit `/v1/integrations` of de OAuth-init kreeg —
dat is de `public_id` (`con_…`). De numerieke `id` blijft werken, maar is de
interne sleutel; bewaar 'm niet.

of her-poll `GET /v1/integrations?account_external_id=bob`. Verbonden =
`status: "active"` & `revoked_at: null`.

**🤖 Agent-prompt**

```text
Bouw de terugkomst-handler: na de redirect terug vanaf de provider, poll
`GET /v1/connections/{id}` (of her-fetch `GET /v1/integrations`) tot
`status:"active"` & `revoked_at:null`, en werk de UI bij naar "gekoppeld". Toon
zolang het `pending` is een laad-/wachtstaat; stop met pollen na een redelijke
timeout met een "probeer opnieuw".
```

## Stap 5 — Loskoppelen

```http
DELETE /v1/connections/con_01JQZ8F4XK2N7RVB3TDW6MPYAC   → 204
```

De Hub doet de volledige provider-teardown (token-revoke + webhook-subscriptions).
Opnieuw koppelen = Stap 3 herhalen (de Hub regelt de schone reconnect).

**🤖 Agent-prompt**

```text
Implementeer "Loskoppelen": `DELETE /v1/connections/{id}` via de proxy, verwacht
`204`, en zet de UI terug op "niet gekoppeld". Doe zelf GEEN token-/webhook-
opruiming — de Hub regelt de volledige teardown. Bied daarna gewoon weer de
Koppelen-flow (Stap 3) aan.
```

## Boekhouden — documenten valideren & boeken

Zodra een tenant een **boekhoud-provider** gekoppeld heeft (Stap 1–4, bv. Exact),
kun je financiële documenten valideren en boeken. Deze endpoints lopen **niet** op
`integrations:manage` maar op de **canonieke boekhoud-abilities**:
`accounting:read` (lezen + valideren) + `accounting:write` (boeken) — vraag een PAT
met preset *Boekhouding (provider-onafhankelijk)*.

Die twee noemen bewust geen provider. Verhuist een eindgebruiker van Exact naar een
ander boekhoudpakket, dan blijft hetzelfde token geldig; bij een provider-ability
(`exact:write`) zou je een nieuw token nodig hebben. Bestaande `{provider}:read`/
`{provider}:write`-tokens blijven voorlopig geaccepteerd op deze endpoints, zodat je
kunt migreren zonder onderbreking — plan de omzetting wel in. De provider-abilities
blijven de juiste keuze voor de **ruwe pass-through** (`/v1/exact/*`), die per
definitie providerspecifiek is.

Beide identificeren de tenant via **header** `X-Account-Id: <external_id>` (let op:
een header, niet de query-param `account_external_id` van de connect-laag):

> **Meerdere boekhoudkoppelingen op één Account?** Wijs er één aan met
> `X-Connection-Id: <connection_id>` — de waarde die `GET /v1/integrations` per
> gekoppelde provider teruggeeft. Heeft het Account precies één boekhoudkoppeling —
> het normale geval — dan is die header niet nodig. Zijn het er meer en laat je 'm
> weg, dan antwoordt de Hub `409 multiple_accounting_connections` met de keuze in
> `connections` (`connection_id`, `provider`, `administration`); hij kiest bewust
> niet zelf. Een `connection_id` dat dit Account niet heeft geeft `404`.
>
> Je kiest dus een **koppeling**, geen pakket. De Unified API vraagt nergens om een
> providernaam: dat is precies de belofte dat je code hetzelfde blijft als de
> eindgebruiker morgen op een ander boekhoudpakket zit.

| Doel | Request | Ability |
|---|---|---|
| Capabilities opvragen | `GET /v1/accounting/capabilities` | `accounting:read` |
| Geboekte documenten lezen | `GET /v1/accounting/documents?type=…` | `accounting:read` |
| Bank-/kasafschriften lezen | `GET /v1/accounting/bank-statements` | `accounting:read` |
| Grootboek lezen | `GET /v1/accounting/ledger-accounts` | `accounting:read` |
| Btw-codes lezen | `GET /v1/accounting/tax-codes` | `accounting:read` |
| Klanten lezen | `GET /v1/accounting/customers` | `accounting:read` |
| Leveranciers lezen | `GET /v1/accounting/suppliers` | `accounting:read` |
| Valideren (dry-run) | `POST /v1/accounting/documents/validate` | `accounting:read` |
| Boeken | `POST /v1/accounting/documents` | `accounting:write` |

> Je backend-proxy moet de headers `X-Account-Id`, `Idempotency-Key` en `Prefer`
> mee-forwarden — zorg dat je proxy headers doorzet (zie het proxy-voorbeeld).

### Wat ondersteunt deze koppeling?

Niet elke boekhoudprovider kan hetzelfde. In plaats van dat per provider te weten,
vraag je het:

```http
GET /v1/accounting/capabilities
X-Account-Id: bob
```

```json
{
  "provider": "exact",
  "enabled": true,
  "capabilities": ["documents.write", "documents.attachments",
                   "references.sync", "validation.enrich"]
}
```

`capabilities` is een **platte lijst** — behandel een onbekende waarde als iets wat je
negeert, dan is uitbreiding voor jou niet-breaking. Ontbreekt
`documents.attachments`, stuur dan geen bijlagen mee. `enabled: false` betekent dat de
provider tijdelijk uitgezet is (onderhoud, incident); wat hij *kan* verandert daar niet
door, maar schrijfacties geven dan `503 provider_disabled`.

### Lezen uit de boekhouding

Vier endpoints, één antwoordvorm:

```http
GET /v1/accounting/ledger-accounts?limit=50
X-Account-Id: bob
```

```json
{
  "data": [
    { "id": "a1b2…", "code": "8000", "name": "Omzet", "attributes": {} }
  ],
  "next_cursor": "MDgwMA",
  "has_more": true
}
```

- **`id`** is **ondoorzichtig**. Gebruik 'm om terug te verwijzen, lees er geen
  betekenis in en parse 'm niet. Wat wél betekenis heeft staat apart: `code` op een
  grootboekrekening is het nummer dat je boekhouder kent en dat je in
  `line.category` kunt terugleggen.
- **Paginatie is cursor-based.** Geef `next_cursor` ongewijzigd terug als `?cursor=`
  tot `has_more` false is. Geen `?page=`: de onderliggende pakketten pagineren niet
  op offset. De cursor is ondoorzichtig — bewaar 'm niet langdurig.
- **`limit`** is 1–200, standaard 50.
- Bij `customers`/`suppliers` draagt elke rij `roles` (`debtor` en/of `creditor`); een
  relatie kan allebei zijn. `/customers` en `/suppliers` filteren op één rol.

**Bank- en kasafschriften** haal je op met `GET /v1/accounting/bank-statements?kind=bank|cash`.
Dit is de resource waarover de bank-webhooks notificeren: krijg je zo'n melding, dan
vind je hier de mutaties. Een afschrift draagt `opening_balance` en `closing_balance`
waarmee je kunt controleren of je alle regels hebt, en per regel de tegenpartij, het
bedrag, de datum en de grootboekrekening.

**Geboekte documenten teruglezen** gaat via `GET /v1/accounting/documents` — hetzelfde
pad als waar je op POST, want het is hetzelfde begrip. `?type=` is **verplicht**:
`sales_invoice`, `purchase_invoice`, `credit_note`, `income` of `expense`. Boekhoud-
pakketten bewaren verkoop en inkoop in gescheiden collecties met een eigen cursor, dus
"alles in één lijst" bestaat daar niet. Wil je beide, doe dan twee calls.

```json
{
  "data": [{
    "id": "entry-guid", "type": "sales_invoice", "number": "60001",
    "external_id": "INV-2026-001",
    "issue_date": "2026-06-16", "due_date": "2026-07-16",
    "party": { "id": "cust-guid", "name": "Acme BV" },
    "journal": "70", "currency": "EUR", "net_total": 250.0,
    "lines": [{ "description": "Consultancy", "amount": 200.0,
                "tax_code": "4", "ledger_account_id": "gl-guid",
                "cost_center": null, "cost_unit": null }]
  }],
  "next_cursor": null, "has_more": false
}
```

- **`external_id`** is jóuw sleutel, teruggelezen uit de herkomst die de Hub bij het
  boeken meeschreef. Documenten die buiten de Hub om in het pakket zijn ingevoerd
  hebben er geen: dan is het `null`. Daarmee kun je jouw administratie afstemmen tegen
  die van het pakket.
- **`net_total`** telt de regels op. Bewust niet het totaalveld van het pakket: dat
  betekent per pakket iets anders (met of zonder btw, in valuta of in
  administratie-valuta).
- **`party.name`** komt uit de Hub-spiegel. Is de relatie daar nog niet bekend, dan is
  hij `null` terwijl `party.id` wél gevuld is — draai `POST /v1/accounting/sync` of
  zoek 'm op via `/v1/accounting/customers`.

`ledger-accounts` en `tax-codes` komen uit de Hub-spiegel van jouw administratie —
snel en zonder je boekhoudpakket te belasten. Ververs die met
`POST /v1/accounting/sync` na wijzigingen in het pakket. `customers`/`suppliers`
worden live opgehaald.

Controleer met `GET /v1/accounting/capabilities` of `accounting.relations.read` en
`accounting.ledger_accounts.read` in de lijst staan; ontbreekt er één, dan geeft dat
endpoint `422 unsupported_capability`.

### Canonical document

Eén Hub-canonical formaat (snake_case), ongeacht het pakket — de Hub mapt het naar
de provider:

```json
{
  "type": "purchase_invoice",
  "external_id": "factuur-2026-0042",
  "issue_date": "2026-06-20",
  "due_date": "2026-07-20",
  "currency": "EUR",
  "party": { "role": "creditor", "name": "Leverancier BV", "vat_number": "NL000099998B57", "iban": "NL91ABNA0417164300", "external_id": "crediteur-99" },
  "lines": [
    { "description": "Dienst", "amount": 100.00, "tax_rate": 21, "category": "kantoorkosten", "cost_center": "ADMIN", "cost_unit": "PROJ-X" }
  ],
  "attachments": [
    { "filename": "factuur.pdf", "mime_type": "application/pdf", "content": "<base64>" }
  ]
}
```

- `type` ∈ `sales_invoice` · `purchase_invoice` · `credit_note` · `income` · `expense`.
- `credit_note` wordt als verkoopboeking (debiteur, verkoopdagboek) doorgestuurd — net als
  `sales_invoice`. De Hub muteert je bedragen **niet** en keert het teken **niet** om: lever
  zelf negatieve `amount`-waarden aan als je een creditboeking wilt. Wat je stuurt, boekt de Hub.
- `party.role`: `debtor` (verkoop) of `creditor` (inkoop). `vat_number`/`iban`/`external_id` optioneel.
- `party.external_id` = jouw stabiele klant-/leverancier-sleutel. Stuur 'm consistent
  mee: de Hub onthoudt 'm (relatie-mirror) zodat een volgende boeking direct matcht.
- `lines[].amount` = **netto** regelbedrag (leidend); `tax_rate` = percentage (0/9/21).
  `quantity`/`unit_price` optioneel/informatief; `category` = GL-hint.
- `lines[].tax_treatment` (optioneel, default `standard`) = BTW-behandeling. Zet
  `reverse_charge` voor verlegde BTW (onderaanneming / intra-EU B2B): hetzelfde
  `tax_rate` mapt dan naar de **verlegd**-VATCode i.p.v. de gewone. Vereist dat de
  koppeling een verlegd-tarief gemapt heeft (auto-afgeleid bij connect; anders `422`).
- `lines[].cost_center` / `lines[].cost_unit` (optioneel) = kostenplaats-/kostendrager-**Code**
  van de gekoppelde administratie (precies zoals die in Exact heet). Onbekende Code →
  `422` met een duidelijke melding; laat weg als je er geen voert. Deze Codes komen
  **niet** uit `/v1/accounting/reference-data` (die geeft alleen GL/VAT/dagboeken) —
  haal ze rechtstreeks uit je administratie of laat de gebruiker ze invullen.
- `external_id` = jouw stabiele document-sleutel (echo't terug — gebruik 'm in je sync-ledger).
- `due_date` (optioneel) = vervaldatum; weggelaten → de Hub zet standaard `issue_date + 1 maand`
  en stuurt die als Exact-`DueDate` (vervaldatum van de openstaande post).
- `currency` default `EUR`; `attachments` optioneel, inline base64 (PDF/PNG/JPEG, ≲ 1 MB/stuk).

### Valideren (Scan & herstel)

Stuur het (eventueel OCR-geëxtraheerde) concept naar `validate` vóór je boekt:
boekt niets, geeft een findings-rapport. De body mag onvolledig zijn (lenient);
extra OCR-samenvattingsvelden `subtotal`/`tax_total`/`total`/`discount` worden tegen
de regels gecheckt.

```http
POST /v1/accounting/documents/validate
X-Account-Id: bob
{ …canonical document… }
```
```json
{
  "valid": false,
  "summary": { "errors": 1, "warnings": 2, "infos": 1 },
  "findings": [
    { "code": "vat_treatment.domestic_rate_on_non_eu", "severity": "error",
      "path": "lines.0.tax_rate", "message": "…", "current": 21, "suggestion": 0 }
  ]
}
```

`valid` is `false` zodra er één `error` is (zou een foute boeking opleveren);
`warning`/`info` blokkeren niet. Toon de findings, laat de gebruiker bevestigen, boek
daarná. Elke finding draagt `current` (aangeleverd) + `suggestion` (voorgestelde
correctie of `null`) — pas een suggestie alleen toe na bevestiging.

Een ongeldig NL-btw-nummer (fout formaat of fout controlecijfer/11-proef,
`vat_number.malformed` / `vat_number.checksum`) is een **`error`** — Exact weigert
zo'n boeking hard, dus `validate` houdt 'm tegen vóór je POST. Buitenlandse
EU-formaten blijven `warning`.

| Code | Severity | Betekenis |
|---|---|---|
| `arithmetic.amount_not_numeric` | warning | Regelbedrag niet numeriek |
| `arithmetic.line_amount_mismatch` | warning | `amount` ≠ `quantity × unit_price` |
| `arithmetic.subtotal_mismatch` | warning | `subtotal` ≠ som van de regels |
| `arithmetic.tax_total_mismatch` | warning | `tax_total` ≠ berekende BTW |
| `arithmetic.total_mismatch` | warning | `total` ≠ netto + BTW − korting |
| `iban.checksum_invalid` | error | IBAN faalt mod-97/lengte |
| `iban.normalize` | info | IBAN geldig maar niet genormaliseerd |
| `vat_number.malformed` | warning | BTW-nummer matcht landpatroon niet |
| `vat_number.checksum` | warning | NL BTW-nummer faalt de 11-proef (controlecijfer) — het pakket weigert dit |
| `vat_number.normalize` | info | BTW-nummer geldig maar niet genormaliseerd |
| `vat_treatment.reverse_charge_expected` | warning | Intra-EU B2B met BTW-nr → verlegd verwacht (zet `tax_treatment: reverse_charge`) |
| `vat_treatment.domestic_rate_on_non_eu` | error | Niet-EU leverancier met binnenlands tarief |
| `geography.country_mismatch` | warning | Land uit BTW-nr ≠ land uit IBAN |
| `currency.foreign` | info | Andere valuta dan EUR |
| `exact.vat_code.unmapped` | warning | Tarief nog niet gekoppeld aan een Exact-VATCode (een gekoppeld tarief levert géén finding) |
| `exact.relation.matched` | info | Relatie = bestaande Exact-relatie (`suggestion` = GUID) |
| `exact.relation.new` | info | Relatie nog niet in Exact (wordt automatisch aangemaakt bij boeken als auto-create aan staat, anders `422`) |

> `exact.*` verschijnen alleen bij een Exact-connection; de rest is provider-agnostisch.

### Boeken

```http
POST /v1/accounting/documents
X-Account-Id: bob
Idempotency-Key: factuur-2026-0042
{ …canonical document… }
```

- **`Idempotency-Key` is verplicht** — bij retry herhaalt de Hub de eerste respons
  i.p.v. dubbel te boeken. Gebruik een stabiele sleutel per document (bv. je `external_id`),
  1–255 printbare ASCII-tekens. Een herhaalde respons draagt de header
  `Idempotent-Replayed: true`, zodat je replay van uitvoering kunt onderscheiden.
  Drie situaties om af te vangen:
  - `409 idempotency_request_in_progress` — er loopt nú een request met deze sleutel.
    Dit is precies wat je wilt: zonder dit boekten twee gelijktijdige retries allebei.
    Wacht `Retry-After` seconden en probeer opnieuw. **Geen fout.**
  - `422 idempotency_key_reuse` — dezelfde sleutel, andere inhoud. Je client hergebruikt
    een sleutel; gebruik er een nieuwe. **Niet retryen.**
  - Sleutels vervallen na 24 uur. Een retry daarna wordt opnieuw uitgevoerd — maar de
    dedupe hieronder houdt dubbel boeken alsnog tegen.
- Synchroon (default): `201` `{ "provider": "exact", "status": "posted", "external_id": "…", "external_ref": "…", "external_number": 60001 }`.
  `external_ref` = de interne document-ID (GUID) bij het pakket; bewaar 'm. `external_number`
  = het mensleesbare boekstuknummer (bij Exact `EntryNumber`, voor verkoopfacturen het
  factuurnummer) — toon dát aan je gebruiker i.p.v. de GUID. Optioneel: alleen aanwezig
  als het pakket er één teruggeeft.
- Asynchroon: stuur `Prefer: respond-async` → direct `202` `{ "status": "pending", "external_id": "…" }`.
  Het eindresultaat komt per webhook (`accounting.document.synced`, HMAC-gesigneerd met je
  `webhook_callback_secret`) op je `webhook_callback_url`, met dezelfde body als de
  synchrone respons (`status` + `external_id` + `external_ref` + `external_number`). Async
  zonder geregistreerde callback → `400 webhook_required`.

`status` ∈ `posted` / `pending` / `rejected` / `failed`.

**Dubbele boeking, tweede vangnet.** Naast de `Idempotency-Key` houdt de Hub per
koppeling bij welk `external_id` al geboekt is en met welke inhoud. Dat blijft staan
ook als je idempotency-sleutel weg is:

- Zelfde `external_id`, **zelfde inhoud** → `200` met
  `{ "status": "posted", "deduplicated": true, "external_ref": "…", "external_number": "…" }`.
  Er is niets opnieuw geboekt; `external_ref` is die van de oorspronkelijke boeking.
  Behandel dit als succes.
- Zelfde `external_id`, **andere inhoud** → `409`
  `{ "status": "rejected", "error": "document_already_posted", "external_ref": "…" }`.
  Er staat al een boeking onder dat nummer met een andere inhoud. Boekhoudkundig is
  een correctie een creditnota met een eigen `external_id` — hergebruik het oude
  nummer niet. **Niet retryen.**

> De identiteit van een document is `(koppeling, type, external_id)` — het `type`
> telt mee. Verkoop- en inkoopnummering lopen bij de meeste consumers los van
> elkaar, dus `external_id: "100"` mag zowel een `sales_invoice` als een
> `purchase_invoice` zijn; die botsen niet. Twee documenten van hetzelfde type met
> hetzelfde `external_id` botsen wél.

De vergelijking kijkt naar de betekenis van het document, niet naar de exacte bytes:
sleutelvolgorde en `200` versus `200.00` maken geen verschil. Een gewijzigd bedrag,
tarief, regel, datum of factuurnummer wél.

**Onbekende relatie?** Standaard geeft een party die niet in Exact bestaat
`422 mapping_failed`. Staat **auto-create** aan op de Connection (Hub-admin →
Boekhoud-mapping → "Onbekende relaties automatisch aanmaken"), dan maakt de Hub de
relatie zelf aan (match op `vat_number`, anders `name`) en boekt door. Stuur dus
altijd een nette `party.name` (+ `vat_number` indien bekend) mee.

Foutcodes: `403 insufficient_ability` (PAT mist `accounting:write`) ·
`400 idempotency_key_required` / `400 idempotency_key_invalid` ·
`409 idempotency_request_in_progress` (wacht `Retry-After`, dan retryen) ·
`422 idempotency_key_reuse` (zelfde sleutel, ander document) ·
`409 document_already_posted` (zie hierboven — corrigeer met een nieuw `external_id`) ·
`422 mapping_failed` (onvolledige boekhoud-mapping óf onbekende relatie zonder
auto-create — los op in de Hub-admin) · `422 upstream_rejected` (het pakket wees de
boeking functioneel af, bv. een ongeldig btw-nummer — **niet retryen**, corrigeer het
document: `message` is een leesbare uitleg, `provider_message` de rauwe pakket-tekst) ·
`503 provider_disabled` · `502/503/504` upstream (pakket onbereikbaar/onderhoud/timeout —
echt transient, mét `Retry-After` waar relevant; **wél** retrybaar).
Elke fout draagt `{ "status": "failed", "external_id": "…", "error": "…", "message": "…" }`.

### Foutenvelope (alle `/v1/*`-endpoints)

Elke fout, van welk endpoint dan ook, draagt dezelfde drie velden naast wat het
endpoint zelf teruggeeft:

```json
{
  "error": "mapping_failed",
  "category": "REFERENCE_MAPPING_MISSING",
  "message": "…",
  "request_id": "01HXYZ…"
}
```

- **`error`** — de specifieke code. Ongewijzigd; blijf hierop branchen waar je dat al doet.
- **`category`** — de provider-onafhankelijke klasse fout. Handig als je niet elke code
  apart wilt afvangen: `VALIDATION_ERROR`, `AUTHENTICATION_ERROR`,
  `AUTHORIZATION_ERROR`, `RATE_LIMITED`, `RESOURCE_NOT_FOUND`, `CONFLICT`,
  `PROVIDER_UNAVAILABLE`, `UNSUPPORTED_CAPABILITY`, `REFERENCE_MAPPING_MISSING`,
  `PROVIDER_ERROR`, `INTERNAL_ERROR`.
- **`request_id`** — stuur deze mee bij een supportvraag; daarmee is de hele keten
  (jouw request → onze verwerking → de partner-call → de terugmelding) in één keer
  terug te vinden. Je kunt hem ook zelf bepalen door `X-Request-Id` mee te sturen.

Vuistregel voor retries: alleen `RATE_LIMITED`, `PROVIDER_UNAVAILABLE` en
`INTERNAL_ERROR` zijn het opnieuw proberen waard (met dezelfde `Idempotency-Key`). De
rest verandert niet door het nog eens te sturen.

`PROVIDER_ERROR` betekent dat de boekhoudpartner het afwees, `INTERNAL_ERROR` dat het
aan onze kant misging — dat onderscheid bepaalt bij wie je moet zijn.

**🤖 Agent-prompt**

```text
Implementeer de boekhoud-sync naar de emeq Hub (provider-agnostisch, ik lever het
Hub-canonical formaat — buig niets naar Exact). Voor elk boekbaar document:
1. Bouw het canonical document (snake_case): type, external_id (mijn stabiele
   document-sleutel), issue_date, party{role,name,vat_number?,external_id}, lines[]
   met netto `amount` + `tax_rate`, optioneel attachments (base64).
2. Stuur optioneel eerst `POST /v1/accounting/documents/validate` (header
   `X-Account-Id: {tenant}`), toon de findings en laat de gebruiker bevestigen.
3. Boek met `POST /v1/accounting/documents`, headers `X-Account-Id: {tenant}` +
   `Idempotency-Key: {external_id}`. Gebruik `Prefer: respond-async` alleen als ik
   een webhook-callback geregistreerd heb.
4. Verwerk `201 posted`: bewaar `external_ref` (interne GUID) + `external_number`
   (leesbaar boekstuknummer, toon dít aan de gebruiker) in mijn sync-ledger naast
   mijn external_id. Behandel `200` met `deduplicated: true` net zo — dat is
   dezelfde boeking, niet opnieuw uitgevoerd. Behandel `422 mapping_failed` als
   "actie vereist in de Hub-admin", niet als retrybare fout. `409
   document_already_posted` betekent dat ik hetzelfde external_id met andere inhoud
   stuurde: nooit retryen, maar een creditnota + nieuw external_id maken. Retry
   nooit een 5xx zonder dezelfde Idempotency-Key.
Stuur `party.external_id` consistent mee zodat relaties gecachet worden.
```

### Boekhoud-mapping (zelf-service, optioneel)

De Hub synct + auto-derivet de mapping al bij connect — **standaard hoef je hier
niets**. Deze endpoints zijn er voor consumers die de mapping willen tonen/verfijnen
of relatie-auto-create zelf willen sturen. Alle op header `X-Account-Id` +
`exact:read` (lezen) / `exact:write` (schrijven).

| Doel | Request | Ability |
|---|---|---|
| Mirror (her)synchroniseren + auto-derive | `POST /v1/accounting/sync` | `exact:write` |
| Beschikbare codes (GL/BTW/dagboek) | `GET /v1/accounting/reference-data` | `exact:read` |
| Huidige mapping lezen | `GET /v1/accounting/mapping` | `exact:read` |
| Mapping + auto-create zetten (merge) | `PUT /v1/accounting/mapping` | `exact:write` |

PUT-body — alle velden optioneel, **merge** (bestaande waarden blijven):

```json
{
  "vat_codes":   { "21": "3", "9": "1", "0": "0" },
  "gl_accounts": { "_default": "4000", "omzet": "8000" },
  "journals":    { "sales": "70", "purchase": "20" },
  "auto_create_relations": true
}
```

- `vat_codes`/`gl_accounts`/`journals` = stabiele **Codes** uit `reference-data`
  (geen GUIDs — de Hub resolvet die lokaal).
- `auto_create_relations`: `true` → een onbekende party wordt automatisch als relatie
  aangemaakt bij het boeken (anders `422`). `false`/weglaten = uit. Deze toggle wordt
  **gedeeld met de Hub-admin** — wie het laatst schrijft, wint.
- `GET /v1/accounting/mapping` geeft de hele mapping terug (incl. `auto_create_relations`).

> Merge-only: een bestaande key verwijderen kan niet via PUT — stuur een nieuwe waarde.

**🤖 Agent-prompt**

```text
Bouw (optioneel) een instellingen-scherm voor de boekhoud-koppeling tegen de emeq
Hub. Lees de keuzes met `GET /v1/accounting/reference-data` (header `X-Account-Id`)
en de huidige mapping met `GET /v1/accounting/mapping`. Laat de tenant per BTW-tarief
een VATCode, per categorie een GL-Code en per dagboek-type een Journal kiezen (alles
Codes, geen GUIDs) plus een toggle "onbekende relaties automatisch aanmaken". Sla op
met `PUT /v1/accounting/mapping` (merge), body `{ vat_codes, gl_accounts, journals,
auto_create_relations }` — stuur alleen de gewijzigde velden. Optioneel een knop
"hersynchroniseren" → `POST /v1/accounting/sync`. Default hoeft de tenant niets in te
stellen; de Hub auto-derivet bij connect.
```

## Webhooks ontvangen

Gebeurt er iets bij de gekoppelde partner, dan POST de Hub naar je
`webhook_callback_url`, HMAC-gesigneerd met je `webhook_callback_secret`.

**Elke** webhook heeft dezelfde vorm, ongeacht welk pakket je eindgebruiker
koppelde:

```json
{
  "event": "accounting.bank_statement.changed",
  "provider": "exact",
  "account_id": "school1",
  "occurred_at": "2026-08-11T14:22:03+00:00",
  "data": { "…": "de ruwe payload van de partner" }
}
```

- **`event`** — canonieke naam uit het Hub-vocabulaire. Route hierop, niet op
  `provider` en niet op iets uit `data`.
- **`account_id`** — hetzelfde id dat jij bij het koppelen aanleverde (je
  `X-Account-Id`), niet een Hub-intern nummer.
- **`occurred_at`** — wanneer de Hub het event uitstuurde. De meeste partners
  leveren geen eigen tijdstempel; doen alsof van wel zou liegen over de bron.
- **`data`** — bij een event dat van de partner komt: diens payload, ongewijzigd.
  Handig om te debuggen; bouw er geen routering op, want die vorm verschilt per
  provider. Bij de twee events die de Hub zélf publiceert —
  `accounting.document.synced` en `connection.revoked` — is `data` wél van de Hub
  en dus provider-onafhankelijk; die velden staan hieronder per event.

Huidige `event`-waarden:

| Event | Betekenis |
|---|---|
| `accounting.bank_statement.changed` | bankmutatie gewijzigd |
| `accounting.cash_statement.changed` | kasmutatie gewijzigd |
| `accounting.relation.changed` | debiteur/crediteur gewijzigd |
| `accounting.sales_invoice.changed` | verkoopfactuur gewijzigd |
| `accounting.document.synced` | de Hub heeft jouw document weggeschreven |
| `billing.payment.changed` | betaling gewijzigd |
| `billing.subscription.changed` | abonnement gewijzigd |
| `connection.revoked` | de koppeling is ingetrokken — `data` draagt `connection_id`, `source` en `revoked_at`. Stop met pass-through-calls voor dit account en toon de koppelknop weer |
| `unmapped` | de partner stuurde iets waar de Hub nog geen naam voor heeft — negeer, of kijk in `data` |

Behandel een onbekende `event`-waarde als `unmapped`: de lijst groeit additief en
een nieuwe naam mag jouw handler niet laten crashen.

> **Wijziging.** Tot nu toe stuurde de Hub de ruwe partner-payload als body. Die
> staat nu onder `data`. Migreren is dus `body` → `body.data`, en daarna kun je op
> `body.event` gaan routeren in plaats van op provider-specifieke velden als
> Exact's `Topic`.

**🤖 Agent-prompt**

```text
Bouw in mijn {stack}-app een publiek webhook-endpoint op {pad} dat de emeq Hub
aanroept. Verwerk als volgt:

1. Verifieer eerst de HMAC-SHA256 in de `Signature`-header over de ruwe
   request-body, met mijn `webhook_callback_secret` uit env. Vergelijk in
   constante tijd. Mismatch → 401, niets verwerken, niets loggen van de body.
2. Zet het endpoint buiten CSRF-bescherming en buiten mijn tenant-auth — de Hub
   authentiseert met de signature, niet met een sessie.
3. Body-vorm is altijd `{ event, provider, account_id, occurred_at, data }`.
   Route op `event` — nooit op `provider` en nooit op iets uit `data`, want die
   vorm verschilt per provider.
4. `account_id` is mijn eigen tenant-sleutel (dezelfde die ik bij het koppelen
   aanleverde). Zoek daarmee de tenant op; onbekende waarde → 200 + log, geen
   crash.
5. Onbekende `event`-waarde → behandel als `unmapped`: negeren en loggen, geen
   exception. De lijst groeit additief; een nieuwe naam mag mijn handler niet
   omleggen.
6. Antwoord snel met 2xx en doe het echte werk in een background-job. De Hub
   retryt bij een niet-2xx.
7. Dedupliceer op de `X-Emeq-Event-Id`-header — bij een retry komt hetzelfde
   event opnieuw binnen. Log `X-Emeq-Request-Id` mee, dat correleert met de
   Hub-audit.

Geef mij daarna de URL die ik als `webhook_callback_url` moet doorgeven.
```

## Valkuilen

- Param is `return_url` (snake_case), niet `returnUrl` — anders genegeerd (valt
  terug op Origin).
- Geen cookies/`credentials` — auth is puur de PAT (via je proxy).
- Account moet bestaan vóór je boekt; voor koppelen maakt `init` het Account
  desnoods zelf aan (Stap 1).
- Boeken gebruikt de header `X-Account-Id`, niet de query-param
  `account_external_id` van de connect-laag.
- Snelstart is `connectable: false` (geen OAuth) — toon, maar bied geen
  OAuth-connect aan.
- Volledige, altijd-actuele API-referentie: **`/docs/api`**.
