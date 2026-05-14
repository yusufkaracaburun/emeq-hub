# Phase 5b: Snelstart-pass-through API — Context

**Gathered:** 2026-05-14
**Status:** Ready for planning
**Requirement:** HUB-05 (Pending → blocked by this phase)
**Depends on:** Phase 3 (Hub-skeleton — landed 2026-05-14)

<domain>
## Phase Boundary

Consumer doet HTTP-call naar `/v1/snelstart/{path}` met `Authorization: Bearer <PAT>` + `X-Account-Id: <external_id>`. De Hub:
1. Authoriseert de PAT en checkt de ability (`snelstart:read` voor GET, `snelstart:write` voor POST/PATCH/DELETE)
2. Resolved `X-Account-Id` naar een `Account` van de geauthenticeerde Consumer (cross-Consumer → 404)
3. Resolved de actieve Snelstart-`Connection` voor die Account
4. Bindt `HubSnelstartCredentialResolver` aan `Emeq\SnelstartApi\Contracts\SnelstartCredentialResolver` voor de duur van de request
5. Doet de SDK-call namens die Account (via `RawSnelstartRequest` voor non-OData, OData QueryBuilder voor reads)
6. Logged een audit-rij in een nieuwe `pass_through_calls`-tabel
7. Mapped upstream-side fouten naar 502, passthrough user-input fouten verbatim
8. Stroomt response terug naar Consumer

Plus drie provisioning-endpoints:
- `POST /v1/accounts` — Consumer maakt Account (`external_id`, `display_name`)
- `POST /v1/connections` — Consumer POST't Snelstart-credentials voor een Account (returnt fingerprint, geen raw)
- `GET/DELETE /v1/connections/{id}` — Consumer leest of revoked eigen Connection

**Out of scope** (gedeferd of in andere phases):
- Mollie-pass-through (`/v1/mollie/*`) → Phase 5a
- OAuth-broker, Connect-flows → Phase 4
- Webhook fan-out (uitgaande consumer-callbacks) → later
- Admin-UI voor Connections (revoke via UI) → Phase 9
</domain>

<decisions>
## Implementation Decisions

### Route shape — Catch-all + method-dispatch

**Eén route:**
```php
Route::any('/v1/snelstart/{path}', PassThroughController::class)
    ->where('path', '.*')
    ->middleware(['auth:sanctum', 'abilities:snelstart:read,snelstart:write,*', 'resolve.snelstart.account'])
    ->name('v1.snelstart.passthrough');
```

`PassThroughController::__invoke()` dispatcht op `$request->method()` naar een private handler. Past bij PROJECT.md *"pass-through is dunne laag"* — geen verzonnen endpoints, nieuwe Snelstart-route requires zero code-deploy. Scramble OpenAPI documenteert de catch-all op één regel; researcher/planner moeten valideren of "try it out" werkt voor een wildcard.

Excluded methods (OPTIONS/HEAD/TRACE) gaan naar 405. Method-whitelist via `Route::any` + expliciete dispatch is voldoende; geen `Route::match([...])` nodig.

### Resolver binding — Per-request scoped middleware

**Nieuwe middleware:** `ResolveSnelstartAccount` (alias `resolve.snelstart.account`)

Flow:
1. Lees `X-Account-Id` header. **Ontbreekt** → 400 met `{error: 'missing_account_header'}` (alleen op pass-through-route, niet op provisioning)
2. Lookup `Account::where('consumer_id', $request->user()->id)->where('external_id', $headerValue)->first()`. **Geen match** → 404 met `{error: 'account_not_found'}` (cross-Consumer = 404, niet 403)
3. Lookup actieve `Connection::where('account_id', $account->id)->where('provider', 'snelstart')->whereNull('revoked_at')->first()`. **Geen match** → 404 met `{error: 'connection_not_found'}`
4. Bind:
   ```php
   app()->instance(
       \Emeq\SnelstartApi\Contracts\SnelstartCredentialResolver::class,
       new HubSnelstartCredentialResolver($connection)
   );
   ```
5. Set `$request->attributes->set('snelstart_connection', $connection)` voor controller en audit-logging

Controllers blijven dun — geen Account-context-threading.

### Audit-log — Nieuwe `pass_through_calls`-tabel

**Niet** hergebruik van `webhook_calls` (deviatie van HUB-05 ROADMAP-tekst). Reden:
- PROJECT.md beschrijft `webhook_calls` voor *inkomend van partner → uitgaande consumer-callback* — een fundamenteel ander stream-pattern
- Mengen levert lelijke `direction`-discriminator-queries en NULL-kolommen op
- Separate tabel = clean indexes + retention-policy-flexibiliteit

**Schema:**
```
id              bigint PK
consumer_id     foreignId -> consumers.id, cascadeOnDelete
account_id      foreignId -> accounts.id, cascadeOnDelete
connection_id   foreignId -> connections.id, nullOnDelete
provider        string                 -- 'snelstart' (provider-agnostisch voor toekomstige 5c+)
method          string                 -- HTTP-method
path            text                   -- volledig request-path inclusief query-string
status          smallint               -- upstream HTTP-status (of 0 bij network-error)
duration_ms     integer                -- end-to-end latency
request_fingerprint  string(12)        -- sha256 van geserialiseerde request-body[0..12]; NULL voor GET
response_size_bytes  integer nullable  -- voor capacity-planning, geen body-content
upstream_error  string nullable        -- short error-code bij 502-rewrap (bv. 'snelstart_auth')
created_at      timestamp              -- géén updated_at (immutable audit-rij)
INDEX (consumer_id, created_at)        -- per-Consumer chronologisch
INDEX (account_id, created_at)
INDEX (status) WHERE status >= 500     -- partial-index voor failure-monitoring
```

**Nooit in audit:** raw `client_key`, `subscription_key`, response-body, request-body. Alleen metadata + fingerprint.

### Audit-timing — Synchroon na response

Audit-rij wordt geschreven in dezelfde request-cycle, na de SDK-call returnt maar vóór de Hub-response naar de Consumer gaat. ~1ms latency-cost, geen queue-dependency, geen verlies-risico bij worker-crash. Past bij v0.2-schaal. Async via Horizon wordt overwogen wanneer audit-write een meetbare bottleneck wordt (later).

### Header forwarding — Whitelist

Headers die de Hub doorzet naar Snelstart bij pass-through:
- `Accept`
- `Content-Type`
- `If-Match`
- `If-None-Match`

Alle andere headers (`Authorization`, `X-Account-Id`, `X-*`, `Cookie`, `User-Agent`) worden gestript vóór de SDK-call. De SDK voegt zelf de Snelstart-auth-headers toe via de resolver. Whitelist > blacklist omdat toekomstige Hub-headers anders automatisch lekken.

### Upstream-error mapping

| Snelstart returnt | Hub returnt | Reden |
|---|---|---|
| 2xx | passthrough verbatim | happy path |
| 400, 404, 422 (user-input fouten) | passthrough verbatim | Consumer's payload was fout, info-disclosure-veilig |
| 401, 403, 5xx | **502 Bad Gateway** met `{error: 'upstream_error', upstream_status: <int>, upstream_detail: <short-msg>}` | Lekt geen info over of Snelstart-auth faalde (kan ook ongeldige clientKey zijn = security state) |
| 429 | passthrough met `Retry-After`-header verbatim | Snelstart's rate-limit moet Consumer kunnen respecteren |
| Network-error (timeout, refused) | 504 Gateway Timeout met `{error: 'upstream_timeout'}` | Onderscheid van 502 voor monitoring/alerting |

`upstream_error`-kolom in `pass_through_calls` krijgt de short-code (`snelstart_auth`, `snelstart_5xx`, `snelstart_timeout`) zodat dashboards op causes kunnen aggregeren zonder body-parsing.

### Provisioning-endpoints

**`POST /v1/accounts`** — body `{external_id: string, display_name: string|null}`. Ability: `snelstart:write` of `mollie:write` of `consumer:manage-accounts` of `*`. Response: full Account-resource (id, external_id, display_name, created_at). Conflict (`UNIQUE (consumer_id, external_id)`) → 409.

**`POST /v1/connections`** — body `{account_id: int|external_id: string, provider: 'snelstart', credentials: {client_key, subscription_key, subscription_id}}`. Returnt:
```json
{
  "id": 42,
  "account_id": 7,
  "provider": "snelstart",
  "status": "active",
  "fingerprint": "ac942340c588",
  "created_at": "..."
}
```
Géén raw credentials in response. Bestaande actieve Connection per (account, provider) → 409 (unique-index uit cleanup-pass).

**`GET /v1/connections/{id}`** — alleen own Connections (Connection.account.consumer_id = current Consumer); else 404.

**`DELETE /v1/connections/{id}`** — zet `revoked_at = now()`, returnt 204. Toekomstige Phase 4: trigger ook upstream-revoke via `OAuthFlow::revoke()` — niet in 5b-scope omdat Snelstart geen OAuth-revoke endpoint heeft.

### Error-response format (alle endpoints in deze phase)

Consistent JSON shape:
```json
{
  "error": "snake_case_code",
  "message": "Human-readable Nederlands",
  "details": { ... }   // optional per endpoint
}
```

HTTP-status volgt de error-code-tabel hierboven + standaard Laravel-validation (422 met `errors`-object voor Form-Request-faalures).

### PAT-ability-policy (consolidatie van Phase 3 + cleanup)

| Endpoint | Required abilities (any of) |
|---|---|
| `POST /v1/accounts` | `snelstart:write`, `mollie:write`, `consumer:manage-accounts`, `*` |
| `POST /v1/connections` | `consumer:manage-accounts`, `<provider>:write` (e.g. `snelstart:write`), `*` |
| `GET /v1/connections/{id}` | `snelstart:read`, `snelstart:write`, `consumer:manage-accounts`, `*` |
| `DELETE /v1/connections/{id}` | `consumer:manage-accounts`, `<provider>:write`, `*` |
| `GET /v1/snelstart/{path}` | `snelstart:read`, `snelstart:write`, `*` |
| `POST/PATCH/DELETE /v1/snelstart/{path}` | `snelstart:write`, `*` |

De Phase 3 `SanctumAbilityTest`-placeholder kan in deze phase tot concrete tests uitgewerkt worden.
</decisions>

<specifics>
## Specifieke Ideeën

- **Smoke-endpoint voor SC-3:** `GET /v1/snelstart/echo/ping` proxied naar Snelstart's `echo/ping` (geen credentials nodig voor echo, perfect voor binding-test). Validateer in research dat dit endpoint inderdaad bestaat in Snelstart's B2B-API.
- **OData read voor SC-4:** `GET /v1/snelstart/relaties?$top=5` valideert de QueryBuilder-pad. `?$top=5` query-string moet 1-op-1 doorgezet worden in de SDK-call (URL-encoding correct laten).
- **Naming `pass_through_calls`:** snake_case Engels (matched bestaande migrations). Model: `App\Models\PassThroughCall` (singular).
- **`HubSnelstartCredentialResolver`:** in `app/Services/Snelstart/HubSnelstartCredentialResolver.php`. Constructor neemt `Connection` en exposeert `resolve(): SnelstartCredentials` met decrypted values van die specifieke Connection. Geen multi-Account-state intern.
- **`PassThroughController`:** in `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php`. Folder-by-provider zodat 5a's Mollie-controller hetzelfde patroon volgt.
- **Scramble route-discovery:** controleer in plan-phase research of Scramble `Route::any` met wildcard-where correct als één OpenAPI-operation rendert, en of "Try it out" een placeholder voor `{path}` accepteert. Anders kunnen specifieke routes per Snelstart-resource overwogen worden — maar dat is een fallback, niet de voorkeur.
</specifics>

<canonical_refs>
## Canonical References

**MUST read voor planning:**
- `.planning/ROADMAP.md` — HUB-05 Phase 5b sectie (success criteria, locked context)
- `.planning/REQUIREMENTS.md` — HUB-05 volledige requirement-tekst
- `.planning/phases/03-hub-skeleton/03-CONTEXT.md` — domeinmodel + abilities
- `.planning/phases/03-hub-skeleton/03-01-SUMMARY.md` t/m `03-05-SUMMARY.md` — wat in Phase 3 landed (factories, models, routes)
- `.docs/partners/snelstart/api-definition.yaml` — Snelstart OpenAPI-spec (autoritatief voor endpoint-shapes en error-codes)
- `.docs/partners/snelstart/authenticatie-ac80d051.md` — clientKey + subscriptionKey auth-flow (PROJECT.md "geen verzonnen partner-features")
- `.docs/partners/snelstart/foutcodes-v2-3bbfc32a.md` — foutcode-tabel die de upstream-error-mapping moet matchen
- `.docs/partners/snelstart/odata-3cc065de.md` — OData query-conventies voor SC-4
- `packages/snelstart-api/src/Contracts/SnelstartCredentialResolver.php` — interface waarvoor we de Hub-implementatie binden
- `packages/snelstart-api/src/Data/SnelstartCredentials.php` — DTO voor de credentials
- `packages/snelstart-api/src/Http/Request/RawSnelstartRequest.php` — request-builder voor non-OData calls
- `packages/snelstart-api/src/OData/QueryBuilder.php` — voor SC-4 (`?$top=5`-pad)
- `app/Models/Connection.php` — bestaande encrypted-casts + fingerprint-accessor (gebruik niet opnieuw definiëren)
- `app/Sanctum/TokenAbilities.php` — bestaande ability-constants
- `app/Providers/AppServiceProvider.php` — bestaande RateLimiter `api` binding (Phase 3 cleanup)

**MAY consult:**
- `.docs/decisions/intern-admin-ui-filament.md` — Phase 9 ADR die noemt dat `Connection::fingerprint()` accessor de UI-laag voedt; pas op dat we die accessor niet breken
- `.planning/STATE.md` — Quick Tasks Completed-tabel voor recent cleanup-context (BL-02, WR-01, WR-03)
</canonical_refs>

<deferred>
## Noted for Later

- **Upstream-revoke voor Snelstart in `DELETE /v1/connections/{id}`** — Snelstart heeft geen revoke-endpoint, dus Hub-only `revoked_at`-flag is voldoende in 5b. Toekomstige Phase 4 introduceert `OAuthFlow::revoke()` voor providers die het wel hebben (Mollie Connect).
- **Async audit-writes via Horizon** — overwegen wanneer `pass_through_calls`-write latency meetbaar wordt (>5ms p99) of bij hoge throughput. Niet nu.
- **Per-Account rate-limit** — huidige throttle is per-Consumer (60/min). Bij meer Snelstart-volume kan per-Account-throttle nodig zijn om één misdragende Account andere Accounts van dezelfde Consumer niet te laten verstoren. Backlog.
- **Webhook-handling vanuit Snelstart** — Snelstart heeft geen webhook-API, dus N/A voor 5b. Mollie 5a wel.
- **`pass_through_calls` retention-policy** — partitioning of cleanup-job na N maanden. Niet in 5b-scope; eerst data-volume meten.
- **`PassThroughCalled` Laravel-event** — voor toekomstige listeners (metrics, alerting). 5b emit dat event niet expliciet; eerst minimum viable audit-tabel.
- **Generic `/v1/{provider}/{path}` super-catchall** — alle providers door één controller-laag laten gaan. Verleidelijk maar maakt provider-specifieke header/error-mapping moeilijker. Per-provider blijft beter voor v0.2. Mogelijk re-evalueren in v0.3.
</deferred>

<gray_areas_resolved>
## Discussion Summary

| Area | Decision | Rationale |
|---|---|---|
| Pass-through route shape | Catch-all `/v1/snelstart/{path}` + method-dispatch | Past bij "dunne laag", zero-deploy voor nieuwe Snelstart-endpoints |
| Resolver binding | Per-request scoped via `ResolveSnelstartAccount`-middleware | Idiomatisch Laravel + matched SDK README "Credentials wiring" |
| Audit-log table | Nieuwe `pass_through_calls`-tabel (deviatie van ROADMAP "webhook_calls") | Cleaner mental model, separate streams |
| Upstream-error mapping | 502 voor upstream-side (5xx/auth), passthrough voor user-input (4xx) | Voorkomt info-disclosure over Snelstart-auth-state |
| Audit timing | Synchroon na response | Geen queue-dependency, ~1ms cost, geen verlies-risico |
| Header forward | Whitelist (Accept, Content-Type, If-Match, If-None-Match) | Voorkomt automatische leak van toekomstige Hub-headers |
| Missing Connection error | 404 + `connection_not_found` | Consistent met cross-Consumer-policy, geen Account-existence-disclosure |
</gray_areas_resolved>
