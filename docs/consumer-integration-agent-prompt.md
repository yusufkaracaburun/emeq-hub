# Implementatie-prompt — emeq Hub integraties in een consumer-app

Kopieer alles onder de lijn naar de AI-agent van je consumer-app. De prompt is
generiek (werkt voor elke consumer-app); de agent vult de stack-specifieke details
zelf in. Achtergrond/contracten staan in [`consumer-integration-guide.md`](./consumer-integration-guide.md);
de live API-referentie op `/docs/api`.

---

Je implementeert de **emeq Hub integraties-koppeling** in deze consumer-app: een
scherm waar een tenant (Account) boekhoud-/betaalpartners (Exact, Mollie, …) kan
koppelen en loskoppelen. De app is multi-tenant. Lees eerst de bestaande
auth-/tenant-laag en het settings-/integraties-scherm voordat je begint.

## Harde constraints (niet overtreden)

1. **PAT nooit in de browser.** De emeq-PAT is een server-side secret. Alle
   Hub-calls lopen via een **backend-proxy** in deze app die de PAT injecteert.
   De frontend praat alleen met je eigen origin (`/api/emeq/*`), nooit direct met
   de Hub.
2. **Data-driven renderen.** Hardcode GEEN providerlijst. Render wat
   `GET /v1/integrations` teruggeeft, zodat een nieuwe provider automatisch
   verschijnt zonder code-wijziging.
3. **Geen cookies** richting de Hub (`credentials: 'omit'` / geen cookie-jar in
   de proxy). Auth is puur de Bearer-PAT.
4. **Snake_case.** Request-params zijn snake_case (`account_external_id`,
   `return_url`). `returnUrl` wordt genegeerd.

## Config

```
EMEQ_BASE = https://hub.emeq.nl          # prod ; dev: https://hub-dev.emeq.nl
EMEQ_PAT  = <server-side secret, ability: integrations:manage>
```
De PAT vraag je bij emeq op (admin → Consumer → "Issue PAT" → preset *Integraties*).
`integrations:manage` dekt koppelen, status lezen en loskoppelen van álle providers.

## Hub-API-contract (geverifieerd)

Alle paden onder `EMEQ_BASE/v1`. Header: `Authorization: Bearer <PAT>`, `Accept: application/json`.

| Doel | Request | Respons |
|---|---|---|
| Account registreren | `POST /accounts` `{ "external_id": "...", "display_name": "..." }` | `201` AccountResource · `409` bestaat al |
| Integraties + status | `GET /integrations?account_external_id=...` | `200` lijst (zie onder) |
| Koppelen (init) | `POST /oauth/{provider}/init` `{ "account_external_id": "...", "return_url"?: "..." }` | `200` `{ connection_id, redirect_url }` · `404`/`503`/`403` |
| Status | `GET /connections/{id}` | `200` `{ data: { id, provider, status, revoked_at, fingerprint } }` |
| Loskoppelen | `DELETE /connections/{id}` | `204` |

`GET /integrations` item-vorm:
```json
{ "key":"exact", "label":"Exact Online", "tagline":"...", "category":"Boekhouden",
  "logo":"/img/partners/exact.svg", "brand":"#e1141d",
  "connectable":true, "status":"connected|pending|disconnected", "connection_id":"12|null" }
```

## Taken

1. **Backend-proxy.** Voeg een geauthentiseerde route toe (achter je eigen
   tenant-auth) die `/api/emeq/{path}` doorzet naar `EMEQ_BASE/v1/{path}` met de
   PAT-header, query-string en JSON-body. Forward status + body 1-op-1.
   - Laravel-voorbeeld:
     ```php
     Route::any('/api/emeq/{path}', function (\Illuminate\Http\Request $r, string $path) {
         return \Illuminate\Support\Facades\Http::withToken(config('services.emeq.pat'))
             ->withHeaders(['Accept' => 'application/json'])
             ->send($r->method(), config('services.emeq.base')."/v1/{$path}", [
                 'query' => $r->query(),
                 'json'  => $r->isJson() ? $r->json()->all() : null,
             ])->toPsrResponse();
     })->where('path', '.*')->middleware('auth');
     ```
   - Beperk de proxy tot de paden die de UI nodig heeft (`accounts`,
     `integrations`, `oauth/*/init`, `connections/*`) als je wilt hardenen.

2. **Account zorgen-dat-bestaat.** Bij eerste gebruik van een tenant:
   `POST /accounts` met de tenant-`external_id` (jouw stabiele tenant-sleutel) +
   `display_name`. `409` = al goed, negeren.

3. **Integraties-lijst.** Op het integraties-scherm: haal
   `GET /integrations?account_external_id=<tenant>` op en render een kaart per
   item — logo/label/tagline/category uit de respons. Per kaart:
   - `status: connected` → "Verbonden" + knop *Loskoppelen*.
   - `status: disconnected` + `connectable: true` → knop *Koppelen*.
   - `connectable: false` → toon als niet-koppelbaar ("binnenkort"/extern), geen knop.

4. **Koppelen.** Klik *Koppelen* → `POST /oauth/{key}/init` met
   `account_external_id`. Bewaar `connection_id`. Stuur de browser naar
   `redirect_url`. `return_url` mag je weglaten (de Hub gebruikt automatisch je
   Origin → tenant-root); geef 'm alleen mee als je op een specifiek pad terug
   wilt (host = jouw tenant-domein).

5. **Terugkomst + status.** Na consent stuurt de Hub de gebruiker automatisch
   terug. Bij laden van het scherm (of op je `?...=return`-marker): her-poll
   `GET /integrations?account_external_id=<tenant>` (of `GET /connections/{id}`)
   en schakel de UI naar "Verbonden" zodra `status:"active"` / `connected`.

6. **Loskoppelen.** Klik *Loskoppelen* → `DELETE /connections/{connection_id}`
   → `204`. De Hub doet de provider-teardown. Ververs de lijst.

7. **Foutafhandeling.** `401` (PAT/proxy-config), `403 insufficient_ability`
   (PAT mist `integrations:manage`), `404` (account/connection bestaat niet of al
   revoked), `503 provider_disabled` (kill-switch). Toon nette meldingen.

## Acceptatiecriteria

- Nieuwe provider bij de Hub verschijnt in het scherm **zonder** code-wijziging
  (bewijs: de lijst komt 100% uit `GET /integrations`).
- PAT staat nergens in frontend-bundles of netwerk-calls vanuit de browser.
- Volledige cyclus werkt per tenant: koppelen → Verbonden → loskoppelen →
  opnieuw koppelen.
- Tweede tenant ziet niet de koppelstatus van een andere tenant.

## Niet doen

- Geen providerlijst hardcoden of per provider een aparte connect-knop bouwen.
- Geen PAT of `EMEQ_PAT` naar de client sturen.
- De OAuth `redirect_uri` niet zelf invullen — die is Hub-globaal.
- Geen `returnUrl` (camelCase); gebruik `return_url` of laat 'm weg.

Lever per stap: gewijzigde bestanden, en een korte demo/test van de volledige
cyclus tegen `https://hub-dev.emeq.nl`.
