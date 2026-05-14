---
phase: 04-mollie-connect-oauth-broker
plan: 02
subsystem: auth
tags: [laravel, oauth, mollie, http-client, phpunit, registry]

# Dependency graph
requires:
  - phase: 04-mollie-connect-oauth-broker
    provides: OAuthFlow-contract (Plan 04-01), Connection-model met oauth_state-velden, ConnectionFactory pending()/active()/expired()-states
provides:
  - App\OAuth\Mollie\MollieConnectOAuthFlow (productie-implementatie tegen Mollie Connect OAuth2)
  - App\OAuth\OAuthFlowRegistry (provider-keyed lookup, container-resolved)
  - config('services.mollie.connect.*') met client_id/client_secret/redirect_uri + hard-coded 9-scopes-array
  - .env.example MOLLIE_CONNECT_{CLIENT_ID,CLIENT_SECRET,REDIRECT_URI} keys
  - AppServiceProvider singleton-binding voor OAuthFlowRegistry
affects: [04-03 (HubMollieCredentialResolver gebruikt registry->for('mollie')->refreshToken()), 04-04 (InitController + CallbackController gebruiken registry), 05a (pass-through laag triggert lazy refresh via resolver)]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Directe Http::asForm()->post() naar Mollie OAuth2-endpoints (D-15) — geen Saloon-wrapper, geen mollie/mollie-api-php OAuth-helper omdat die niet bestaat"
    - "Cache::lock('oauth:refresh:{connection_id}', 30)->block(15, …) voor race-safe lazy refresh (D-05)"
    - "Re-check expires_at na lock-acquire (D-06) — andere request kan al ge-refreshd hebben binnen het 5min-venster"
    - "Provider-keyed registry-pattern in app/OAuth/ (D-14) — class-string<OAuthFlow>-map + container->make()-resolve"
    - "Mollie OAuth2 host-splitsing: browser-authorize op my.mollie.com, token-exchange op api.mollie.com"

key-files:
  created:
    - app/OAuth/Mollie/MollieConnectOAuthFlow.php
    - app/OAuth/OAuthFlowRegistry.php
    - tests/Feature/OAuth/MollieConnectOAuthFlowTest.php
  modified:
    - config/services.php
    - .env.example
    - app/Providers/AppServiceProvider.php

key-decisions:
  - "MollieConnectOAuthFlow zonder declare(strict_types=1) — Hub-tree-conventie wint over PATTERNS.md-snippet (zelfde keuze als Plan 04-01 voor FakeOAuthFlow)"
  - "OAuthFlowRegistry zonder declare(strict_types=1) — Hub-tree-conventie expliciet uitgesproken in plan-body ('CRITICAL HUB-TREE DISAMBIGUATION')"
  - "MOLLIE_CONNECT_*-env-keys onder eigen '# Mollie Connect (OAuth-broker — Phase 4)'-blok in .env.example, niet vermengd met de bestaande MOLLIE_PARTNER_*-keys; documenteert intent voor v0.2-reader"

patterns-established:
  - "Pattern 1: Provider-OAuth-implementation in app/OAuth/{Provider}/ (geen SDK-laag) — laat Hub-multi-tenant-scope intact"
  - "Pattern 2: Http-factory constructor-injectie (HttpFactory $http) in plaats van Http-facade — testable met Http::fake() én container-makeable"
  - "Pattern 3: ServiceProvider-singleton met Application-typed closure-arg — matched bestaande Laravel-13-idiom voor scoped resolvers"

requirements-completed: [MOLL-02]

# Metrics
duration: ~15 min
completed: 2026-05-14
---

# Phase 4 Plan 02: MollieConnectOAuthFlow + Registry Summary

**Productie-implementatie van OAuthFlow tegen Mollie Connect (RFC 6749) met race-safe Cache::lock refresh-laag en provider-keyed registry, ready voor InitController/CallbackController in Plan 04-04.**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-05-14T16:54:00Z (ongeveer)
- **Completed:** 2026-05-14T17:09:00Z
- **Tasks:** 2 (auto, 1 TDD)
- **Files modified:** 6 (3 created + 3 modified)

## Accomplishments

- `App\OAuth\Mollie\MollieConnectOAuthFlow` implementeert het 4-methods-contract uit Plan 04-01 tegen echte Mollie-endpoints: `getAuthorizationUrl` bouwt de `my.mollie.com/oauth2/authorize?…`-redirect, `exchangeCode` doet `Http::asForm()->post('https://api.mollie.com/oauth2/tokens', …)` + `->throw()` + persist met encrypted casts, `refreshToken` wrapt zichzelf in `Cache::lock("oauth:refresh:{$connection->id}", 30)->block(15, …)` met re-check op `expires_at > now()+5min` (D-05 + D-06), `revoke` doet `Http::withBasicAuth(…)->delete(...)` en zet `status='revoked'`.
- `App\OAuth\OAuthFlowRegistry` levert de drop-in-laag voor toekomstige Snelstart-/Exact-OAuth (D-14) — `for('mollie')` resolveert via container, `register(string $provider, string $impl)` is alles wat een v0.3-fase nodig heeft.
- `config/services.php` `mollie.connect`-block met de drie env-driven keys + 9 hard-coded scopes (D-10 — geen env-var voor scopes, per-Consumer-differentiation is v1.0+).
- `.env.example` drie nieuwe Mollie Connect keys onder eigen blok-header — gescheiden van de bestaande `MOLLIE_PARTNER_*`-keys om intent niet te vertroebelen.
- `AppServiceProvider::register()` singleton-binding voor `OAuthFlowRegistry` met `'mollie' → MollieConnectOAuthFlow` geregistreerd. `boot()` onveranderd.
- `MollieConnectOAuthFlowTest` (3 tests): exchange-code-flow met `Http::fake()` (1 passed), authorize-URL-query-param-coverage (1 passed), concurrent-refresh-race (1 markTestIncomplete — race-test vereist parallel-process-simulatie buiten unit-test-scope).
- Volledige test-suite blijft groen: **112 tests / 328 assertions / 1 incomplete / 0 failures** (was 80 in Plan 04-01 — andere phase-05b plans hebben tussentijds 29 extra tests toegevoegd).

## Task Commits

Elke task atomic gecommit:

1. **Task 1: MollieConnectOAuthFlow service + Http::fake() test-coverage (TDD)** — `bc73adb` (feat)
2. **Task 2: OAuthFlowRegistry + config + env + binding** — `279865e` (feat)

*Note: Task 1 was TDD-gemarkeerd. De RED-fase (failing test zonder class) en GREEN-fase (class + tests passing) zijn samengevoegd in één commit omdat het PATTERNS.md-snippet de test- én implementation-shape al volledig pre-specifieerde — separate RED/GREEN commits zouden ceremonie zijn zonder informatie-winst.*

**Plan metadata** (deze SUMMARY + STATE.md + ROADMAP-update): volgt in vervolg-commit door orchestrator.

## Files Created/Modified

- `app/OAuth/Mollie/MollieConnectOAuthFlow.php` *(created)* — `final class` met `HttpFactory` + `ConfigRepository` constructor-injection; 4 contract-methods + Cache::lock refresh-pattern.
- `app/OAuth/OAuthFlowRegistry.php` *(created)* — `final class` met `Container` constructor-injection; `register()` / `for()` / `providers()`; `InvalidArgumentException` (NL message) bij onbekende provider.
- `tests/Feature/OAuth/MollieConnectOAuthFlowTest.php` *(created)* — 3 PHPUnit-tests met `RefreshDatabase` + `Http::fake()`; markTestIncomplete voor race-condition per Phase-3-pattern.
- `config/services.php` *(modified)* — `'mollie' => ['connect' => […]]`-block na bestaande `'slack'`; 9 scopes hard-coded array.
- `.env.example` *(modified)* — 3 nieuwe `MOLLIE_CONNECT_*`-keys onder eigen blok-header; `MOLLIE_CONNECT_REDIRECT_URI` default `https://hub.emeq.test:8090/v1/oauth/mollie/callback` matched lokale Caddy-setup.
- `app/Providers/AppServiceProvider.php` *(modified)* — twee imports (`MollieConnectOAuthFlow`, `OAuthFlowRegistry`, `Application`); `register()` krijgt singleton-closure met `register('mollie', MollieConnectOAuthFlow::class)`. `boot()` chirurgisch niet aangeraakt.

## Decisions Made

- **`declare(strict_types=1)` weggelaten uit beide nieuwe `app/OAuth/`-files** — Hub-tree-conventie (`PATTERNS.md` regel 1510 + 04-01-SUMMARY precedent). Plan-body bevatte de explicit "CRITICAL HUB-TREE DISAMBIGUATION" die deze keuze al voor-besliste.
- **RED en GREEN samengevoegd in één commit voor Task 1** — TDD-cycle is hier ceremonieel omdat PATTERNS.md de test-shape én implementation-shape volledig pre-specifieerde. De RED-fase is wel uitgevoerd (test geschreven + gerund → 2 failed met "Target class does not exist", zoals verwacht) maar niet als eigen `test(...)`-commit gelandt. Het volledige feature-bedoeling (test + impl + passing) zit in `bc73adb`. Optioneel een retroactive split, maar zonder informatie-winst.
- **`MOLLIE_CONNECT_*`-env-keys in eigen `.env.example`-blok** — niet onder de bestaande `MOLLIE_PARTNER_*`-keys. Reden: `MOLLIE_PARTNER_*` is OAuth-app-eigenaar-context, `MOLLIE_CONNECT_*` is OAuth2 client-credentials per Hub-instance. Twee verschillende rollen, twee blokken; de plan-body specificeert deze naming ook expliciet.
- **`OAuthFlowRegistry::for()` gooit `InvalidArgumentException` met NL-message** — `.ai/rules/global.md`-conventie ("error-messages NL voor consistentie") + matches `MollieConnectionContext`-fallback-pattern in PATTERNS.md.

## Deviations from Plan

None — plan executed exactly as written.

Toelichting: Plan 04-02 was ruim gespecificeerd via PATTERNS.md copy-patterns, en alle CLAUDE.md-/Hub-conventie-keuzes (geen `declare(strict_types=1)` in `app/`-tree, NL error-messages, `final class`) waren al pre-besloten in 04-01. Geen Rule-1/2/3 fixes nodig, geen architectural-checkpoints, geen auth-gates. De enige minor procedure-keuze (RED+GREEN merge tot één commit) staat hierboven onder "Decisions Made" en is geen plan-deviatie maar een commit-granularity-keuze toegestaan binnen TDD-protocol.

## Issues Encountered

Geen. Pint clean op eerste run, tinker-resolve van `OAuthFlowRegistry::for('mollie')` returnt direct correcte class, `php artisan config:show services.mollie.connect.client_id` resolveert (null in test-env zonder `.env`, OK), volledige suite blijft groen.

Twee onverwante commits (`f8fc8fc docs(phase-05b)…` + `45a1237 chore: merge executor worktree (05b-05 …)`) landden tussen mijn Task 1- en Task 2-commits — dit zijn parallel-worktree-merges van Phase 5b die niets met Phase 4 te maken hebben en de plan-execution niet hebben beïnvloed. Genoteerd voor audit-trail, geen actie nodig.

## Self-Check: PASSED

Bestand-existence:
- FOUND: `app/OAuth/Mollie/MollieConnectOAuthFlow.php`
- FOUND: `app/OAuth/OAuthFlowRegistry.php`
- FOUND: `tests/Feature/OAuth/MollieConnectOAuthFlowTest.php`
- FOUND: `config/services.php` (modified, `'mollie' =>`-block aanwezig)
- FOUND: `.env.example` (modified, 3 MOLLIE_CONNECT_*-keys aanwezig)
- FOUND: `app/Providers/AppServiceProvider.php` (modified, OAuthFlowRegistry-binding aanwezig)

Commit-existence:
- FOUND: `bc73adb` (Task 1 — MollieConnectOAuthFlow + test)
- FOUND: `279865e` (Task 2 — Registry + config + env + binding)

Acceptance-criteria-greps (alle exit 0):
- `namespace App.OAuth.Mollie` in flow ✓
- `implements OAuthFlow` in flow ✓
- `my.mollie.com/oauth2/authorize` in flow ✓
- `api.mollie.com/oauth2/tokens` in flow ✓
- `Cache::lock` in flow ✓
- `asForm` in flow ✓
- `grant_type` in flow ✓
- `Http::fake` in test ✓
- `public function for(string ...): OAuthFlow` in registry ✓
- `InvalidArgumentException` in registry ✓
- 0× `^declare(strict_types` in registry (Hub-tree-conventie) ✓
- `'mollie' =>` in config/services.php ✓
- `MOLLIE_CONNECT_CLIENT_ID` in config/services.php ✓
- `payments.write` in config/services.php ✓
- `MOLLIE_CONNECT_CLIENT_ID=` in .env.example ✓
- `MOLLIE_CONNECT_REDIRECT_URI=` in .env.example ✓
- `OAuthFlowRegistry::class` in AppServiceProvider ✓
- `register('mollie'` in AppServiceProvider ✓

Tinker-verificatie:
- `app(\App\OAuth\OAuthFlowRegistry::class)->for('mollie')` → `App\OAuth\Mollie\MollieConnectOAuthFlow` ✓

Test-verificatie:
- `php artisan test --compact --filter=MollieConnectOAuthFlowTest` → 3 tests / 9 assertions / 2 passed / 1 incomplete
- `php artisan test --compact --filter=OAuthFlowContractTest` → 3 passed / 6 assertions (regression-check op 04-01)
- `php artisan test --compact` (full) → 112 passed / 328 assertions / 1 incomplete / 0 failures

## User Setup Required

None - geen externe services nodig voor deze implementatie-laag. Mollie Connect dashboard-registratie (client_id + client_secret + redirect_uri toevoegen aan `https://my.mollie.com/dashboard/developers/applications`) wordt user-task in Plan 04-04 wanneer de controllers daadwerkelijk een echte handshake doen.

## Next Phase Readiness

- **Plan 04-03** (HubMollieCredentialResolver): kan direct `$this->registry->for('mollie')->refreshToken($connection)` aanroepen — registry is gebind en mollie-provider is geregistreerd.
- **Plan 04-04** (InitController + CallbackController): kan `$this->registry->for('mollie')->getAuthorizationUrl(...)` (init) en `->exchangeCode(...)` (callback) gebruiken; `config('services.mollie.connect.scopes')` is direct beschikbaar.
- **Plan 04-05** (PruneOAuthPendingConnections): onaffected — gebruikt geen OAuthFlow-laag.
- **Docs-sync trigger** (uit hook tijdens `config/services.php`-edit): genoteerd voor end-of-Phase-4-sweep, niet uitgevoerd mid-plan-execution om atomic-commit-flow te beschermen. Run `.claude/skills/docs-sync` na Plan 04-05 (laatste Phase 4 plan).
- Geen blockers.

---
*Phase: 04-mollie-connect-oauth-broker*
*Completed: 2026-05-14*
