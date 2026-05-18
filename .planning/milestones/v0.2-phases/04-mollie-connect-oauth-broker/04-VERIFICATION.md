---
phase: 04-mollie-connect-oauth-broker
verified: 2026-05-18T19:06:00Z
status: passed
score: 5/5 must-haves verified
overrides_applied: 0
previous_status: missing
triggered_by: "Phase 15 verification-debt backfill (VERIF-01)"
---

# Phase 04: Mollie Connect OAuth-broker — Verification Report

**Phase Goal:** "Werkende OAuth-broker waarmee Accounts hun eigen Mollie-account aan een Consumer kunnen koppelen via Mollie Connect, met provider-agnostisch contract voor toekomstige providers." (`.planning/milestones/v0.2-ROADMAP.md` regel 66)
**Verified:** 2026-05-18T19:06:00Z
**Status:** passed
**Re-verification:** No — eerste formele verifier-run. Phase 4 had eerder een BLOCKING acceptance (8/8 stappen GREEN in `04-05-SUMMARY.md` §"8 Acceptance-step uitkomsten") maar geen `*-VERIFICATION.md`-artefact. Phase 15 wave-1 backfill (plan 15-01) trekt die debt dicht.

## Goal Achievement

### Observable Truths (ROADMAP Success Criteria — v0.2-ROADMAP.md regels 70-84)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| SC-1 | `POST /v1/oauth/mollie/init` → pre-creates pending Connection + redirect_url | VERIFIED | Route `routes/api.php:45-46` (`api.oauth.mollie.init`, `auth:sanctum` + `ability:mollie:write` + `feature.provider:mollie` group). `InitController` (`app/Http/Controllers/Api/V1/OAuth/InitController.php`) `__invoke` valideert `account_external_id`, scope't Account via `$consumer->accounts()->where(...)->firstOrFail()` (regel 30-32), creëert pending Connection met `Str::random(48)` state + `now()->addMinutes(30)` TTL (regel 34-42), returnt JSON `{connection_id, redirect_url}` (regel 47-50). 3 tests in `tests/Feature/Api/OAuth/InitTest.php`: `test_init_creates_pending_connection_and_returns_redirect_url` (assertJsonStructure + assertDatabaseHas pending), `_without_ability_returns_403`, `_with_cross_consumer_account_returns_404`. Live route-check: `php artisan route:list --path=oauth/mollie` toont `POST v1/oauth/mollie/init api.oauth.mollie.init` ✓ |
| SC-2 | Callback `GET /v1/oauth/mollie/callback` exchanges authorization-code voor `access_token` + `refresh_token` (encrypted) | VERIFIED | Route `routes/api.php:144-145` (`api.oauth.mollie.callback`, publiek — state is auth per D-07). `CallbackController` (`app/Http/Controllers/Api/V1/OAuth/CallbackController.php`) query't Connection met `provider='mollie' AND status='pending' AND oauth_state=? AND oauth_state_expires_at>now()` (regel 24-29) en delegeert naar `OAuthFlowRegistry::for('mollie')->exchangeCode($connection, $code)` (regel 38). `MollieConnectOAuthFlow::exchangeCode` (`app/OAuth/Mollie/MollieConnectOAuthFlow.php:31-52`) doet `Http::asForm()->post('https://api.mollie.com/oauth2/tokens', …)->throw()` met `grant_type=authorization_code` en schrijft `access_token`/`refresh_token`/`expires_at`/`scopes` + `status='active'`. Encrypted-at-rest geborgd door `Connection::$casts` (`app/Models/Connection.php:72-73`: `'access_token' => 'encrypted', 'refresh_token' => 'encrypted'`). `tests/Feature/Api/OAuth/CallbackTest.php::test_callback_exchanges_code_when_state_matches` asserteert `status=active`, `oauth_state=null`, `access_token` start met FakeOAuthFlow-prefix. `MollieConnectOAuthFlowTest::test_exchange_code_writes_encrypted_tokens` (regel 16-41) gebruikt `Http::fake()` op `api.mollie.com/oauth2/tokens` en asserteert real-write van `access_real_xyz`. |
| SC-3 | Connection met `expires_at` < 5 min triggert automatische refresh via lazy-refresh-pattern | VERIFIED | `HubMollieCredentialResolver::resolve()` (`app/Mollie/HubMollieCredentialResolver.php:17-29`) doet `if ($connection->expires_at && $connection->expires_at->lt(now()->addMinutes(5)))` → delegate naar `$this->registry->for('mollie')->refreshToken($connection)`. `MollieConnectOAuthFlow::refreshToken` (`app/OAuth/Mollie/MollieConnectOAuthFlow.php:54-78`) wikkelt in `Cache::lock("oauth:refresh:{$connection->id}", 30)->block(15, …)` (D-05 race-safe) + re-check `expires_at > now()+5min` na lock-acquire (D-06). `tests/Feature/Mollie/HubMollieCredentialResolverTest.php` bewijst beide paden: `test_resolve_returns_fresh_token_when_not_near_expiry` (fake-instance bound, `wasCalled('refreshToken')===0`) + `test_resolve_triggers_refresh_when_within_five_minute_window` (`expires_at=now()->addMinutes(2)`, `wasCalled('refreshToken')===1`, accessToken start met `access_test_fake_`). Pure lazy (D-04): geen scheduler-registratie van een refresh-cron. |
| SC-4 | `FakeOAuthFlow` bewijst dat pattern niet Mollie-specifiek is | VERIFIED | `app/OAuth/Testing/FakeOAuthFlow.php` (70 regels, `final class FakeOAuthFlow implements OAuthFlow`) zit in `app/OAuth/Testing/` namespace (NIET `tests/`) — runtime-bindable via container (D-12). Geen Mollie-referentie in de class (geen Mollie HTTP-call, geen `MollieConnectionContext`-koppeling) — opereert puur op het contract uit `app/OAuth/Contracts/OAuthFlow.php` (4 methods: `getAuthorizationUrl`, `exchangeCode`, `refreshToken`, `revoke`). Bewijs runtime-portability: `tests/Feature/OAuth/OAuthFlowContractTest.php` 3 tests — `test_fake_oauth_flow_satisfies_contract` (`assertInstanceOf(OAuthFlow::class)`), `_exchange_code_marks_connection_active`, `_revoke_sets_revoked_status`. Drop-in-bewijs in feature-tests: `InitTest::setUp()` + `CallbackTest::setUp()` doen `$this->app->bind(MollieConnectOAuthFlow::class, FakeOAuthFlow::class)` zodat Registry's `container->make()` de fake retourneert zonder controller-wijziging. `OAuthFlowRegistry::register(string $provider, string $implementation)` (`app/OAuth/OAuthFlowRegistry.php:21-24`) accepteert elke `class-string<OAuthFlow>` — provider-agnostisch contract bevestigd. |
| SC-5 | Tampered/expired `state` → 400 | VERIFIED | `CallbackController.php:24-36` query't `where('oauth_state', $validated['state'])->where('oauth_state_expires_at', '>', now())->first()`; null-pad returnt `response()->json(['error' => 'invalid_or_expired_state'], 400)`. Drie tests in `tests/Feature/Api/OAuth/CallbackTest.php` dekken alle drie SC-5-paden: `test_callback_with_invalid_state_returns_400` (tampered → 400 + `{error: invalid_or_expired_state}`), `test_callback_with_expired_state_returns_400` (factory-state `pending()->expired()` → 400), `test_second_callback_with_same_state_returns_400` (idempotent replay → eerste OK, tweede 400). Bevestigt D-03 (state-verify + idempotency) en CONTEXT.md `<specifics>` "CSRF-failure = 400, niet 401/403". |

**Score:** **5/5 truths verified**

## Notable deviations

**Geen `04-08-ACCEPTANCE.md` aanwezig.** Anders dan Phases 6 en 7 (`/gsd-mvp-phase`-flow met expliciete `<NN>-08-ACCEPTANCE.md` UAT-artefact) heeft Phase 4 alleen 5 SUMMARY-files + 04-CONTEXT.md als startbewijs. Phase 4 is in een eerder cycli-model gepland (volgde de v0.1 BLOCKING-acceptance-pattern uit Phase 5b, ingebed in het laatste plan).

**Fallback-files voor goal-backward verificatie:**
- `.planning/milestones/v0.2-phases/04-mollie-connect-oauth-broker/04-CONTEXT.md` (165 regels) — domain-boundary, 16 decisions (D-01..D-16), canonical refs, code-context
- `.planning/milestones/v0.2-phases/04-mollie-connect-oauth-broker/04-01-SUMMARY.md` — OAuthFlow-foundation + FakeOAuthFlow + migration (bewijs SC-4 partial)
- `.planning/milestones/v0.2-phases/04-mollie-connect-oauth-broker/04-02-SUMMARY.md` — `MollieConnectOAuthFlow` + `OAuthFlowRegistry` + config/.env (bewijs SC-2 partial, SC-3 refresh-laag)
- `.planning/milestones/v0.2-phases/04-mollie-connect-oauth-broker/04-03-SUMMARY.md` — `HubMollieCredentialResolver` + `MollieConnectionContext` + composer-VCS-install (bewijs SC-3)
- `.planning/milestones/v0.2-phases/04-mollie-connect-oauth-broker/04-04-SUMMARY.md` — `InitController` + `CallbackController` + routes + 7 feature-tests (bewijs SC-1 + SC-2 + SC-5)
- `.planning/milestones/v0.2-phases/04-mollie-connect-oauth-broker/04-05-SUMMARY.md` — `oauth:prune-pending` + BLOCKING phase-acceptance 8/8 + SC-traceability-tabel (SC-1..SC-5 elk gemapt aan test + commit)

**Rationale voor SUMMARY-fallback (toelaatbaar voor deze audit):**

1. **Plan 05's `## 8 Acceptance-step uitkomsten` (regel 89-101)** is functioneel equivalent aan een ACCEPTANCE-artefact: 8 stappen met expliciete commands, verwachte uitkomst, en stdout-bewijs (`SCHEMA_OK`, `2 routes returned`, `129 passed / 0 failed`, `Pint clean`).
2. **Plan 05's `## ROADMAP SC mapping`-tabel (regel 105-113)** mapt elke SC-1..SC-5 1-op-1 aan een test-naam + commit-hash. Dit is dezelfde traceability die een ACCEPTANCE-file biedt.
3. **Plan 05's `## CONTEXT decisions traceerbaarheid`-tabel (regel 117-135)** beweest dat alle 16 D-decisions uit CONTEXT.md gehonoreerd zijn.
4. **Code + tests draaien nog steeds groen** vandaag (2026-05-18): `php artisan test --compact --filter='OAuthFlowContractTest|MollieConnectOAuthFlowTest|HubMollieCredentialResolverTest|InitTest|CallbackTest|PruneOAuthPendingConnectionsTest'` → `18 passed / 45 assertions / 1 incomplete / 0 failures`. SUMMARY-claims zijn dus codebase-backed.
5. **Geen Hub-domeinmodel-leak naar SDK** (CLAUDE.md invariant) — `OAuthFlow`-contract en `MollieConnectionContext` zitten in `app/OAuth/` resp. `app/Mollie/`, niet in `packages/mollie-api/`. `HubMollieCredentialResolver` implementeert `Emeq\MollieApi\Contracts\MollieCredentialResolver` (SDK-contract) zonder Hub-modellen naar de SDK te lekken.

De SUMMARY-fallback is dus auditable voor deze backfill-audit; alle 5 SC's zijn herleidbaar tot code + tests met stabiele file:line-evidence.

## Evidence Summary

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/OAuth/Contracts/OAuthFlow.php` | Provider-agnostisch contract met 4 methods | VERIFIED | 33 regels; `interface OAuthFlow` met `getAuthorizationUrl(Account, list<string>, string): string`, `exchangeCode(Connection, string): Connection`, `refreshToken(Connection): Connection`, `revoke(Connection): void`. Geen Mollie-referenties. D-13 honored. |
| `app/OAuth/Mollie/MollieConnectOAuthFlow.php` | Productie-implementatie tegen Mollie OAuth2 | VERIFIED | 92 regels; `final class … implements OAuthFlow`; constructor injecteert `HttpFactory + ConfigRepository` (testable). `getAuthorizationUrl` bouwt `my.mollie.com/oauth2/authorize?…`-URL (regel 21-29); `exchangeCode` doet `Http::asForm()->post('https://api.mollie.com/oauth2/tokens', …)->throw()` (regel 33-39) + persist met `oauth_state=null`; `refreshToken` wikkelt in `Cache::lock(...)->block(15, …)` met re-check (regel 56-78); `revoke` doet `Http::withBasicAuth(...)->delete(...)` + zet `status='revoked'`. D-04 + D-05 + D-06 + D-15 honored. |
| `app/OAuth/OAuthFlowRegistry.php` | Provider-keyed lookup, container-resolved | VERIFIED | 46 regels; `final class` met `Container` injection; `register(string, class-string<OAuthFlow>)` + `for(string): OAuthFlow` + `providers(): list<string>`. `for()` throws `InvalidArgumentException` (NL message) bij onbekende provider, throws `ProviderDisabledException` als Pennant-feature `provider-{name}-enabled` inactive (Phase 8 toevoeging — geen Phase 4-regressie, breidt uit, niet vervangt). D-14 honored. |
| `app/OAuth/Testing/FakeOAuthFlow.php` | Runtime test-fixture die SC-4 bewijst | VERIFIED | 70 regels; `final class … implements OAuthFlow` in `app/OAuth/Testing/` (NIET `tests/`); deterministic fake-tokens (`access_test_fake_{nonce}`, `refresh_test_fake_{nonce}`); `wasCalled(string): int` voor test-assertions; geen Mollie-referentie. D-12 honored. |
| `app/Mollie/HubMollieCredentialResolver.php` | Lazy-refresh + SDK-binding (D-16) | VERIFIED | 31 regels; `final class … implements MollieCredentialResolver` (SDK-contract); constructor injecteert `MollieConnectionContext + OAuthFlowRegistry`; `resolve()` doet 5-min-window-check + delegate naar `registry->for('mollie')->refreshToken()`; returnt `MollieOAuthCredentials(accessToken, expiresAt)` (SDK-DTO, geen Hub-model lekkage). |
| `app/Mollie/MollieConnectionContext.php` | Per-request scoped current-Connection holder | VERIFIED | 24 regels (file-size 721B); `set(Connection)` + `current(): Connection` + `has(): bool`; `current()` throws `RuntimeException` met NL-message "geen current Connection" (T-04-03-02 mitigation). Scoped-bound in `AppServiceProvider::register()` regel 27. |
| `app/Http/Controllers/Api/V1/OAuth/InitController.php` | Sanctum-protected init, JSON-return | VERIFIED | 52 regels; single-action `__invoke` + `OAuthFlowRegistry`-injection; `firstOrFail()` Consumer-scoped Account-lookup → 404 (geen 403/info-disclosure); `Str::random(48)` state + 30-min TTL; returnt JSON-array `{connection_id, redirect_url}` (D-08, geen HTTP-redirect). `#[Group(name: 'OAuth Connect', …)]` Scramble-attribute aanwezig. |
| `app/Http/Controllers/Api/V1/OAuth/CallbackController.php` | Publieke callback met state-auth | VERIFIED | 46 regels; single-action `__invoke` + `OAuthFlowRegistry`-injection; query't pending Connection met state + non-expired check; null → 400 `{error: invalid_or_expired_state}`; idempotent (tweede call vindt geen pending-row, → 400). D-03 + D-07 + D-08 honored. |
| `app/Console/Commands/PruneOAuthPendingConnections.php` | Operationele cleanup-command (D-09) | VERIFIED | 35 regels; signature `oauth:prune-pending {--dry-run}`; NL-description; `handle()` query't `where('status', 'pending')->where('oauth_state_expires_at', '<', now())`; dry-run-pad toont count + returnt SUCCESS; regular-pad doet `delete()` + toont count. Geen scheduler-registratie (D-04 + D-09 honored: handmatige/deploy-hook trigger). |
| `connections.oauth_state` + `oauth_state_expires_at` columns + indexes | Schema-uitbreiding voor OAuth-state-lifecycle | VERIFIED (geconsolideerd) | Originele Plan-04-01-aanpak was forward-only `ALTER TABLE`-migration (`2026_05_15_000001_add_oauth_state_to_connections_table.php`). In de loop van v0.2 zijn de kolommen geconsolideerd in de baseline-migration `database/migrations/2026_05_14_000003_create_connections_table.php` regels 23-24 (kolommen) + regels 40-41 (index `oauth_state` + composite `(status, oauth_state_expires_at)`). Forward-only-invariant niet geschonden vóór release; `Schema::hasColumns('connections', ['oauth_state', 'oauth_state_expires_at'])` returnt `true` (bewezen door Plan-05 acceptance-step 2 `SCHEMA_OK`). |
| `ConnectionFactory::pending()` / `active()` / `expired()` states | OAuth-lifecycle test-helpers | VERIFIED | `database/factories/ConnectionFactory.php` regels 58-86: `pending()` zet `status=pending` + `oauth_state=Str::random(48)` + `oauth_state_expires_at=now()->addMinutes(30)`; `active()` zet `status=active` + `oauth_state=null`; `expired()` zet `oauth_state_expires_at=now()->subMinutes(1)`. Direct gebruikt door `CallbackTest` (alle 4 tests) en `InitTest` (impliciet via assertDatabaseHas). |
| `config/services.php` mollie.connect block | OAuth-app-credentials + 9 hard-coded scopes | VERIFIED | Regels 38-56: `mollie.connect.{client_id,client_secret,redirect_uri,scopes}`; scopes-array bevat alle 9 D-10-scopes (`payments.read/write`, `customers.read/write`, `subscriptions.read/write`, `mandates.read`, `organizations.read`, `onboarding.read`). |
| `AppServiceProvider` bindings | Singleton-registry + scoped context + resolver-bind | VERIFIED | `app/Providers/AppServiceProvider.php` regel 6-14 imports; regel 27 `scoped(MollieConnectionContext::class)`; regel 29-31 `singleton(OAuthFlowRegistry::class, …)` met `register('mollie', MollieConnectOAuthFlow::class)`; regel 36 `bind(MollieCredentialResolver::class, HubMollieCredentialResolver::class)`. D-14 + D-16 honored. |
| Tests `tests/Feature/OAuth/OAuthFlowContractTest.php` | SC-4 bewijs (3 tests) | VERIFIED | 46 regels; 3 tests / 6 assertions; alle groen vandaag (regression-check). |
| Tests `tests/Feature/OAuth/MollieConnectOAuthFlowTest.php` | SC-2 exchange-flow + URL-builder (3 tests, 1 incomplete) | VERIFIED | 68 regels; `test_exchange_code_writes_encrypted_tokens` (Http::fake → assert `access_real_xyz` + scopes); `test_refresh_token_is_locked_per_connection` (markTestIncomplete — parallel-process-race buiten unit-scope, gedocumenteerd in 04-02-SUMMARY); `test_get_authorization_url_contains_required_query_params`. |
| Tests `tests/Feature/Mollie/HubMollieCredentialResolverTest.php` | SC-3 lazy-refresh bewijs (3 tests) | VERIFIED | 63 regels; 3 tests / 7 assertions; dekt fresh-pad (no refresh), refresh-pad (within 5min), error-pad (RuntimeException). |
| Tests `tests/Feature/Api/OAuth/InitTest.php` | SC-1 init-flow (3 tests) | VERIFIED | 75 regels; happy-path + 403-ability-bypass + 404-cross-Consumer (firstOrFail). |
| Tests `tests/Feature/Api/OAuth/CallbackTest.php` | SC-2 + SC-5 callback-flow (4 tests) | VERIFIED | 63 regels; happy-path + tampered-state + expired-state + replay-state alle drie 400. |
| Tests `tests/Feature/Console/PruneOAuthPendingConnectionsTest.php` | D-09 cleanup bewijs (2 tests) | VERIFIED | 32 regels; 2 tests / 7 assertions; prune-expired + dry-run-no-delete. |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|----|--------|---------|
| `routes/api.php` regel 44-47 | `InitController` | Route::post + sanctum + ability:mollie:write + feature.provider:mollie | WIRED | Live route-check: `POST v1/oauth/mollie/init api.oauth.mollie.init` met middleware-stack zichtbaar via `route:list` |
| `routes/api.php` regel 144-145 | `CallbackController` | Route::get publieke route | WIRED | Live route-check: `GET v1/oauth/mollie/callback api.oauth.mollie.callback`; geen sanctum (D-07: state = auth) |
| `bootstrap/app.php` regel 38-39 | Sanctum-aliases `ability`/`abilities` | middleware-alias-tabel | WIRED | `CheckAbilities` + `CheckForAnyAbility` imports + alias-entries — vereist voor `Route::middleware('ability:mollie:write')` op `/init` (Plan 04-04 deviation-fix) |
| `AppServiceProvider::register()` regel 29-32 | `OAuthFlowRegistry` + Mollie-provider | singleton + register('mollie', MollieConnectOAuthFlow::class) | WIRED | grep `register\('mollie'` in AppServiceProvider returnt 1 hit. Tinker (Plan-05 acceptance-step 4b) bewijst `OAuthFlowRegistry->for('mollie')` resolveert naar `MollieConnectOAuthFlow` |
| `AppServiceProvider::register()` regel 27 | `MollieConnectionContext` | scoped() per-request singleton | WIRED | Voorkomt cross-request token-lekkage (T-04-03-01 mitigation). HubMollieCredentialResolverTest::test_resolve_throws_when_context_has_no_connection bewijst lege context throws |
| `AppServiceProvider::register()` regel 36 | `MollieCredentialResolver` → `HubMollieCredentialResolver` | bind() SDK-contract → Hub-impl | WIRED | Plan-05 acceptance-step 4a bewijst tinker `app(MollieCredentialResolver::class)` → `App\Mollie\HubMollieCredentialResolver`. D-16 honored. |
| `HubMollieCredentialResolver::resolve()` | `OAuthFlowRegistry::for('mollie')->refreshToken()` | lazy-refresh-delegate | WIRED | Regel 21-23 doet expires_at-check + delegate. Bewezen door `test_resolve_triggers_refresh_when_within_five_minute_window` (FakeOAuthFlow `wasCalled('refreshToken')===1`) |
| `MollieConnectOAuthFlow::refreshToken()` | `Cache::lock("oauth:refresh:{$connection->id}", 30)->block(15, …)` | Redis-lock per connection_id | WIRED | Regel 56 — D-05 race-safe pattern; re-check op regel 59 (D-06) voorkomt dubbele refresh-roundtrips |
| `MollieConnectOAuthFlow::exchangeCode` | Mollie token-endpoint | `Http::asForm()->post('https://api.mollie.com/oauth2/tokens')->throw()` | WIRED | Regel 33-39; `grant_type=authorization_code`; D-15 (geen Saloon) honored |
| `CallbackController` | `OAuthFlowRegistry::for('mollie')->exchangeCode($connection, $code)` | Registry-resolved | WIRED | Regel 38 — geen Mollie-specifieke type-hint, provider-agnostisch dispatch |
| `CallbackController` | State-verify + idempotency | DB-query `oauth_state` + `oauth_state_expires_at>now()` | WIRED | Regel 24-29 + 31-36 — D-03 honored; idempotent (tweede call vindt geen pending-row) |
| `Connection`-model | Encrypted-at-rest casts | `protected $casts = ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', …]` | WIRED | `app/Models/Connection.php` regel 72-73 + 74-75 (`client_key`/`subscription_key`); SC-2 leunt op deze Phase 3-invariant (regression-checked via Plan-05 full test-suite green) |
| `oauth:prune-pending` command | NIET in `routes/console.php` | D-09 manual/deploy-hook trigger | WIRED (by absence) | Bewust GEEN scheduler-registratie — past bij D-04 "geen cron"-filosofie. PruneOAuthPendingConnectionsTest bewijst commando functioneel zonder scheduler. |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `InitController` JSON response | `connection_id` + `redirect_url` | `Connection::create(...)` (pending-row) + `Registry::for('mollie')->getAuthorizationUrl(...)` | Yes — `InitTest::test_init_creates_pending_connection_and_returns_redirect_url` asserteert `assertJsonStructure(['connection_id', 'redirect_url'])` + `assertDatabaseHas('connections', status=pending)`. Live `redirect_url` = `https://my.mollie.com/oauth2/authorize?client_id=…&state=…&scope=…&response_type=code` (productie) of fake-URL (tests). | FLOWING |
| `CallbackController` JSON response | `connection_id` + `status='active'` | `OAuthFlowRegistry::for('mollie')->exchangeCode($connection, $code)` schrijft DB-row | Yes — `CallbackTest::test_callback_exchanges_code_when_state_matches` asserteert `assertJson(['status' => 'active'])` + `assertSame('active', $connection->status)` + `assertStringStartsWith('access_test_fake_', $connection->access_token)` | FLOWING |
| `HubMollieCredentialResolver::resolve()` return | `MollieOAuthCredentials($accessToken, $expiresAt)` (SDK-DTO) | `$connection->access_token` (eventueel post-refresh) | Yes — `HubMollieCredentialResolverTest` (3 tests / 7 assertions): fresh-pad returnt `'access_test_freshxyz'` zonder refresh, refresh-pad returnt nieuwe `access_test_fake_{nonce}` met `wasCalled('refreshToken')===1`. | FLOWING |
| `MollieConnectOAuthFlow::refreshToken()` write | DB-row `access_token`/`refresh_token`/`expires_at` | HTTP-response van `api.mollie.com/oauth2/tokens` met `grant_type=refresh_token` | Yes (via Http::fake() in tests) — `MollieConnectOAuthFlowTest::test_exchange_code_writes_encrypted_tokens` bewijst write-pad; refresh-pad volgt identieke shape (DRY via shared `Http::asForm()->post(...)` call). | FLOWING |
| `oauth:prune-pending` stdout | `"Verwijderd: {N} expired pending Connection(s)."` / `"Dry-run: {N} pending …"` | `Connection::where('status', 'pending')->where('oauth_state_expires_at', '<', now())->{count|delete}()` | Yes — `PruneOAuthPendingConnectionsTest` (2 tests / 7 assertions) asserteert expired-row weg + fresh blijft + dry-run-output bevat 'Dry-run'. | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| 2 OAuth-routes geregistreerd | `php artisan route:list --path=oauth/mollie` | `POST v1/oauth/mollie/init api.oauth.mollie.init` + `GET v1/oauth/mollie/callback api.oauth.mollie.callback` (2 routes shown) | PASS |
| `oauth:prune-pending` artisan-command beschikbaar | `php artisan list \| grep oauth:prune-pending` | `oauth:prune-pending  Ruim expired pending OAuth-Connections op (status=pending AND oauth_state_expires_at < now)` | PASS |
| Phase-4 test-suite groen vandaag (2026-05-18) | `php artisan test --compact --filter='OAuthFlowContractTest\|MollieConnectOAuthFlowTest\|HubMollieCredentialResolverTest\|InitTest\|CallbackTest\|PruneOAuthPendingConnectionsTest'` | `{"tool":"phpunit","result":"passed","tests":18,"passed":18,"assertions":45,"duration_ms":829,"incomplete":1}` (1 incomplete = pre-existing parallel-process-race markTestIncomplete in `MollieConnectOAuthFlowTest::test_refresh_token_is_locked_per_connection`) | PASS |

### Probe Execution

| Probe | Command | Result | Status |
|-------|---------|--------|--------|
| (n/a) | n/a — Phase 4 is een Laravel HTTP/OAuth-broker zonder `scripts/*/tests/probe-*.sh`-artifacts; PLAN/SUMMARY/CONTEXT.md declareren geen probes | n/a | SKIPPED (no probes declared) |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| MOLL-02 | 04-01, 04-02, 04-03, 04-05 (frontmatter `requirements-completed`) | Mollie Connect OAuth-broker — token-exchange + refresh-flow + encrypted-at-rest | SATISFIED | SC-1..SC-5 alle VERIFIED bovenstaand. v0.2-REQUIREMENTS.md regel 16-17 markt `- [x] MOLL-02` met "Validated in Phase 4 (2026-05-14). … 5/5 plans, 129/129 tests, BLOCKING acceptance 8/8." Traceability rij 69 = `MOLL-02 \| Phase 4 \| ✅ Validated`. |
| HUB-02 | 04-01, 04-05 (frontmatter `requirements-completed`) | Provider-agnostisch `OAuthFlow`-contract met `MollieConnectOAuthFlow` als eerste implementatie | SATISFIED | SC-4 VERIFIED bovenstaand (`OAuthFlowContractTest` + `FakeOAuthFlow` bewijst pattern-portability). `OAuthFlowRegistry::register(string, class-string<OAuthFlow>)` accepteert elke implementatie — v0.3 Snelstart-OAuth / Exact-OAuth / Ibanity-OAuth kunnen direct registreren zonder Mollie-coupling. v0.2-REQUIREMENTS.md regel 30-31 markt `- [x] HUB-02`. Traceability rij 73. |

**Orphaned requirements:** None. v0.2-REQUIREMENTS.md regel 16-17 + 30-31 mapt MOLL-02 + HUB-02 → Phase 4; beide appearen in plan-frontmatter `requirements-completed`-arrays op 04-01 + 04-02 + 04-03 + 04-05. Plan 04-04 frontmatter heeft geen `requirements-completed` veld (decisions-array + tasks-bewijs is voldoende; SC-coverage gebeurt via 04-05 BLOCKING-acceptance-aggregatie).

### Anti-Patterns Scan

| File | Pattern | Severity | Impact |
|------|---------|----------|--------|
| (none) | Geen debt-markers in Phase-4-files (`grep -rn 'TBD\|FIXME\|XXX' app/OAuth/ app/Mollie/ app/Http/Controllers/Api/V1/OAuth/ app/Console/Commands/PruneOAuthPendingConnections.php`) | INFO | Geen blockers; geen warnings. Plan 04-02-SUMMARY's `markTestIncomplete` is een gedocumenteerde Phase-3-pattern (NIET dezelfde categorie als TODO/FIXME) — bewust uitgesteld race-condition-test buiten unit-scope. |

### Verifier confirmation passes (Inversion + Confirmation Bias Counter)

**Inversion-pass (3 mogelijke faalpaden):**

1. *Risico:* "FakeOAuthFlow draagt subtiel een Mollie-coupling die SC-4 zou ondermijnen." → Check: `grep -rn 'mollie\|Mollie' app/OAuth/Testing/FakeOAuthFlow.php` → **0 hits**. `grep -rn 'mollie\|Mollie' app/OAuth/Contracts/OAuthFlow.php` → 0 hits behalve `Connection` (Hub-model, NIET Mollie). SC-4 staat.
2. *Risico:* "Refresh wordt nooit getriggerd in productie omdat geen consumer-call de resolver raakt." → Check: Phase 5a's `AbstractMolliePassThroughController::buildClient()` (via grep) gebruikt `app(MollieCredentialResolver::class)` (= `HubMollieCredentialResolver`) — elke `/v1/mollie/*`-call doorloopt de lazy-refresh. SC-3 productie-bewezen.
3. *Risico:* "Tampered state-test gebruikt incomplete state-mutation en zou false-positive zijn." → Check: `CallbackTest::test_callback_with_invalid_state_returns_400` doet GET met `state=tampered_state` zonder dat een Connection met die state bestaat — query-resultaat null → 400. Geen state-mutation; pad correct gevolgd.

**Confirmation Bias Counter-pass (disconfirmation):**

1. *Partial truth?* SC-3 wordt VERIFIED met FakeOAuthFlow's `wasCalled('refreshToken')`-counter, niet met een echte Mollie-roundtrip. `MollieConnectOAuthFlow::refreshToken` zelf (regel 63-74) is unit-getest via `test_exchange_code_writes_encrypted_tokens` (zelfde Http-shape) — niet via een dedicated `refreshToken`-test. **Severity:** INFO — refresh- en exchange-code-paden delen `Http::asForm()->post('https://api.mollie.com/oauth2/tokens', …)`-call met identieke shape (regel 63-68 vs 33-39); de mock-test bewijst de write-laag, de lock-laag is `markTestIncomplete` (race-test gedeferred — gedocumenteerd in 04-02-SUMMARY). Geen blocker voor SC-3 omdat de delegate-edge (resolver → Registry → refreshToken) Wired-bewezen is.
2. *Misleidende test?* `OAuthFlowContractTest::test_fake_oauth_flow_satisfies_contract` doet `assertInstanceOf(OAuthFlow::class, new FakeOAuthFlow)` — bewijst alleen interface-implementatie, NIET runtime-portability. Maar `InitTest::setUp()` + `CallbackTest::setUp()` bewijzen drop-in-binding via container (`bind(MollieConnectOAuthFlow::class, FakeOAuthFlow::class)`) — die `setUp()`-statements zijn de feitelijke runtime-portability-bewijs. SC-4 staat.
3. *Onbeschermde error-pad?* `MollieConnectOAuthFlow::exchangeCode` heeft `->throw()` — bij een non-200 van Mollie wordt een `RequestException` gegooid. Geen unit-test voor de `->throw()`-edge (e.g. Mollie returnt 401 = expired authorization-code). **Severity:** INFO — Phase 5a's `MollieExceptionMapper` (rij 22 v0.2-REQUIREMENTS.md MOLL-03) wraps Mollie-exceptions naar Hub-tree; daar zit de error-mapping coverage. Phase 4 SC's vragen niet om error-mapping op de OAuth-broker-laag.

## Deferred / Open Items

**Geen blocker-deferrals.** Alle 5 ROADMAP SC's zijn VERIFIED door codebase-evidence (file:line + tests). De volgende items zijn bewust gedeferred buiten Phase 4-scope (per CONTEXT.md `<deferred>` + 04-05-SUMMARY's "ROADMAP follow-up"):

| # | Item | Addressed In | Rationale |
|---|------|--------------|-----------|
| 1 | Parallel-process refresh-race test (`MollieConnectOAuthFlowTest::test_refresh_token_is_locked_per_connection` markTestIncomplete) | Niet ge-adresseerd in v0.2 | Race vereist parallel-process simulatie buiten unit-test-scope; `Cache::lock` pattern is industry-standard en code-resident bewijs voldoende. Geen impact op SC-3. |
| 2 | ROADMAP SC-1 wording-drift (`GET /v1/oauth/mollie/authorize` vs werkelijk `POST /v1/oauth/mollie/init`) gesignaleerd in 04-05-SUMMARY-§"ROADMAP follow-up" | Niet meer relevant — `.planning/milestones/v0.2-ROADMAP.md` regel 72 toont nu `POST /v1/oauth/mollie/init` (drift dichtgetrokken in latere doc-sync) | Pre-existing SUMMARY-claim; verifier-check bewijst dat ROADMAP-tekst nu matched D-08 + actual implementation. Geen actie nodig. |
| 3 | Snelstart-OAuth / Exact-OAuth / Ibanity-OAuth als tweede + derde OAuthFlow-implementaties | v0.3+ (zie ROADMAP backlog + carry-forward in v0.2-REQUIREMENTS.md regel 100) | SC-4 (provider-agnostisme) bewezen via FakeOAuthFlow; live tweede implementatie is v0.3-scope (`PROV-MONEYBIRD`/`PROV-EXACT`/`PROV-IBANITY` backlog). |
| 4 | Consumer-facing `DELETE /v1/connections/{id}` revoke-flow | Phase 5a + 5b (HUB-03 + HUB-05) | Phase 4 levert alleen `oauth:revoke {connection_id}` artisan-command pattern (contract-aanwezig op `OAuthFlow::revoke()`); HTTP-endpoint zit in latere passthrough-fases. CONTEXT.md `<deferred>` regel 156. |
| 5 | Filament admin-UI voor pending-Connections + handmatig prunen | Phase 9 (HUB-04) | Bevestigd shipped in 09 + 10. Niet Phase-4-scope. |

---

*Phase verified: 2026-05-18T19:06:00Z*
*Verifier: gsd-verifier subagent (Phase 15 verification-debt backfill, plan 15-01 wave-1)*
*Trigger: VERIF-01 — backfill `04-VERIFICATION.md` voor Phase 4 die 5/5 plans + BLOCKING-acceptance 8/8 had maar geen formele verifier-audit*
