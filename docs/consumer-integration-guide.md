# emeq Hub — Consumer-integratiehandleiding

Voor ontwikkelaars die een (multi-tenant) consumer-app aan de emeq Hub koppelen.
Eén integratie → alle huidige én toekomstige providers (Exact, Mollie, …). Nieuwe
providers verschijnen vanzelf; je past je code niet aan per provider.

> Altijd-actuele API-referentie: **`/docs/api`** (OpenAPI, auto-gegenereerd).
> Deze handleiding is de narratieve laag eromheen.

## Concepten

| Begrip | Betekenis |
|---|---|
| **Consumer** | Jouw app (admin.emeq.nl / admin.planny.nl / …). Authentiseert met een PAT. |
| **Account** | Een eindklant/tenant van jouw app, geïdentificeerd door jouw eigen `external_id` (bv. `bob`, `school1`). |
| **Connection** | Eén koppeling tussen één Account en één provider. |
| **Provider** | Boekhoud-/betaalpartner (Exact, Mollie, Snelstart, …). |

## Auth — backend-proxy (aanbevolen)

De PAT is een **server-side secret** — zet 'm nooit in de browser. Patroon:

```
Browser (tenant-SPA)  →  jouw backend (/api/emeq/*)  →  emeq Hub (/v1/*)
                          injecteert Authorization: Bearer <PAT>
```

- PAT-ability: **`integrations:manage`** — één token koppelt + beheert alle
  providers. Aanvragen bij emeq (admin → Consumer → "Issue PAT" → preset
  *Integraties*).
- Base-URL: `https://hub.emeq.nl` (prod) · `https://hub-dev.emeq.nl` (dev).
- Alle requests: `Authorization: Bearer <PAT>`, `Accept: application/json`.
  Geen cookies.

> CORS staat elke https-origin toe, dus een directe browser→Hub-call mét PAT
> werkt technisch ook — maar lekt je PAT. Gebruik de proxy.

### Backend-proxy (Laravel)

```php
Route::any('/api/emeq/{path}', function (Request $r, string $path) {
    return Http::withToken(config('services.emeq.pat'))
        ->withHeaders(['Accept' => 'application/json'])
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
    },
    body: ['GET', 'HEAD'].includes(req.method) ? undefined : JSON.stringify(req.body),
  })
  res.status(r.status).send(await r.text())
})
```

## Stap 1 — Account registreren (eenmalig per tenant)

```http
POST /v1/accounts
{ "external_id": "bob", "display_name": "Bob B.V." }
```

`201` → aangemaakt. `409` → bestaat al (idempotent te behandelen). `external_id`
is jouw sleutel; gebruik 'm overal hierna.

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

## Stap 5 — Loskoppelen

```http
DELETE /v1/connections/12   → 204
```

De Hub doet de volledige provider-teardown (token-revoke + webhook-subscriptions).
Opnieuw koppelen = Stap 3 herhalen (de Hub regelt de schone reconnect).

## Boekhouden — documenten valideren & boeken

Zodra een tenant een **boekhoud-provider** gekoppeld heeft (Stap 1–4, bv. Exact),
kun je financiële documenten valideren en boeken. Deze endpoints lopen **niet** op
`integrations:manage` maar op **provider-abilities**: `exact:read` (valideren) +
`exact:write` (boeken) — vraag een PAT met die abilities.

Beide identificeren de tenant via **header** `X-Account-Id: <external_id>` (let op:
een header, niet de query-param `account_external_id` van de connect-laag):

| Doel | Request | Ability |
|---|---|---|
| Valideren (dry-run) | `POST /v1/accounting/documents/validate` | `exact:read` |
| Boeken | `POST /v1/accounting/documents` | `exact:write` |

> Je backend-proxy moet de headers `X-Account-Id`, `Idempotency-Key` en `Prefer`
> mee-forwarden — het proxy-voorbeeld hierboven stuurt alleen query + body door.

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
  "party": { "role": "creditor", "name": "Leverancier BV", "vat_number": "NL000099998B57", "iban": "NL91ABNA0417164300" },
  "lines": [
    { "description": "Dienst", "amount": 100.00, "tax_rate": 21, "category": "kantoorkosten" }
  ],
  "attachments": [
    { "filename": "factuur.pdf", "mime_type": "application/pdf", "content": "<base64>" }
  ]
}
```

- `type` ∈ `sales_invoice` · `purchase_invoice` · `credit_note` · `income` · `expense`.
- `party.role`: `debtor` (verkoop) of `creditor` (inkoop). `vat_number`/`iban`/`external_id` optioneel.
- `lines[].amount` = **netto** regelbedrag (leidend); `tax_rate` = percentage (0/9/21).
  `quantity`/`unit_price` optioneel/informatief; `category` = GL-hint.
- `external_id` = jouw stabiele document-sleutel (echo't terug — gebruik 'm in je sync-ledger).
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
| `vat_number.normalize` | info | BTW-nummer geldig maar niet genormaliseerd |
| `vat_treatment.reverse_charge_expected` | warning | Intra-EU B2B met BTW-nr → 0% (verlegd) verwacht |
| `vat_treatment.domestic_rate_on_non_eu` | error | Niet-EU leverancier met binnenlands tarief |
| `geography.country_mismatch` | warning | Land uit BTW-nr ≠ land uit IBAN |
| `currency.foreign` | info | Andere valuta dan EUR |
| `exact.vat_code.matched` | info | Tarief → Exact-VATCode (`suggestion` = code) |
| `exact.vat_code.unmapped` | warning | Tarief nog niet gekoppeld aan een Exact-VATCode |
| `exact.relation.matched` | info | Leverancier = bestaande Exact-relatie (`suggestion` = GUID) |
| `exact.relation.new` | info | Leverancier nog niet in Exact (wordt nieuw bij boeken) |

> `exact.*` verschijnen alleen bij een Exact-connection; de rest is provider-agnostisch.

### Boeken

```http
POST /v1/accounting/documents
X-Account-Id: bob
Idempotency-Key: factuur-2026-0042
{ …canonical document… }
```

- **`Idempotency-Key` is verplicht** — bij retry herhaalt de Hub de eerste respons
  i.p.v. dubbel te boeken. Gebruik een stabiele sleutel per document (bv. je `external_id`).
- Synchroon (default): `201` `{ "provider": "exact", "status": "posted", "external_id": "…", "external_ref": "…" }`.
  `external_ref` = de document-ID bij het pakket; bewaar 'm.
- Asynchroon: stuur `Prefer: respond-async` → direct `202` `{ "status": "pending", "external_id": "…" }`.
  Het eindresultaat komt per webhook (`accounting.document.synced`, HMAC-gesigneerd met je
  `webhook_callback_secret`) op je `webhook_callback_url`. Async zonder geregistreerde
  callback → `400 webhook_required`.

`status` ∈ `posted` / `pending` / `rejected` / `failed`.

Foutcodes: `403 insufficient_ability` (PAT mist `{provider}:write`) ·
`422 mapping_failed` (de boekhoud-mapping op de Connection is onvolledig — los op in de
Hub-admin) · `503 provider_disabled` · `502/503/504` upstream (pakket onbereikbaar/
onderhoud/timeout, met `Retry-After` waar relevant). Elke fout draagt
`{ "status": "failed", "external_id": "…", "error": "…", "message": "…" }`.

## Valkuilen

- Param is `return_url` (snake_case), niet `returnUrl` — anders genegeerd (valt
  terug op Origin).
- Geen cookies/`credentials` — auth is puur de PAT (via je proxy).
- Account moet bestaan (Stap 1) vóór je koppelt, anders `404`.
- Snelstart is `connectable: false` (geen OAuth) — toon, maar bied geen
  OAuth-connect aan.
- Volledige, altijd-actuele API-referentie: **`/docs/api`**.
