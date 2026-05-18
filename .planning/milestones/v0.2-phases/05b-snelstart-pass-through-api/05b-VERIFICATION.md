---
phase: 05b-snelstart-pass-through-api
verified: 2026-05-17T10:30:00Z
status: passed
score: 8/8 must-haves verified
overrides_applied: 0
previous_status: missing
triggered_by: "v0.2-MILESTONE-AUDIT.md verification-debt closure (2026-05-17)"
---

# Phase 05b: Snelstart-pass-through API Verification Report

**Phase Goal:** "Een werkende end-to-end Snelstart-pass-through: Consumer doet HTTP-call naar `/v1/snelstart/{path}` met Bearer-PAT + Account-ID, Hub resolved Connection naar Snelstart-credentials (`client_key` + `subscription_key` + `subscription_id`), SDK doet OData/REST-call namens die Account, response stroomt terug." (`05b-CONTEXT.md` §`<domain>` + ROADMAP.md regel 167)
**Verified:** 2026-05-17T10:30:00Z
**Status:** passed
**Re-verification:** No — eerste formele verifier-run (v0.2-MILESTONE-AUDIT.md flagged "satisfied (verification-debt)" omdat ROADMAP 5/5 plans complete claim niet door een VERIFICATION.md gedekt was).

## Goal Achievement

### Observable Truths (ROADMAP Success Criteria HUB-05)

| #    | Truth (uit ROADMAP regel 186-193 + CONTEXT.md)                                                                                                                   | Status     | Evidence                                                                                                                                                                                                                                                                                                                                                          |
| ---- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| SC-1 | Consumer met PAT (`snelstart:write` / `consumer:manage-accounts` / `*`) kan `POST /v1/accounts` doen en krijgt Account-resource terug                              | VERIFIED   | Route `routes/api.php:33` (`api.accounts.store`). `AccountController::store` + `StoreAccountRequest`. `tests/Feature/Api/V1/StoreAccountTest.php` (7 tests) — happy-path returnt `id/external_id/display_name/created_at`; `consumer_id` afgeleid uit Sanctum-context (T-05b-15 closed). UAT-test 2 live-verified met 201 + 409-conflict.                            |
| SC-2 | `POST /v1/connections` accepteert nested `credentials.{client_key,subscription_key,subscription_id}` en retourneert alleen fingerprint, géén raw credentials       | VERIFIED   | Route `routes/api.php:35`. `ConnectionController::store` + `StoreConnectionRequest`. `ConnectionResource` (whitelist `id/account_id/provider/status/fingerprint/revoked_at/created_at`). `tests/Feature/Api/V1/StoreConnectionTest.php` (8 tests) — `test_creates_snelstart_connection_with_encrypted_credentials_and_returns_fingerprint_only` + `test_response_never_contains_raw_credentials`. UAT-test 3 live: raw DB-rij encrypted, fingerprint `f8540d4706a4` returned; raw `client_key`/`subscription_key` afwezig in response. |
| SC-3 | `GET /v1/snelstart/echo/ping` met `X-Account-Id` resolved Connection en bewijst resolver-binding                                                                   | VERIFIED   | Route `routes/api.php:44` (`Route::any('/snelstart/{path}')` met `feature.provider:snelstart` + `resolve.snelstart.account`). `ResolveSnelstartAccount.php:66-74` doet `app()->instance(SnelstartCredentialResolver::class, new HubSnelstartCredentialResolver($connection))` + `app()->forgetInstance(Snelstart::class)`. `tests/Feature/Api/V1/Snelstart/PassThroughEchoPingTest.php` (4 tests) — `test_credential_resolver_was_bound_to_the_right_connections_credentials_during_call`. UAT-test 4 live: resolver-binding verified via audit `connection_id=1`. |
| SC-4 | `GET /v1/snelstart/relaties?$top=5` levert OData-pad doorzetting (query-string verbatim naar SDK)                                                                  | VERIFIED   | `PassThroughController.php:67-69` + `tests/Feature/Api/V1/Snelstart/PassThroughOdataRelatiesTest.php` (4 tests) — bewijst `$top=5` arriveert op SDK's `PendingRequest->query()`. UAT-test 5 live: audit-rij `query_keys="$top"` bevestigt SDK heeft query gezien.                                                                                                          |
| SC-5 | Cross-Consumer (A's PAT → B's Account/Connection) → 404 op alle endpoints (geen 403, geen info-disclosure)                                                         | VERIFIED   | Middleware `ResolveSnelstartAccount.php:41-51` (`->where('consumer_id', $consumerId)` op Account-lookup, null → 404 `account_not_found`). `ConnectionController.php:73-75,90-92` doet zelfde scope via `findOwnedConnection`. `tests/Feature/Api/V1/Snelstart/PassThroughResolutionTest.php::test_other_consumers_account_id_returns_404_not_403` + `ShowConnectionTest::test_other_consumers_connection_returns_404_with_connection_not_found` + `DestroyConnectionTest::test_other_consumers_connection_returns_404_on_delete`. UAT-test 6 live-verified. |
| SC-6 | `X-Account-Id`-resolution-paden: ontbrekend → 400 `missing_account_header`; onbekend → 404 `account_not_found`; geen actieve Connection → 404 `connection_not_found` | VERIFIED   | `ResolveSnelstartAccount.php:32-37` (ontbrekend → 400), `:46-51` (account onbekend → 404), `:59-64` (geen actieve Connection → 404). `PassThroughResolutionTest` (7 tests) dekt alle drie paden expliciet. UAT-test 7a/7b/7c live: 400 / 404 / 404 met juiste error-codes.                                                                                              |
| SC-7 | Elke pass-through-call landt één rij in audit-tabel met juiste FKs en fingerprint-only; nooit raw `client_key`/`subscription_key` in audit-log of response          | VERIFIED   | Audit-deviatie van ROADMAP: nieuwe `pass_through_calls`-tabel (CONTEXT.md §Audit-log, ADR `.docs/decisions/pass-through-calls-table.md`). Migration `2026_05_15_000001_create_pass_through_calls_table.php` + `2026_05_15_000002_add_query_keys_to_pass_through_calls_table.php` (CR-02 PII-safe). `PassThroughController.php:117-130` schrijft `consumer_id/account_id/connection_id/provider/method/path/status/duration_ms/query_keys/request_fingerprint/response_size_bytes/upstream_error/created_at` synchroon. `tests/Feature/Api/V1/Snelstart/PassThroughAuditNoSecretsTest.php` (4 tests) — `test_audit_row_never_contains_raw_snelstart_credentials` + `test_empty_post_body_yields_null_request_fingerprint` (CR-03). UAT-test 8 live: grep over hele tabel geen raw secrets. |
| SC-8 | `/docs/api` toont alle Phase 5b routes (`/v1/accounts`, `/v1/connections`, `/v1/connections/{id}`, `/v1/snelstart/{path}`) met "Try it out"-knop                  | VERIFIED   | `tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php` (11 tests). Vier Phase 5b-specifieke testen: `test_openapi_spec_contains_post_v1_accounts_route` (regel 25), `test_openapi_spec_contains_post_v1_connections_route` (regel 33), `test_openapi_spec_contains_show_and_delete_v1_connections_id_routes` (regel 41), `test_openapi_spec_contains_snelstart_passthrough_catchall` (regel 50). UAT-test 9 live: 27 paths in `/docs/api.json`; catch-all rendert GET-method. |

**Score:** **8/8 truths verified**

## Critical Issues Closure (REVIEW)

`05b-REVIEW.md` flagde drie BLOCKERS die vóór Phase-acceptance moesten landen. Alle drie zijn nu code-resident:

| CR    | Issue                                                          | Fix Evidence                                                                                                                                                       | Test Evidence                                                                                                |
| ----- | -------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| CR-01 | 415-guard voor niet-JSON Content-Type op POST/PATCH            | `PassThroughController.php:52-56` (`if (! str_starts_with($contentType, 'application/json'))` → 415 `unsupported_content_type`)                                       | `PassThroughEchoPingTest` regel 112-128                                                                       |
| CR-02 | PII-safe audit — `path` zonder query-string, `query_keys` only | `PassThroughController.php:119-120` (`'path' => $endpoint` + `'query_keys' => $query !== [] ? implode(',', array_keys($query)) : null`) + nieuwe migration `2026_05_15_000002` | `PassThroughOdataRelatiesTest::test_complex_odata_query_stores_only_query_keys_no_values_in_audit` (regel 72-100) |
| CR-03 | NULL `request_fingerprint` voor lege/missing bodies            | `PassThroughController.php:123-125` (`(is_array($body) && $body !== []) ? substr(hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)), 0, 12) : null`)            | `PassThroughAuditNoSecretsTest::test_empty_post_body_yields_null_request_fingerprint` (regel 119-139)         |

## Required Artifacts

| Artifact                                                                                          | Expected                                                                                                                                                       | Status   | Details                                                                                                                                                                                        |
| ------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php`                                  | Catch-all controller + ability-guards + audit-write + 415/CR-02/CR-03 fixes                                                                                    | VERIFIED | `Route::any` dispatcht `__invoke` via `routes/api.php:44`; 415-guard op regel 52; audit-write regel 117-130 (atomic vóór response)                                                              |
| `app/Http/Middleware/ResolveSnelstartAccount.php`                                                  | Alias `resolve.snelstart.account` — leest header, scoped Account+Connection-lookup, bindt resolver, forget singleton                                           | VERIFIED | 5 stappen geverifieerd in code (`:32-37`, `:41-51`, `:53-64`, `:66-74`, `:76-77`)                                                                                                              |
| `app/Services/Snelstart/HubSnelstartCredentialResolver.php`                                       | `final readonly class` + `resolve()` returnt `SnelstartCredentials`-DTO                                                                                        | VERIFIED | Geen logging, geen `__toString` (T-05b-05 SECURED). `HubSnelstartCredentialResolverTest` (4 tests) bewijst decryption-roundtrip                                                                  |
| `app/Support/Snelstart/HeaderForwarder.php`                                                        | Whitelist (Accept, Content-Type, If-Match, If-None-Match)                                                                                                      | VERIFIED | `private const ALLOWED` — 4 whitelisted. `HeaderForwarderTest` (6 unit tests) + `HeaderForwardingTest` (3 integration tests) dekken strip van Authorization/Cookie/X-Account-Id (T-05b-09)        |
| `app/Support/Snelstart/UpstreamErrorMapper.php`                                                    | Static `::mapException()` + 401/403→502 cloaking + 429+Retry-After + 5xx→502 + timeout→504                                                                     | VERIFIED | `UpstreamErrorMapperTest` (8 unit tests) + `PassThroughErrorMappingTest` (6 integration tests) — alle status-paden bewezen                                                                       |
| `app/Models/PassThroughCall.php`                                                                  | Immutable model — `$timestamps = false`, geen `update()`/`fill()`-pad                                                                                          | VERIFIED | `PassThroughCallModelTest::test_does_not_track_updated_at` (5 tests) — T-05b-03 SECURED                                                                                                         |
| `database/migrations/2026_05_15_000001_create_pass_through_calls_table.php`                       | Eigen audit-tabel (deviatie van ROADMAP `webhook_calls`)                                                                                                       | VERIFIED | Schema matcht CONTEXT.md §Audit-log: 12 kolommen incl. fingerprint + indexes; cascadeOnDelete op `consumer_id` (T-05b-04 accepted)                                                              |
| `database/migrations/2026_05_15_000002_add_query_keys_to_pass_through_calls_table.php`            | CR-02 follow-up: aparte `query_keys`-kolom voor PII-safe audit                                                                                                 | VERIFIED | Migration toegevoegd; `PassThroughController.php:120` schrijft `array_keys($query)`-CSV (geen waarden)                                                                                          |
| `app/Http/Controllers/Api/V1/AccountController.php` + `StoreAccountRequest` + `AccountResource`     | `POST /v1/accounts` + Form-Request-validatie + consumer_id-afleiding via `$request->user()->accounts()->create(...)`                                            | VERIFIED | T-05b-15 SECURED (geen `consumer_id` in body geaccepteerd); `StoreAccountTest` 7/7                                                                                                              |
| `app/Http/Controllers/Api/V1/ConnectionController.php` + `StoreConnectionRequest` + `ConnectionResource` | `POST/GET/DELETE /v1/connections` + cross-Consumer 422 (validation) of 404 (scope) + fingerprint-only response                                                  | VERIFIED | T-05b-12/13/14/16 SECURED. `StoreConnectionTest` 8/8 + `ShowConnectionTest` 4/4 + `DestroyConnectionTest` 5/5                                                                                  |

### Key Link Verification

| From                                       | To                                                  | Via                                                                                          | Status |
| ------------------------------------------ | --------------------------------------------------- | -------------------------------------------------------------------------------------------- | ------ |
| Route `Route::any('/snelstart/{path}')`    | `PassThroughController::__invoke`                   | `routes/api.php:44`                                                                          | WIRED  |
| Route → middleware                         | `EnsureProviderEnabled` + `ResolveSnelstartAccount` | `routes/api.php:46` (`['feature.provider:snelstart', 'resolve.snelstart.account']`); `bootstrap/app.php:37` alias | WIRED  |
| `ResolveSnelstartAccount`                  | `HubSnelstartCredentialResolver` (per-request)      | `app()->instance(SnelstartCredentialResolver::class, new HubSnelstartCredentialResolver($connection))` op regel 66-69 | WIRED  |
| `ResolveSnelstartAccount`                  | `Snelstart`-singleton herbouw                       | `app()->forgetInstance(Snelstart::class)` op regel 74                                         | WIRED  |
| `PassThroughController` → SDK              | `Emeq\SnelstartApi\Snelstart` (via DI-resolve)      | `RawSnelstartRequest` voor non-OData; OData QueryBuilder bij `$top`/`$filter`                | WIRED — bewezen door `PassThroughEchoPingTest` (4) + `PassThroughOdataRelatiesTest` (4) |
| `PassThroughController` → audit            | `PassThroughCall::create([...])`                    | Synchroon, vóór response (CONTEXT.md §Audit-timing)                                          | WIRED  |

## Requirements Coverage

| Requirement | Source Plans                           | Description                                                                                                   | Status      | Evidence                                                                                                                                                                                |
| ----------- | -------------------------------------- | ------------------------------------------------------------------------------------------------------------- | ----------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| HUB-05      | 05b-01, 05b-02, 05b-03, 05b-04, 05b-05 | Pass-through `/v1/snelstart/{path}` + provisioning-endpoints + `HubSnelstartCredentialResolver` + audit-log    | SATISFIED   | 8/8 SC's verified. Plans 5/5 complete. Geen orphaned requirements (REQUIREMENTS.md mapt HUB-05 → Phase 5b, alle 5 plans declareren HUB-05). UAT 9/9 passed (`05b-UAT.md`). SECURITY 24/24 closed (`05b-SECURITY.md`). |

### Test-baseline (Phase 5b-scoped)

Test-tellingen geverifieerd via `grep -c "public function test_"` op de testfiles:

| Testfile                                                                | Tests | Doel                                                            |
| ----------------------------------------------------------------------- | ----- | --------------------------------------------------------------- |
| `tests/Feature/Api/V1/StoreAccountTest.php`                             | 7     | SC-1 (Account-provisioning)                                     |
| `tests/Feature/Api/V1/StoreConnectionTest.php`                          | 8     | SC-2 (Connection-provisioning + fingerprint-only)               |
| `tests/Feature/Api/V1/ShowConnectionTest.php`                           | 4     | SC-5 (cross-Consumer 404 op GET)                                |
| `tests/Feature/Api/V1/DestroyConnectionTest.php`                        | 5     | SC-5 (cross-Consumer 404 op DELETE) + revoked_at                |
| `tests/Feature/Api/V1/Snelstart/PassThroughEchoPingTest.php`            | 4     | SC-3 + 415-guard (CR-01)                                        |
| `tests/Feature/Api/V1/Snelstart/PassThroughResolutionTest.php`          | 7     | SC-5 + SC-6 + OPTIONS→405                                       |
| `tests/Feature/Api/V1/Snelstart/PassThroughErrorMappingTest.php`        | 6     | T-05b-10 (401→502) + 429+RetryAfter + 5xx→502 + 404 passthrough |
| `tests/Feature/Api/V1/Snelstart/PassThroughOdataRelatiesTest.php`       | 4     | SC-4 + CR-02 (PII-safe query_keys)                              |
| `tests/Feature/Api/V1/Snelstart/PassThroughAuditNoSecretsTest.php`      | 4     | SC-7 + CR-03 (NULL fingerprint empty body)                      |
| `tests/Feature/Api/V1/Snelstart/HeaderForwardingTest.php`               | 3     | T-05b-09 integration (header-stripping)                         |
| `tests/Unit/Support/Snelstart/HeaderForwarderTest.php`                  | 6     | HeaderForwarder unit (whitelist 4 + explicit-strip 3)           |
| `tests/Unit/Support/Snelstart/UpstreamErrorMapperTest.php`              | 8     | UpstreamErrorMapper unit (8 exception-paths)                    |
| `tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php`            | 11    | SC-8 (Scramble discovery — 4 voor 5b + 7 voor 5a)               |
| `tests/Feature/PassThroughCallModelTest.php`                            | 5     | Immutability T-05b-03                                           |
| `tests/Feature/Services/HubSnelstartCredentialResolverTest.php`         | 4     | Resolver decryption-roundtrip                                   |
| **Totaal**                                                              | **86** | **Alle 8 SC's + 17 mitigate-threats hard-bewezen**             |

Phase-9 baseline-suite per `STATE.md` (2026-05-16): 391 tests / 1353 assertions / 0 failed / 1 incomplete. De ene incomplete is `Tests\Feature\OAuth\MollieConnectOAuthFlowTest::test_concurrent_refresh_race` (Phase-4 concurrent-refresh-race placeholder) — **niet** een Phase-5b-blocker.

## Anti-Patterns Found (informatief, niet acceptance-blokkerend)

Per `05b-REVIEW.md` overgenomen — 3 CRITICAL gefixed (zie kopje hierboven), 7 warnings + 4 infos blijven openstaan. Geen daarvan blokkeert het Phase-doel:

| File                                                       | Pattern                                                                  | Severity | Disposition                                                                         |
| ---------------------------------------------------------- | ------------------------------------------------------------------------ | -------- | ----------------------------------------------------------------------------------- |
| `PassThroughController.php:94-102`                         | Middleware-attributes zonder runtime null-guard (WR-01)                  | WARNING  | Defensive-only; middleware-fail kan geen request bereiken                          |
| `PassThroughController.php:60-92`                          | Audit-write niet idempotent bij JSON_THROW na catch (WR-02)              | WARNING  | Edge-case; backlog candidate                                                        |
| `UpstreamErrorMapper.php:30-42`                            | 401/403→502: `upstream_status: 401` blijft in body (WR-03)               | WARNING  | Status-code-cloaking gehandhaafd (T-05b-10); body-detail bewust open voor diagnose |
| `UpstreamErrorMapper.php:135-142`                          | `/HTTP\s+(\d{3})/`-regex matched ook generieke substrings (WR-04)        | WARNING  | SDK-message-coupling; v0.2.1 polish-candidate                                      |
| `HubSnelstartCredentialResolverTest.php:55-63`             | Test koppelt aan SDK-interne `InvalidArgumentException` (WR-05)          | WARNING  | Aanbeveling: early provider-check in resolver; backlog                              |
| `AccountController.php:27` / `StoreAccountRequest.php:20`  | `external_id` niet getrimd vóór persistence (WR-06)                      | WARNING  | Silent-bug-pattern; v0.2.1 polish-candidate                                         |
| `routes/api.php:44`                                        | `Route::any` accepteert alle methodes; controller filtert pas (WR-07)    | WARNING  | OPTIONS/HEAD/TRACE → 405 in controller (T-05b-23 closed); bespaart guard           |
| `PassThroughCall.php:11-30`                                | `$timestamps = false` maar `created_at` in `$fillable` (IN-01)           | INFO     | Inconsistent, niet incorrect                                                        |
| `tests/Concerns/PrimesSnelstartTokenCache.php:25-38`       | Trait dupliceert credential-bouw uit resolver (IN-02)                    | INFO     | Test-helper-duplicatie                                                              |
| `ConnectionController.php:97-104`                          | `whereHas` ipv join-query (IN-03)                                        | INFO     | Performance, out-of-scope                                                           |
| `ScrambleRouteDiscoveryTest.php:69-74`                     | `markTestSkipped` fallback voor catch-all-render (IN-04)                 | INFO     | UAT-test 9 live bevestigt dat `/snelstart/{path}` GET in 27-paths-spec staat → skip-pad wordt niet getriggerd |

## Deferred Items

Geen items uit `05b-REVIEW.md` of `05b-SECURITY.md` raken het Phase-doel; bovenstaande WARNINGS/INFO's worden gebundeld voor een v0.2.1 polish-task.

**Stale prompt-claim opgehelderd:** Het verifier-prompt noemde "een pre-existing incomplete test `Tests\Feature\Api\SanctumAbilityTest` (Phase 3-03 placeholder for Phase 5b ability-middleware)" als deferred item. Inspectie van `tests/Feature/Api/SanctumAbilityTest.php` toont **5 volledig-geïmplementeerde tests** zonder `markTestIncomplete` (regel 16, 26, 43, 62, 81). Per `05b-05-SUMMARY.md` (regel 36: "SanctumAbilityTest::test_token_without_required_ability_is_rejected: passing 403-test (geen markTestIncomplete meer)") en `tests/Feature/OAuth/MollieConnectOAuthFlowTest.php:47` is de werkelijke "+1 incomplete" in de suite een Phase-4 concurrent-refresh-race placeholder, **geen** Phase-5b-debt. Er is dus geen v0.2.1-opruiming nodig voor SanctumAbilityTest.

## Human Verification Required

Geen items. UAT 9/9 passed (`05b-UAT.md` 2026-05-16) heeft alle gebruikersperspectieven live geverifieerd via `bin/dev up --reset` + curl, inclusief:

- 1e/2e POST /v1/accounts (SC-1, 201 + 409-conflict)
- POST /v1/connections fingerprint-only (SC-2, raw-key encrypted in DB)
- /echo/ping resolver-binding (SC-3, audit `connection_id` correct)
- /relaties?$top=5 query_keys-only audit (SC-4 + CR-02)
- Cross-Consumer 404 (SC-5)
- 400/404/404 X-Account-Id-paden (SC-6)
- Audit-grep zonder raw secrets (SC-7 + CR-03)
- Scramble 27 paths incl. catch-all (SC-8)

## Conclusie

Phase 5b Snelstart-pass-through API is **closed**. Alle 8 ROADMAP success criteria zijn programmatisch bewezen via 86 Phase-5b-scoped tests (verspreid over 15 testfiles), 24/24 SECURITY-threats zijn gemitigeerd of geaccepteerd (`05b-SECURITY.md`), en 9/9 UAT-scenarios zijn live doorgelopen tegen Snelstart's test-omgeving (`05b-UAT.md`). De drie CRITICAL REVIEW-issues (CR-01 415-guard, CR-02 query_keys PII-safe, CR-03 NULL fingerprint empty body) zijn code-resident en getest. Score: **8/8 must-haves verified**, status **passed**. Eerste verifier-run dus geen regressie-check; geen openstaande gaps. Phase 5c (Snelstart webhook-handler, HUB-06) en Phase 8 (Naschool-wiring NSCH-01/02/03) kunnen op deze fundering landen.

---

_Verified: 2026-05-17T10:30:00Z_
_Verifier: Claude (gsd-verifier)_
_Triggered by: v0.2-MILESTONE-AUDIT.md verification-debt closure_
