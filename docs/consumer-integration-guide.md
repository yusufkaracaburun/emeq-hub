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
  - [Categorie of kostendrager?](#categorie-of-kostendrager)
  - [Betalingen horen niet in de boeking](#betalingen-horen-niet-in-de-boeking)
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
  "capabilities": ["accounting.documents.write", "accounting.documents.attachments",
                   "accounting.ledger_accounts.read", "…"]
}
```

Dit endpoint is de bron: elke naam begint met `accounting.`, en de set groeit mee met
wat de adapters kunnen. Wat er vandaag in zit, vraag je op — hier staat bewust geen
kopie van die lijst, want die veroudert zonder dat iemand het merkt.

`capabilities` is een **platte lijst** — behandel een onbekende waarde als iets wat je
negeert, dan is uitbreiding voor jou niet-breaking. Match op de volledige string,
voorvoegsel incluis. Ontbreekt `accounting.documents.attachments`, stuur dan geen
bijlagen mee. `enabled: false` betekent dat de provider tijdelijk uitgezet is
(onderhoud, incident); wat hij *kan* verandert daar niet door, maar schrijfacties geven
dan `503 provider_disabled`.

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
- `party.role`: `debtor` (verkoop) of `creditor` (inkoop).
- `party.kind` (**verplicht**): `company` of `person`. Dit zegt wélke sleutels bestaan,
  niet wat de Hub mag doen. Een `company` wordt herkend op KvK of btw-nummer; een
  `person` heeft geen van beide en leunt volledig op `party.external_id`.
- `party.external_id` (**verplicht**) = jouw stabiele klant-/leverancier-sleutel. De Hub
  onthoudt 'm (relatie-mirror) zodat een volgende boeking direct matcht, zonder de
  boekhouding te bevragen.
- **Bij `kind: company` is `chamber_of_commerce` of `vat_number` verplicht.** Zonder een
  van beide kan de Hub de relatie alleen op naam herkennen, en een naam-miss zet een
  duplicaat in de administratie van je klant. Ontbreken ze allebei, dan volgt een `422`
  en werk je het bij in je eigen app.
- `party.relation_id` (optioneel) = de relatie in de boekhouding, rechtstreeks aangewezen.
  Hiermee slaat de Hub de hele zoekladder over. Gebruik dit om een `409 relation_ambiguous`
  op te lossen: je gebruiker kiest de juiste relatie, jij pint 'm één keer, de Hub onthoudt 'm.
- **Relatiekaart** (allemaal optioneel): `address_line_1`, `address_line_2`, `postcode`,
  `city`, `state`, `country` (ISO-landcode, 2 letters), `email`, `phone`, `website`,
  plus `iban`. Deze velden tellen alleen wanneer de Hub de relatie aanmaakt — bestaat 'ie
  al, dan is de administratie leidend en raakt de Hub alleen een leeg KvK- of btw-veld
  aan (zodat de volgende boeking sterker matcht). Stuur wat je hebt: een relatie met
  alleen een naam is voor de boekhouder onbruikbaar. Weet je een veld niet zeker, laat het
  weg in plaats van te gokken — een fout adres is erger dan geen adres.
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

### Categorie of kostendrager?

`category` is een **grootboek**-hint: het antwoord op "welke omzet- of kostenrekening is dit".
`cost_center` en `cost_unit` zijn **dimensies** op die rekening: voor wie, voor welk project,
welke werksoort. Verschillende vragen, verschillende velden — en in de praktijk de plek waar
de mapping het vaakst uit de rails loopt.

De vuistregel: beschrijft je categorie een *soort geld*, dan is het een `category`. Beschrijft
'ie *waar het geld aan hing*, dan is het een `cost_unit` of `cost_center`.

- "Omzet hoog tarief", "Omzet verlegd", "Personeelskosten", "Huisvestingskosten" → `category`.
- "Project Noord", "Werksoort Glasvezel", "Klant X", "Vestiging Amsterdam", "Bus 12" →
  `cost_unit` / `cost_center`, met één `category` eronder.

Apps waar gebruikers hun eigen categorieënlijst inrichten, zien die lijst vaak uitgroeien tot
een werksoort- of projectschema van tientallen items. Map die niet één-op-één op
grootboekrekeningen: dan krijgt de administratie er tientallen omzet- en kostenrekeningen bij
die de boekhouder niet wil en die elk rapport onleesbaar maken. Eén rekening, de rest als
dimensie.

Twee categorieën die nooit een `category` mogen zijn: balansposten zoals **debiteuren,
crediteuren, btw of bank**. Dat zijn tegenrekeningen die de boekhouding zelf al boekt vanuit
de relatie en het BTW-tarief; stuur je ze als GL-hint mee, dan staat de boeking dubbel op de
balans en klopt de openstaande post niet meer.

Let op het verschil in faalgedrag. Een `cost_center` of `cost_unit` die niet in de
administratie bestaat, wordt geweigerd met `422`. Een `category` die niet in de mapping staat
**faalt niet**: die valt stil terug op de standaard-grootboekrekening van het documenttype.
Een typefout in een categorienaam levert dus een geslaagde boeking op de verkeerde rekening
op. Laat gebruikers hun categorieën daarom expliciet mappen in plaats van te vertrouwen op de
afgeleide default.

### Betalingen horen niet in de boeking

Een document gaat **één keer, voor het volledige bedrag** de Hub in. Betalingen — volledig,
gedeeltelijk, of gesplitst over meerdere rekeningen — stuur je niet mee, en boek je ook niet
als apart `income`/`expense`-document. Die twee types zijn voor vrijstaande kas- en
bankposten zónder brondocument, niet voor betalingen op een factuur.

Waarom niet:

- **BTW-periode.** Bij het factuurstelsel is de BTW verschuldigd op factuurdatum. Boek je per
  ontvangst, dan landt de BTW in de periode van de betaling — over een kwartaalgrens klopt de
  aangifte niet meer.
- **Openstaande post.** `income`/`expense` boekt direct af. Het debiteuren-/crediteurensaldo
  staat dan op nul terwijl er geld openstaat: ouderdomsanalyse en aanmaningen vallen om.
- **Dubbeltelling.** Boek je de factuur én de betalingen, dan staat de omzet er twee keer in.

De betaling landt in de bankadministratie van het boekhoudpakket: de bankmutaties komen daar
binnen via de bankkoppeling van de klant en worden afgeletterd tegen de openstaande post van
deze factuur. Een deelbetaling laat het restant gewoon openstaan; twee betalingen op één
factuur letteren allebei af tegen dezelfde post. De Hub komt daar niet aan te pas.

**Betaalsplitsingen zoals een G-rekening zijn een betaalinstructie, geen boekhoudfeit.** Een
factuur met een G-deel en een bankdeel blijft één document met één totaalbedrag en één
BTW-bedrag; alleen het geld loopt over twee rekeningen. Stuur het G-bedrag dus niet als aparte
regel of apart document mee. Op de factuur-PDF hoort het wel — die gaat via `attachments` mee
naar de boekhouding.

**Boek niet pas na betaling.** Boekbaarheid hangt aan "definitief en niet geannuleerd", niet
aan betaalstatus. Wachten tot een factuur betaald is, zet de boeking in de verkeerde periode.

Praktijkpunt bij deelbetalingen: automatische afletterherkenning matcht op bedrag én
factuurnummer. Bij een deelbetaling valt het bedrag als aanknopingspunt weg — zorg dus dat het
factuurnummer in de omschrijving van elke betaling staat, bij een gesplitste betaling op beide
mutaties.

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
  "summary": { "errors": 1, "warnings": 2, "infos": 1, "blocking": 2 },
  "findings": [
    { "code": "vat_treatment.domestic_rate_on_non_eu", "severity": "error", "blocking": true,
      "path": "lines.0.tax_rate", "message": "…", "current": 21, "suggestion": 0 }
  ]
}
```

`valid` is `false` zodra één finding de boeking tegenhoudt. Het veld beantwoordt dus
precies de vraag die je stelt vóór je boekt: gaat dit lukken? Toon de findings, laat de
gebruiker bevestigen, boek daarná. Elke finding draagt `current` (aangeleverd) +
`suggestion` (voorgestelde correctie of `null`) — pas een suggestie alleen toe na
bevestiging.

**`severity` en `blocking` beantwoorden verschillende vragen en kantelen niet in elkaar.**
`severity` zegt hoe ernstig een bevinding is, `blocking` of ze de boeking tegenhoudt. Een
ontbrekende BTW-mapping (`exact.vat_code.unmapped`) is een `warning` — zo erg is het niet
— maar hij is wél blocking, want Exact weigert de boeking erop. Andersom is
`arithmetic.total_mismatch` een warning die niets tegenhoudt, net als de drie velden die
je tijdens het boeken nog aanvult (`external_id`, `issue_date`, `party.role`). Wil je per
finding weten of de gebruiker 'm eerst moet oplossen, kijk dan naar `blocking`;
`summary.blocking` telt er hoeveel dat zijn, en `valid` is de samenvatting daarvan.

Een ongeldig NL-btw-nummer (fout formaat of fout controlecijfer/11-proef,
`vat_number.malformed` / `vat_number.checksum`) is een **`error`** — Exact weigert
zo'n boeking hard, dus `validate` houdt 'm tegen vóór je POST. Buitenlandse
EU-formaten blijven `warning` en zijn niet blocking (het boekpad valideert niet-NL
alleen generiek).

Let op bij de `exact.*`-findings: een ontbrekende mapping (`vat_code.unmapped`,
`cost_center.unmapped`, `cost_unit.unmapped`) draagt severity `warning` maar staat op
`blocking: true`, en zet `valid` dus op `false`. Behandel die in je UI als "eerst
oplossen", niet als vrijblijvend advies. `relation.new` is het tegenovergestelde: die is
`info` en niet blocking, want de ladder maakt de relatie zelf aan en meldt dat terug in
`warnings[]`.

De `message` is bedoeld om ongewijzigd aan de eindgebruiker te tonen — Nederlands,
zonder Exact-jargon, met de consequentie en de handeling erin. Stuur je logica op
`code` + `blocking`; de tekst kan tussen releases wijzigen zonder breaking change.

| Code | Severity | Blocking | Betekenis |
|---|---|---|---|
| `arithmetic.amount_not_numeric` | warning | ja | Regelbedrag niet numeriek — `lines.*.amount` moet numeriek zijn om te boeken |
| `arithmetic.line_amount_mismatch` | warning | nee | `amount` ≠ `quantity × unit_price` — advies |
| `arithmetic.subtotal_mismatch` | warning | nee | `subtotal` ≠ som van de regels — bestaat niet op het boekcontract |
| `arithmetic.tax_total_mismatch` | warning | nee | `tax_total` ≠ berekende BTW — bestaat niet op het boekcontract |
| `arithmetic.total_mismatch` | warning | nee | `total` ≠ netto + BTW − korting — bestaat niet op het boekcontract |
| `iban.checksum_invalid` | error | ja | IBAN faalt mod-97/lengte |
| `iban.normalize` | info | nee | IBAN geldig maar niet genormaliseerd |
| `vat_number.malformed` | error (NL) / warning (overig) | ja (NL) / nee (overig) | BTW-nummer matcht landpatroon niet |
| `vat_number.checksum` | error | ja | NL BTW-nummer faalt de 11-proef (controlecijfer) — het pakket weigert dit |
| `vat_number.normalize` | info | nee | BTW-nummer geldig maar niet genormaliseerd |
| `vat_treatment.reverse_charge_expected` | warning | nee | Intra-EU B2B met BTW-nr → verlegd verwacht (zet `tax_treatment: reverse_charge`) — advies |
| `vat_treatment.domestic_rate_on_non_eu` | error | ja | Niet-EU leverancier met binnenlands tarief |
| `geography.country_mismatch` | warning | nee | Land uit BTW-nr ≠ land uit IBAN — advies |
| `currency.foreign` | info | nee | Andere valuta dan EUR |
| `document.*` (type/party/lines) | error | ja | Zonder deze velden valt er niets te boeken |
| `document.external_id.missing` / `document.issue_date.missing` / `document.party.role.missing` | warning | nee | Vul je alsnog in tijdens het boeken |
| `document.party.kind.missing` / `document.party.external_id.missing` | warning | nee | Vul je alsnog in tijdens het boeken; het boeken weigert er wél op |
| `document.party.identifier.missing` | warning | nee | `kind: company` zonder KvK én zonder btw-nummer. Het boeken weigert hierop met een `422` |
| `exact.vat_code.unmapped` | warning | ja | Tarief nog niet gekoppeld aan een Exact-VATCode (een gekoppeld tarief levert géén finding) |
| `exact.relation.matched` | info | nee | Relatie = bestaande Exact-relatie (`suggestion` = GUID). Herkend via `party.relation_id`, de mirror op `external_id`, KvK, btw-nummer of genormaliseerde naam |
| `exact.relation.new` | info | nee | Relatie nog niet in Exact — de boeking maakt 'm aan en meldt dat terug via `warnings[]` |
| `exact.relation.ambiguous` | warning | ja | Meerdere relaties met hetzelfde KvK- of btw-nummer. Het boeken geeft een `409`; laat je gebruiker kiezen en stuur `party.relation_id` |
| `exact.cost_center.matched` / `exact.cost_unit.matched` | info | nee | Opgegeven kostenplaats/-drager bestaat in de administratie |
| `exact.cost_center.unmapped` / `exact.cost_unit.unmapped` | warning | ja | Kostenplaats/-drager onbekend — de boeking weigert hierop. Corrigeer de Code of draai `POST /v1/accounting/sync` |

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

**Onbekende relatie?** Daar hoef je niets voor te zetten. De Hub loopt een vaste
ladder af en maakt de relatie pas aan als elke stap misgaat:

1. `party.relation_id`, als je die meestuurt — dan slaat de Hub de rest over
2. de mirror op `party.external_id`
3. KvK-nummer (alleen bij `kind: company`)
4. btw-nummer (alleen bij `kind: company`)
5. genormaliseerde naam (alleen bij `kind: company`) — bij een treffer vult de Hub een
   leeg KvK-/btw-veld in de administratie aan, zodat stap 3 of 4 het de volgende keer doet
6. aanmaken

Een `person` slaat stap 3 tot 5 over: particulieren hebben geen sterke sleutel, en twee
gelijknamige personen zouden een factuur aan de verkeerde klant koppelen.

Wat de Hub in de administratie heeft gedaan lees je terug in `warnings[]` op het
antwoord: `relation.created`, `relation.matched_by_name`, `relation.name_differs` en
`relation.relinked`. Toon die aan je gebruiker — het is de enige plek waar zichtbaar
wordt dat er iets in zijn boekhouding is bijgekomen of veranderd.

`relation.relinked` betekent dat de eerder gekoppelde relatie niet meer in de
administratie staat (verwijderd of samengevoegd) en de Hub de ladder opnieuw heeft
afgelopen. `context.previous_relation_id` draagt de relatie die weg is; draagt jouw app
zelf een relatie-id mee, ververs 'm dan.

Vinden stap 3 of 4 méér dan één relatie, dan volgt `409 relation_ambiguous` met de
kandidaten in de body. De Hub kiest dan niet: twee relaties met hetzelfde KvK-nummer
betekent dat de administratie al vervuild is, en er een derde bij zetten maakt het erger.
Laat je gebruiker kiezen en boek opnieuw met `party.relation_id`.

Foutcodes: `403 insufficient_ability` (PAT mist `accounting:write`) ·
`400 idempotency_key_required` / `400 idempotency_key_invalid` ·
`409 idempotency_request_in_progress` (wacht `Retry-After`, dan retryen) ·
`422 idempotency_key_reuse` (zelfde sleutel, ander document) ·
`409 document_already_posted` (zie hierboven — corrigeer met een nieuw `external_id`) ·
`422 mapping_failed` (onvolledige boekhoud-mapping) ·
`409 relation_ambiguous` (meerdere relaties met hetzelfde KvK- of btw-nummer; `candidates`
staat in de body — laat je gebruiker kiezen en stuur `party.relation_id`) ·
`422 upstream_rejected` (het pakket wees de boeking functioneel af, bv. een ongeldig
btw-nummer — **niet retryen**, corrigeer het document: `message` is een leesbare
uitleg, `provider_message` de rauwe pakket-tekst) ·
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
   document-sleutel), issue_date, party{role,kind,name,external_id,
   chamber_of_commerce?,vat_number?,relation_id?}, lines[] met netto `amount` +
   `tax_rate`, optioneel attachments (base64). `kind` is `company` of `person`; bij
   `company` is een KvK- of btw-nummer verplicht.
2. Stuur optioneel eerst `POST /v1/accounting/documents/validate` (header
   `X-Account-Id: {tenant}`), toon de findings en laat de gebruiker bevestigen.
3. Boek met `POST /v1/accounting/documents`, headers `X-Account-Id: {tenant}` +
   `Idempotency-Key: {external_id}`. Gebruik `Prefer: respond-async` alleen als ik
   een webhook-callback geregistreerd heb.
4. Verwerk `201 posted`: bewaar `external_ref` (interne GUID) + `external_number`
   (leesbaar boekstuknummer, toon dít aan de gebruiker) in mijn sync-ledger naast
   mijn external_id. Behandel `200` met `deduplicated: true` net zo — dat is
   dezelfde boeking, niet opnieuw uitgevoerd. Behandel `422 mapping_failed` als
   "actie vereist in de Hub-admin". `409 document_already_posted` betekent dat
   ik hetzelfde external_id met andere inhoud stuurde: nooit retryen, maar een
   creditnota + nieuw external_id maken. Retry nooit een 5xx zonder dezelfde
   Idempotency-Key.
5. Toon `warnings[]` uit het antwoord aan de gebruiker — daar staat wat er in zijn
   boekhouding is gebeurd (relatie aangemaakt, op naam gekoppeld, naam wijkt af).
6. Bij `409 relation_ambiguous`: toon de `candidates` uit de body, laat de gebruiker
   de juiste relatie kiezen en boek opnieuw met `party.relation_id`.
Stuur `party.external_id` consistent mee zodat relaties gecachet worden.
```

### Boekhoud-mapping (zelf-service, optioneel)

De Hub synct + auto-derivet de mapping al bij connect — **standaard hoef je hier
niets**. Deze endpoints zijn er voor consumers die de mapping willen tonen/verfijnen.
Alle op header `X-Account-Id` + `exact:read` (lezen) / `exact:write` (schrijven).

| Doel | Request | Ability |
|---|---|---|
| Mirror (her)synchroniseren + auto-derive | `POST /v1/accounting/sync` | `exact:write` |
| Beschikbare codes (GL/BTW/dagboek) | `GET /v1/accounting/reference-data` | `exact:read` |
| Huidige mapping lezen | `GET /v1/accounting/mapping` | `exact:read` |
| Mapping overschrijven (merge) | `PUT /v1/accounting/mapping` | `exact:write` |

PUT-body — alle velden optioneel, **merge** (bestaande waarden blijven):

```json
{
  "vat_codes":   { "21": "3", "9": "1", "0": "0" },
  "gl_accounts": { "_default": "4000", "omzet": "8000" },
  "journals":    { "sales": "70", "purchase": "20" }
}
```

- `vat_codes`/`gl_accounts`/`journals` = stabiele **Codes** uit `reference-data`
  (geen GUIDs — de Hub resolvet die lokaal).

> Merge-only: een bestaande key verwijderen kan niet via PUT — stuur een nieuwe waarde.

**🤖 Agent-prompt**

```text
Bouw (optioneel) een instellingen-scherm voor de boekhoud-koppeling tegen de emeq
Hub. Lees de keuzes met `GET /v1/accounting/reference-data` (header `X-Account-Id`)
en de huidige mapping met `GET /v1/accounting/mapping`. Laat de tenant per BTW-tarief
een VATCode, per categorie een GL-Code en per dagboek-type een Journal kiezen (alles
Codes, geen GUIDs). Sla op met `PUT /v1/accounting/mapping` (merge), body
`{ vat_codes, gl_accounts, journals }` — stuur alleen de gewijzigde velden. Optioneel
een knop "hersynchroniseren" → `POST /v1/accounting/sync`. Default hoeft de tenant
niets in te stellen; de Hub auto-derivet bij connect.
```

## Webhooks ontvangen

Gebeurt er iets bij de gekoppelde partner, dan POST de Hub naar je
`webhook_callback_url`, HMAC-gesigneerd met je `webhook_callback_secret`.

**Elke** webhook heeft dezelfde vorm, ongeacht welk pakket je eindgebruiker
koppelde:

```json
{
  "event": "accounting.sales_invoice.changed",
  "provider": "exact",
  "account_id": "school1",
  "entity_id": "9f1c8e2a-…",
  "action": "updated",
  "occurred_at": "2026-08-11T14:22:03+00:00",
  "hub_authored": true,
  "hub_last_wrote_at": "2026-08-11T14:21:58+00:00",
  "data": { "…": "de ruwe payload van de partner" }
}
```

Vier velden staan er altijd: `event`, `provider`, `account_id`, `occurred_at` en
`data`. De rest staat er alleen als de Hub het kon vaststellen — een veld dat
ontbreekt betekent "weet ik niet", nooit "nee".

- **`event`** — canonieke naam uit het Hub-vocabulaire. Route hierop, niet op
  `provider` en niet op iets uit `data`.
- **`account_id`** — hetzelfde id dat jij bij het koppelen aanleverde (je
  `X-Account-Id`), niet een Hub-intern nummer.
- **`entity_id`** — het id dat het boekhoudpakket zelf aan de gewijzigde entity
  geeft. Dit is hetzelfde id dat je terugkreeg toen je die boeking via de Hub
  wegschreef, dus hiermee vind je je eigen record terug. Vandaag levert alleen
  Exact het; bij Snelstart ontbreekt het veld (hun payload-vorm staat nog open bij
  de partner) en bij Mollie is het de resource-id uit de notificatie.
- **`action`** — wat er met de entity gebeurde: `created`, `updated`, `deleted` of
  `unmapped`. Los van `event`, dat zegt wélk soort entity het is. Mollie levert
  geen actie mee, dus daar ontbreekt het veld.
- **`occurred_at`** — wanneer de Hub het event uitstuurde. De meeste partners
  leveren geen eigen tijdstempel; doen alsof van wel zou liegen over de bron.
- **`hub_authored`** — staat er alleen als hij `true` is, en betekent: **de Hub
  heeft deze entity ooit zelf weggeschreven.** Dus niet: deze wijziging komt van de
  Hub. Een boekhouder die jouw via de Hub geboekte factuur met de hand corrigeert,
  levert óók `hub_authored: true` — dat is precies een wijziging die je wél wilt
  zien. Gebruik dit veld dus niet in je eentje als filter.
- **`hub_last_wrote_at`** — wannéér de Hub deze entity voor het laatst schreef.
  Samen met `hub_authored` kun je hiermee je eigen echo herkennen: komt het event
  binnen kort ná dit tijdstip, dan is het vrijwel zeker het boekhoudpakket dat jouw
  eigen schrijfactie terugkaatst — daar hoef je niets mee. Zit er meer tijd tussen,
  dan heeft een mens iets gewijzigd. Wij kiezen dat venster niet voor je; een paar
  minuten is in de praktijk ruim. **Schrijf binnen dat venster niet terug** — dat is
  een lus.
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
| `accounting.sales_invoice.changed` | verkoopboeking gewijzigd |
| `accounting.purchase_invoice.changed` | inkoopboeking gewijzigd |
| `accounting.journal_entry.changed` | memoriaalboeking gewijzigd |
| `accounting.document.changed` | document (bijlage-container) gewijzigd |
| `accounting.ledger_account.changed` | grootboekrekening gewijzigd |
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

> **Wijziging — `caused_by_hub` is vervallen.** Dat veld beloofde causaliteit maar
> mat auteurschap: het stond ook op `true` bij een handmatige correctie door de
> boekhouder. Vervangen door `hub_authored` (hetzelfde feit, eerlijke naam) plus
> `hub_last_wrote_at` (het tijdstip dat er ontbrak). Gebruikte je `caused_by_hub`
> als echo-filter, vervang dat door "`hub_authored` én binnen N minuten na
> `hub_last_wrote_at`" — dat filtert de echo weg zonder de handmatige correcties
> mee te wissen.

**🤖 Agent-prompt**

```text
Bouw in mijn {stack}-app een publiek webhook-endpoint op {pad} dat de emeq Hub
aanroept. Verwerk als volgt:

1. Verifieer eerst de HMAC-SHA256 in de `Signature`-header over de ruwe
   request-body, met mijn `webhook_callback_secret` uit env. Vergelijk in
   constante tijd. Mismatch → 401, niets verwerken, niets loggen van de body.
2. Zet het endpoint buiten CSRF-bescherming en buiten mijn tenant-auth — de Hub
   authentiseert met de signature, niet met een sessie.
3. Body-vorm is altijd `{ event, provider, account_id, occurred_at, data }`, met
   optioneel `entity_id`, `action`, `hub_authored` en `hub_last_wrote_at`. Route op
   `event` — nooit op `provider` en nooit op iets uit `data`, want die vorm
   verschilt per provider. Behandel een ontbrekend optioneel veld als "onbekend",
   niet als "nee".
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
- Boek een factuur voor het **volledige** bedrag, ook als 'ie maar deels betaald is.
  Betalingen (en betaalsplitsingen zoals een G-rekening) horen op de bankkant van de
  boekhouding, niet in een `income`/`expense`-document — zie
  [Betalingen horen niet in de boeking](#betalingen-horen-niet-in-de-boeking).
- Snelstart is `connectable: false` (geen OAuth) — toon, maar bied geen
  OAuth-connect aan.
- Volledige, altijd-actuele API-referentie: **`/docs/api`** (live gegenereerd).
  Dezelfde spec staat als `api.json` in de repo-root — versioneerd en diffbaar,
  dus je ziet per commit wat er aan het contract wijzigde. CI faalt als die spec
  achterloopt op de routes.
