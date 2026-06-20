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

## Valkuilen

- Param is `return_url` (snake_case), niet `returnUrl` — anders genegeerd (valt
  terug op Origin).
- Geen cookies/`credentials` — auth is puur de PAT (via je proxy).
- Account moet bestaan (Stap 1) vóór je koppelt, anders `404`.
- Snelstart is `connectable: false` (geen OAuth) — toon, maar bied geen
  OAuth-connect aan.
- Volledige, altijd-actuele API-referentie: **`/docs/api`**.
