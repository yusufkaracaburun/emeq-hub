# Phase 4: Mollie Connect OAuth-broker — Context

**Gathered:** 2026-05-14
**Status:** Ready for planning

<domain>
## Phase Boundary

Een werkende OAuth-broker waarmee een Account zijn eigen Mollie-account via Mollie Connect aan een Consumer kan koppelen. Levert het provider-agnostische `OAuthFlow`-contract dat ook Snelstart-OAuth / Exact-OAuth / Ibanity-OAuth in v0.3+ kan dragen, plus de eerste implementatie `MollieConnectOAuthFlow`. Automatische refresh van bijna-vervallen tokens gebeurt **lazy** in de pass-through-laag — geen cron.

**Levert MOLL-02 + HUB-02:**
- `App\OAuth\Contracts\OAuthFlow` interface met 4 methods (`getAuthorizationUrl`, `exchangeCode`, `refreshToken`, `revoke`)
- `App\OAuth\Mollie\MollieConnectOAuthFlow` als eerste implementatie
- `App\OAuth\Testing\FakeOAuthFlow` test-fixture die SC 4 (provider-agnostisme) bewijst
- `App\OAuth\OAuthFlowRegistry` (provider-keyed lookup; voorbereidend pattern voor v0.3+ providers)
- `POST /v1/oauth/mollie/init` — Bearer-PAT-protected; Consumer initieert handshake namens een Account, krijgt `redirect_url` terug
- `GET /v1/oauth/mollie/callback` — publieke route; verifieert `state` tegen `oauth_state`-kolom op de pre-aangemaakte Connection, ruilt code in voor tokens, schrijft encrypted naar Connection, zet status='active'
- Migration: `connections.oauth_state` (string, nullable) + `connections.oauth_state_expires_at` (timestamp, nullable) + `connections.status` (enum: pending/active/revoked)
- `App\Console\Commands\PruneOAuthPendingConnections` — opruimcommando voor `status=pending AND oauth_state_expires_at < now()`
- `App\Mollie\HubMollieCredentialResolver` (bindt `Emeq\MollieApi\Contracts\MollieCredentialResolver`) — geactiveerd in deze fase zodat Phase 5a er meteen op kan bouwen; gebruikt huidige `Connection.access_token` na lazy-refresh
- Lazy refresh-laag met Redis-lock per `connection_id`
- Pest-suite voor: authorize-URL bouwen, code-exchange happy path, state-mismatch (CSRF) → 400, refresh-flow happy, refresh-race met lock, FakeOAuthFlow als drop-in

**Niet in Phase 4:**
- `/v1/mollie/*` resource-pass-through (Phase 5a — MOLL-03 + HUB-03)
- Mollie webhook-verifier (Phase 5a — MOLL-04)
- Connect partner-resources: Onboarding-status, Organizations, Profiles, Permissions, ClientLinks (backlog: `MOLL-CONNECT-RES`)
- Cron-based refresh (bewust uitgesloten — pure lazy, zie D-04)

</domain>

<decisions>
## Implementation Decisions

### State + Connection-lifecycle

- **D-01: Connection pre-create bij /init.** `POST /v1/oauth/mollie/init {account_external_id}` maakt onmiddellijk een `Connection`-row aan met `provider='mollie'`, `status='pending'`, `oauth_state=Str::random(48)`, `oauth_state_expires_at=now()+30min`, `access_token=null`. Returnt `{connection_id, redirect_url}` aan de Consumer. Voordeel: alle state in DB → audit-friendly, idempotent retry, geen cache-flush-fragility.
- **D-02: State-TTL = 30 minuten.** Genoeg voor een gebruiker die mid-flow op een ander tabblad gaat, kort genoeg dat orphan-rows niet ophopen.
- **D-03: Callback verifieert state tegen Connection-row.** `GET /v1/oauth/mollie/callback?code=…&state=…` zoekt de Connection met dat `oauth_state`. Geen match OF `oauth_state_expires_at < now()` → 400 (CSRF/expired). Bij match: token-exchange, encrypted opslaan, `status='active'`, `oauth_state=null`. Idempotent — een tweede callback met dezelfde state vindt geen pending row meer en returnt 400.
- **D-09: Orphan-cleanup via artisan-command, niet automatische cron.** `php artisan oauth:prune-pending` ruimt `status=pending AND oauth_state_expires_at < now()` op. Optioneel handmatig of via deploy-hook draaien — geen scheduler verplicht. Past bij D-04 "geen cron filosofie".

### Refresh-strategie

- **D-04: Pure lazy refresh, géén cron.** De pass-through-laag (Phase 5a) check vóór elke SDK-call `connection.expires_at < now()+5min`; zo ja, refresh first. Idle Connections raken nooit een refresh-call. Throttle-vriendelijk: één refresh per active Connection per token-window, schaalt met gebruik niet met DB-grootte. Eerste call van een dag op een Connection kan ~300ms trager zijn (Mollie-token-endpoint round-trip).
- **D-05: Redis-lock per `connection_id`.** Vóór een refresh-call neemt de refresh-helper een `Cache::lock("oauth:refresh:{$connectionId}", 30s)`. Tweede parallel-call wacht op de lock, leest daarna de inmiddels-vernieuwde `access_token`. Voorkomt dubbele refresh-roundtrips bij gelijktijdige pass-through-calls.
- **D-06: Refresh-window = 5 min.** Onder de drempel sync refreshen vóór de SDK-call. Boven de drempel: directe SDK-call. Geen "comfort"-async-job — éénvoud > marginale latency-winst.

### Route + auth

- **D-07: Twee endpoints, twee auth-modes.** `POST /v1/oauth/mollie/init` is Sanctum-protected (Bearer-PAT van de Consumer + ability `mollie:write`). `GET /v1/oauth/mollie/callback` is publiek maar verifieert via `oauth_state` (dat alleen via /init aangemaakt is). Browser draagt geen Bearer; state is de auth voor de callback.
- **D-08: Init returnt redirect_url als JSON, geen HTTP-redirect.** De Consumer-app controleert de respons en stuurt zelf de browser naar de URL (window.location.href = redirect_url). Voordeel: Consumer kan logging/analytics inhaken; geen verstoppertje met cross-origin redirect-chains.

### Scopes + Test-fixture

- **D-10: Scopes hard-coded in `config/services.php`.** `mollie.connect.scopes = ['payments.read','payments.write','customers.read','customers.write','subscriptions.read','subscriptions.write','mandates.read','organizations.read','onboarding.read']` als array. v0.2 = same scopes voor alle Consumers; per-Consumer differentiation kan in v1.0+ als feature aanvragen komen.
- **D-11: Per-Connection `scopes` jsonb-kolom slaat op wát Mollie daadwerkelijk teruggaf.** Mollie kan minder geven dan aangevraagd (gebruiker weigert specifieke scopes). Pass-through-laag (Phase 5a) kan dit veld inspecteren als een 403 terugkomt en een nuttige foutmelding geven (`"je connection mist scope X — re-koppel"`).
- **D-12: Test-fixture = `App\OAuth\Testing\FakeOAuthFlow`** in `app/OAuth/Testing/` (NIET `tests/`, want het is een runtime-class die in feature-tests gebonden wordt). Genereert deterministic fake-tokens (`access_test_fake_<nonce>`), heeft een teller `wasCalled()->n()` voor assertions. Bewijst contract via runtime-binding, niet via interface-introspection.

### Provider-agnostisme

- **D-13: `OAuthFlow` interface bestaat in `app/OAuth/Contracts/`, NIET in `packages/mollie-api/`.** Reden: het contract is Hub-laag (multi-provider scope), niet provider-laag. `packages/mollie-api/` heeft alleen credential-types. SDK blijft dun (per `.ai/project rules` invariant).
- **D-14: `OAuthFlowRegistry::for(string $provider): OAuthFlow`** — keyed lookup via container-tag (`$this->app->tag([MollieConnectOAuthFlow::class], 'oauth.flow.mollie')`). Phase 5a's pass-through-controllers kunnen `app(OAuthFlowRegistry::class)->for($connection->provider)` doen zonder Mollie-specifieke type-hints.
- **D-15: Mollie's `mollie/mollie-api-php` lib heeft GEEN OAuth-dans helpers** — alleen `setAccessToken()`. `MollieConnectOAuthFlow` doet de OAuth-endpoints (`https://api.mollie.com/oauth2/tokens`) direct via Laravel's `Http::post(...)`-facade. Geen Saloon, geen extra deps.

### SDK-binding (geactiveerd in Phase 4 ipv Phase 5a)

- **D-16: `HubMollieCredentialResolver` bindt al in Phase 4.** Bindt `Emeq\MollieApi\Contracts\MollieCredentialResolver` aan een Hub-impl die op een current-Connection leest (uit een per-request `MollieConnectionContext`-service). De refresh-laag (D-04) zit in deze resolver. Reden om in Phase 4 te landen: Phase 5a wil meteen `Mollie::client()` kunnen aanroepen vanuit zijn controllers zonder eerst credentials-wiring te doen.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Plan-source + scope (autoritief)
- `.planning/ROADMAP.md` §"Phase 4: Mollie Connect OAuth-broker" (regels 89-106) — goal, 5 success criteria, depends-on chain
- `.planning/REQUIREMENTS.md` — MOLL-02 (regel ~3) + HUB-02 (regel ~17)
- `.docs/decisions/mollie-passthrough-api.md` — verklaart waarom Phase 5a's pass-through-laag op deze OAuth-broker leunt en hoe exception-mapping samenkomt

### Architectuur-invariants
- `.ai/rules/global.md` — tokens encrypted-at-rest, fingerprint-only in logs, OAuth-flows volgen RFC 6749, automatisch refresh vóór 401
- `.ai/rules/engineering.md` — chirurgisch wijzigen, conflicten oppervlakken, lezen vóór schrijven
- `.ai/project` rules — Consumer ↔ Account ↔ Connection chain is strict; geen Hub-modellen in SDK; tokens via `encrypted` cast
- `CLAUDE.md` — domeinmodel + invariants

### Mollie-docs (de partner-API zelf)
- https://docs.mollie.com/reference/oauth2/authorize — authorize-URL parameters (response_type=code, scope-formaat, state)
- https://docs.mollie.com/reference/oauth2/tokens — token-exchange + refresh-grant endpoint (`POST https://api.mollie.com/oauth2/tokens`)
- https://docs.mollie.com/oauth/overview — scope-lijst + Connect-partner-flow concepten
- `.docs/partners/mollie/` — lokale Mollie-research-docs (mogelijk leeg in deze fase — toevoegen bij planning)

### emeq/mollie-api SDK (al gepubliceerd, dependency)
- `packages/mollie-api/src/Contracts/MollieCredentialResolver.php` — contract dat de Hub straks bindt (D-16)
- `packages/mollie-api/src/Data/MollieOAuthCredentials.php` — DTO-shape; gebruikt door `HubMollieCredentialResolver` om credentials uit een Connection te leveren
- `packages/mollie-api/CHANGELOG.md` — v0.1.0-alpha.1 release-notes (wat de SDK al levert)

### Hub-skeleton (Phase 3 output — fundering voor deze fase)
- `app/Models/Connection.php` — bestaande model + casts; krijgt `oauth_state`, `oauth_state_expires_at`, `status` velden via deze fase
- `app/Models/Account.php` — `consumer_id + external_id`-scoped lookup voor /init
- `app/Sanctum/TokenAbilities.php` — bestaande abilities-constants; `mollie:write` is al gedefinieerd
- `app/Http/Controllers/Api/V1/PingController.php` — single-action controller-pattern voor de twee nieuwe OAuth-controllers
- `tests/Feature/Api/PingTest.php` + `tests/Feature/Api/SanctumAbilityTest.php` — test-pattern voor Bearer-PAT + ability-gating
- `routes/api.php` — bestaand v1-prefix; toevoegen `/v1/oauth/mollie/init` + `/v1/oauth/mollie/callback`
- `.planning/phases/03-hub-skeleton/03-CONTEXT.md` — Phase 3-decisions (encrypted casts, factory-states, single-action controller-conventie)

### Sibling phase-context (referentie voor planning-shape)
- `.planning/phases/02-emeq-mollie-api-foundation/02-CONTEXT.md` — referentie voor CONTEXT.md-granulariteit
- `.planning/phases/03-hub-skeleton/03-PATTERNS.md` — referentie-pattern voor PATTERNS.md-stijl (komt in planner-output)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`Connection`-model** (`app/Models/Connection.php`): heeft al encrypted casts voor `access_token`/`refresh_token`. Phase 4 voegt 3 velden toe (`oauth_state`, `oauth_state_expires_at`, `status`) via een nieuwe migration; cast `status` als enum, andere als plain.
- **`Account`-model**: `consumer_id+external_id`-scoping is al getest (Phase 3 SC-4). `/init` resolved direct via `auth()->user()->accounts()->where('external_id', $req->account_external_id)->firstOrFail()`.
- **`PingController` pattern** (`app/Http/Controllers/Api/V1/PingController.php`): single-action `__invoke`, retourneert plain array. Copy-target voor `OAuth\InitController` + `OAuth\CallbackController`.
- **Sanctum-ability-middleware**: `Route::middleware('auth:sanctum', 'ability:mollie:write')` werkt al — Phase 4 gebruikt 'm op `/init`.
- **`encrypted` cast op Connection**: bewezen in Phase 3 met `ConnectionEncryptionTest`. Geen wiel opnieuw uitvinden.

### Established Patterns
- **`App\Sanctum\TokenAbilities` als `final class` met `public const`** (Phase 3 D): nieuwe Sanctum-abilities voor OAuth-flow (bv. `mollie:connect`) als constants toevoegen; tests verifiëren via `tokenCan()`.
- **`Tests\Feature\Api` sub-namespace** voor HTTP-tests, `Tests\Feature` root voor model-laag-bewijs.
- **`markTestIncomplete` placeholder-pattern** (Phase 3 D): voor Phase-5a-gedrag dat in Phase 4-tests wel logisch zou willen verifiëren maar nog niet kan, gebruik incomplete ipv skip.
- **Factory-states `forSnelstart()` / `forMollie()`** (Phase 3 D): voor Phase 4 voegt Mollie-factory een `pending()` en `active()` state toe.

### Integration Points
- `routes/api.php` — `/v1/oauth/mollie/init` (Sanctum + ability) + `/v1/oauth/mollie/callback` (publiek)
- `config/services.php` — `mollie.connect.{client_id,client_secret,redirect_uri,scopes}`-keys
- `app/Providers/AppServiceProvider.php` — bindt `OAuthFlow`-contract per provider + bindt `MollieCredentialResolver` aan `HubMollieCredentialResolver` (D-16)
- `bootstrap/app.php` — geen wijziging nodig; `apiPrefix='v1'` is al gezet
- `database/migrations/2026_05_14_000003_create_connections_table.php` (Phase 3) — niet wijzigen; nieuwe migration `add_oauth_state_to_connections_table.php` apart toevoegen (forward-only invariant)

</code_context>

<specifics>
## Specific Ideas

- **Redirect-URL is JSON, geen HTTP-redirect** (D-08): host-apps willen analytics/logging hooks vóór de browser-redirect. Consumer-app stuurt zelf met `window.location.href`.
- **Mollie's `mollie/mollie-api-php` heeft géén OAuth-helpers** (D-15): direct `Http::post('https://api.mollie.com/oauth2/tokens', [...])`-calls in `MollieConnectOAuthFlow`. Geen Saloon, geen extra package.
- **CSRF-failure = 400, niet 401/403** (ROADMAP SC-5): 400 (= bad request) past bij "tampered state-parameter"; 401 zou suggereren "log in" wat hier niet klopt.
- **Idempotency**: een tweede callback met dezelfde `state` vindt geen pending-row (al gezet op active of weg-gepruned) en retourneert 400. Geen "fake success" op tweede call.

</specifics>

<deferred>
## Deferred Ideas

- **Activity-based refresh-cron** (`last_used_at`-gated): user-overweging maar gedeferred — pure lazy is voldoende voor v0.2. Heroverwegen als productie laat zien dat de 300ms eerste-call-latency een UX-issue is.
- **Lazy + async pre-emptive refresh-job**: alternatief uit de discuss-fase, gedeferred om dezelfde reden — pure lazy is simpeler en past beter bij de "geen wasted work voor idle accounts"-filosofie.
- **Per-Consumer scope-profielen** (`consumers.mollie_scopes`): v1.0+ feature als derde-partij-Consumers andere scopes nodig blijken. v0.2 = uniforme scopes uit config (D-10).
- **`MOLL-CONNECT-RES` backlog-item**: Mollie-Connect-partner-resources (Onboarding-status / Organizations / Profiles / Permissions / ClientLinks) volgen het Phase 5a pass-through-pattern. Promoten naar active milestone zodra host-app productie-go-live vereist.
- **Snelstart-OAuth, Exact-OAuth, Ibanity-OAuth**: tweede + derde implementatie van het `OAuthFlow`-contract — v0.3+ (zie ROADMAP backlog).
- **OAuth-revoke-flow als publieke endpoint**: D-13's `OAuthFlow::revoke(Connection)` is contract-aanwezig maar Phase 4 levert alleen een artisan-command `php artisan oauth:revoke {connection_id}`. Een Consumer-facing endpoint (`DELETE /v1/connections/{id}`) volgt in Phase 5a/5b (HUB-03/HUB-05 omvat dit al).
- **Filament admin-UI om pending-Connections te zien + handmatig prunen**: Phase 9 (HUB-04).

</deferred>

---

*Phase: 04-mollie-connect-oauth-broker*
*Context gathered: 2026-05-14*
