# emeq Hub — Consumer-integratiehandleiding

Voor ontwikkelaars die een (multi-tenant) consumer-app aan de emeq Hub koppelen.
Eén integratie → alle huidige én toekomstige providers (Exact, Mollie, …). Nieuwe
providers verschijnen vanzelf; je past je code niet aan per provider.

> Altijd-actuele API-referentie: **`/docs/api`** (OpenAPI, auto-gegenereerd).
> Deze handleiding is de narratieve laag eromheen.

> 🤖 **Agent-prompts** — elke sectie sluit af met een copy-paste-prompt voor je
> AI-coding-agent. Vervang `{…}`-placeholders, plak in je agent, en laat 'm dat
> stuk in je consumer-app bouwen. De harde regels (PAT server-side, data-driven
> rendering, snake_case-params) zitten in de prompts verwerkt.

## Inhoudsopgave

- [Concepten](#concepten)
- [Auth — backend-proxy (aanbevolen)](#auth--backend-proxy-aanbevolen)
- [Stap 1 — Account registreren](#stap-1--account-registreren-eenmalig-per-tenant)
- [Stap 2 — Integraties tonen (discovery)](#stap-2--integraties-tonen-discovery)
- [Stap 3 — Koppelen](#stap-3--koppelen)
- [Stap 4 — Terugkomst + status](#stap-4--terugkomst--status)
- [Stap 5 — Loskoppelen](#stap-5--loskoppelen)
- [Boekhouden — documenten valideren & boeken](#boekhouden--documenten-valideren--boeken)
  - [Boekhoud-mapping (zelf-service, optioneel)](#boekhoud-mapping-zelf-service-optioneel)
- [Valkuilen](#valkuilen)

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
  *Integraties*). Boeken vereist daarnaast `exact:write` (zie Boekhouden).
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
      ...(req.get('X-Account-Id')    && { 'X-Account-Id': req.get('X-Account-Id') }),
      ...(req.get('Idempotency-Key') && { 'Idempotency-Key': req.get('Idempotency-Key') }),
      ...(req.get('Prefer')          && { Prefer: req.get('Prefer') }),
    },
    body: ['GET', 'HEAD'].includes(req.method) ? undefined : JSON.stringify(req.body),
  })
  res.status(r.status).send(await r.text())
})
```

**🤖 Agent-prompt**

```text
Bouw in mijn {stack}-app een server-side proxy op `/api/emeq/{path}` die elke
request 1-op-1 doorzet naar `{EMEQ_BASE}/v1/{path}`. Injecteer server-side
`Authorization: Bearer {EMEQ_PAT}` (PAT uit env — NOOIT naar de browser sturen)
en `Accept: application/json`. Forward query-string, JSON-body én de headers
`X-Account-Id`, `Idempotency-Key` en `Prefer` ongewijzigd door. Beveilig de route
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
    "connectable": true, "status": "connected", "connection_id": "12" },
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
→ `{ "connection_id": "12", "redirect_url": "https://…partner-consent…" }`

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
GET /v1/connections/12
→ { "data": { "id": 12, "provider": "exact", "status": "active",
              "revoked_at": null, "fingerprint": "…" } }
```

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
DELETE /v1/connections/12   → 204
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
`integrations:manage` maar op **provider-abilities**: `exact:read` (valideren) +
`exact:write` (boeken) — vraag een PAT met die abilities.

Beide identificeren de tenant via **header** `X-Account-Id: <external_id>` (let op:
een header, niet de query-param `account_external_id` van de connect-laag):

| Doel | Request | Ability |
|---|---|---|
| Capabilities opvragen | `GET /v1/accounting/capabilities` | `exact:read` |
| Valideren (dry-run) | `POST /v1/accounting/documents/validate` | `exact:read` |
| Boeken | `POST /v1/accounting/documents` | `exact:write` |

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

De vergelijking kijkt naar de betekenis van het document, niet naar de exacte bytes:
sleutelvolgorde en `200` versus `200.00` maken geen verschil. Een gewijzigd bedrag,
tarief, regel, datum of factuurnummer wél.

**Onbekende relatie?** Standaard geeft een party die niet in Exact bestaat
`422 mapping_failed`. Staat **auto-create** aan op de Connection (Hub-admin →
Boekhoud-mapping → "Onbekende relaties automatisch aanmaken"), dan maakt de Hub de
relatie zelf aan (match op `vat_number`, anders `name`) en boekt door. Stuur dus
altijd een nette `party.name` (+ `vat_number` indien bekend) mee.

Foutcodes: `403 insufficient_ability` (PAT mist `{provider}:write`) ·
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
