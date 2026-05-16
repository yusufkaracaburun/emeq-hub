---
phase: 05b
slug: snelstart-pass-through-api
status: secured
threats_open: 0
threats_closed: 24
asvs_level: 1
created: 2026-05-16
audited_by: gsd-security-auditor
register_authored_at_plan_time: true
---

# SECURITY — Phase 05b-snelstart-pass-through-api

**Audit date:** 2026-05-16
**Audited by:** gsd-security-auditor (retroactive verification)
**ASVS Level:** 1
**Threat register source:** PLAN-time authored, 24 threats (T-05b-01 .. T-05b-24)
**Disposition:** 17 mitigate, 7 accept, 0 transfer

## Result: SECURED

**Threats closed:** 24/24
**Open BLOCKERS:** 0
**Unregistered flags:** 0

All mitigate-disposition threats have evidence in code + tests. All accept-disposition threats are documented in this file with rationale matching the PLAN-time disposition.

The three quick-task fixes that landed during UAT (260514-qxk) are verified in code:
- **CR-01** (415-guard non-JSON Content-Type) — `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php:50-57` + `tests/Feature/Api/V1/Snelstart/PassThroughEchoPingTest.php:112-128`
- **CR-02** (query_keys PII-safe — strips OData values from `path`) — `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php:119-120` + `tests/Feature/Api/V1/Snelstart/PassThroughOdataRelatiesTest.php:72-100` + migration `database/migrations/2026_05_15_000002_add_query_keys_to_pass_through_calls_table.php`
- **CR-03** (NULL fingerprint for empty body) — `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php:123-125` (`is_array($body) && $body !== []` guard) + `tests/Feature/Api/V1/Snelstart/PassThroughAuditNoSecretsTest.php:119-139`

---

## Threat Verification — Mitigate (17)

| Threat ID | Category | Mitigation Evidence (file:line) | Test Evidence | Status |
|-----------|----------|---------------------------------|---------------|--------|
| T-05b-01 | Info-disclosure (`path` no credentials) | `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php:119` (`'path' => $endpoint`; no query, no headers) + CR-02 isolates `query_keys` from values | `PassThroughOdataRelatiesTest::test_complex_odata_query_stores_only_query_keys_no_values_in_audit` | CLOSED |
| T-05b-02 | Info-disclosure (`upstream_error` short-code only) | `app/Support/Snelstart/UpstreamErrorMapper.php:40,54,68,82,99,112,125` — only fixed enum (`snelstart_auth`/`snelstart_5xx`/`snelstart_timeout`/`snelstart_unknown`/`null`) | `UpstreamErrorMapperTest::test_*_short_code` (8 cases) | CLOSED |
| T-05b-03 | Tampering (audit immutability) | `app/Models/PassThroughCall.php:34` (`public $timestamps = false`) + migration `2026_05_15_000001:25` (`created_at` only, no `updated_at`) + controller only uses `::create([...])` (no `update()`/`fill()` path) | `PassThroughCallModelTest::test_does_not_track_updated_at` | CLOSED |
| T-05b-05 | Info-disclosure (cleartext DTO via resolver) | `app/Services/Snelstart/HubSnelstartCredentialResolver.php:16-30` — `final readonly class`; returns `SnelstartCredentials` DTO; no `__toString`, no logging | `HubSnelstartCredentialResolverTest::test_resolve_returns_decrypted_snelstart_credentials` | CLOSED |
| T-05b-06 | Info-disclosure (per-request binding leak) | `app/Http/Middleware/ResolveSnelstartAccount.php:66-74` — `app()->instance(...)` per-request + `app()->forgetInstance(Snelstart::class)` defense-in-depth | `PassThroughEchoPingTest::test_credential_resolver_was_bound_to_the_right_connections_credentials_during_call` | CLOSED |
| T-05b-08 | Info-disclosure (502-body no Snelstart-body) | `app/Support/Snelstart/UpstreamErrorMapper.php:31-42` (502 body contains only fixed string `'Upstream auth failed'` + `upstream_detail`; no raw upstream body) | `UpstreamErrorMapperTest::test_authentication_exception_maps_to_502...` | CLOSED |
| T-05b-09 | Info-disclosure (header-leak Authorization/Cookie) | `app/Support/Snelstart/HeaderForwarder.php:28` (`private const ALLOWED = ['Accept','Content-Type','If-Match','If-None-Match']` — whitelist only) | `HeaderForwarderTest::test_strips_authorization_header_explicitly`, `test_strips_cookie_header_explicitly`, `test_strips_x_account_id_header_explicitly` + integration `HeaderForwardingTest` (3 cases) | CLOSED |
| T-05b-10 | Info-disclosure (401/403 → 502 rewrap) | `app/Support/Snelstart/UpstreamErrorMapper.php:30-42` — `AuthenticationException` → 502 (not 401/403) | `UpstreamErrorMapperTest::test_authentication_exception_maps_to_502_with_snelstart_auth_short_code` + integration `PassThroughErrorMappingTest::test_snelstart_401_maps_to_502_with_snelstart_auth_short_code` | CLOSED |
| T-05b-12 | Spoofing (cross-Consumer account_id) | `app/Http/Requests/Api/V1/StoreConnectionRequest.php:26` (`Rule::exists('accounts','id')->where('consumer_id', $consumerId)`) + defense-in-depth `app/Http/Controllers/Api/V1/ConnectionController.php:34-39` (`Account::where('consumer_id',...)->findOrFail($id)`) | `StoreConnectionTest::test_cross_consumer_account_id_returns_422_via_rule_exists` | CLOSED |
| T-05b-13 | Info-disclosure (cross-Consumer 404 not 403) | `app/Http/Controllers/Api/V1/ConnectionController.php:73-75,90-92` — `notFound('connection_not_found',...)` for both null and revoked + scoped via `findOwnedConnection()` (whereHas account.consumer_id) | `ShowConnectionTest::test_other_consumers_connection_returns_404_with_connection_not_found` + `DestroyConnectionTest::test_other_consumers_connection_returns_404_on_delete` | CLOSED |
| T-05b-14 | Info-disclosure (raw credentials in response) | `app/Http/Resources/Api/V1/ConnectionResource.php:17-28` — whitelist (`id,account_id,provider,status,fingerprint,revoked_at,created_at`); zero references to `client_key`/`subscription_key`/`access_token`/`refresh_token` (grep confirmed) + `app/Models/Connection.php:32` (`#[Hidden(...)]`) + `:73-76` (`'encrypted'` casts) | `StoreConnectionTest::test_creates_snelstart_connection_with_encrypted_credentials_and_returns_fingerprint_only` + `test_response_never_contains_raw_credentials` | CLOSED |
| T-05b-15 | Tampering (consumer_id in POST body) | `app/Http/Requests/Api/V1/StoreAccountRequest.php:17-22` — rules accept only `external_id` + `display_name`; no `consumer_id` accepted + `app/Http/Controllers/Api/V1/AccountController.php:28` writes via `$request->user()->accounts()->create([...])` (consumer_id derived from auth context) | `StoreAccountTest::test_creates_account_with_snelstart_write_ability_returns_201_and_resource_shape` (asserts `consumer_id` matches PAT-owner) | CLOSED |
| T-05b-16 | EOP (snelstart:read PAT does POST /v1/connections) | `app/Http/Controllers/Api/V1/ConnectionController.php:24-28,119-125` — `guardAbility()` enforces `CONSUMER_MANAGE_ACCOUNTS|SNELSTART_WRITE|ADMIN` (read not allowed) | `StoreConnectionTest::test_token_without_required_ability_returns_403` | CLOSED |
| T-05b-18 | Spoofing (cross-Consumer pass-through via X-Account-Id) | `app/Http/Middleware/ResolveSnelstartAccount.php:41-51` — `Account::where('consumer_id', $consumerId)->where('external_id', $header)` returns null → 404 `account_not_found` | `PassThroughResolutionTest::test_other_consumers_account_id_returns_404_not_403` | CLOSED |
| T-05b-19 | Tampering (path-traversal `/v1/snelstart/{path}`) | `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php:63` (`$endpoint = '/'.ltrim($path,'/')`) — passed verbatim to SDK; no local file-IO. Snelstart returns 400/401 for invalid paths → mapped to 502 via UpstreamErrorMapper. No filesystem entry-point. | Implicit via `PassThroughErrorMappingTest::test_snelstart_404_passes_through_as_404` (proves path goes to upstream) | CLOSED |
| T-05b-21 | Info-disclosure (`path` OData PII) | **CR-02 fix:** controller writes `path` (endpoint only, no query) + separate `query_keys` column (keys only, no values) — `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php:119-120` | `PassThroughOdataRelatiesTest::test_complex_odata_query_stores_only_query_keys_no_values_in_audit` (asserts `a@b.nl` + `Email eq` absent from all audit columns) | CLOSED |
| T-05b-23 | EOP (OPTIONS/HEAD/TRACE → 405) | `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php:23,29-34` — `const ALLOWED_METHODS = ['GET','POST','PATCH','DELETE']` whitelist; OPTIONS/HEAD/TRACE → 405 `method_not_allowed` | `PassThroughResolutionTest::test_options_method_returns_405_with_method_not_allowed` | CLOSED |

## Threat Verification — Accept (7) — Documented Risk Log

| Threat ID | Category | Acceptance Rationale | Risk-owner action |
|-----------|----------|---------------------|-------------------|
| T-05b-04 | Repudiation (cascadeOnDelete bij Consumer-delete) | `consumer_id` FK is `cascadeOnDelete` (migration `2026_05_15_000001:14`). Bij GDPR-erasure verdwijnen audit-rijen mee — bewust, want GDPR-erasure-pad past hierop. Async-archief is een Phase 9+ overweging. | Accepteer; her-evalueer bij Phase 9+ retention-policy. |
| T-05b-07 | Spoofing (resolver vertrouwt input) | Resolver heeft één caller (`ResolveSnelstartAccount`-middleware) die `consumer_id`-scoping al doet. Single-responsibility-principe — resolver heeft geen reden om scoping te dupliceren. | Accepteer; middleware-laag is de single point of trust. |
| T-05b-11 | DoS (429 zonder Retry-After) | `UpstreamErrorMapper.php:87-89` omit de header wanneer Snelstart 'm niet stuurt. Consumer doet eigen backoff. Hub-eigen rate-limiter naast Snelstart's = out-of-scope voor v0.2. | Accepteer; documenteer in Consumer-API-docs dat default-backoff verwacht wordt. |
| T-05b-17 | Repudiation (revoke geen aparte audit) | `revoked_at`-timestamp staat op de Connection-rij zelf; Phase 9 admin-UI kan ernaar tonen. Aparte audit-tabel voor revoke-events is overlapping met `pass_through_calls` (DELETE /v1/connections is geen pass-through). | Accepteer; Phase 9 Filament-UI levert audit-zichtbaarheid. |
| T-05b-20 | Info-disclosure (48-bit fingerprint reverseerbaar) | `sha256[0..12]` = 48-bit. Voor gerichte body als `{"naam":"X"}` rainbow-tabel mogelijk maar low-value (Consumer's eigen body, géén secret). | Accepteer; bump naar 24/32 chars bij hoog-volume-systeem. Deferred concern. |
| T-05b-22 | Repudiation (synchroon audit-write) | Audit-write is synchroon (geen async-queue). Bij DB-uitval lekt een pass-through-call zonder audit-rij, maar DB-uitval is een breder incident dan 5b-specifiek. Geen async dus geen verlies-risico. | Accepteer; DB-uitval is een platform-niveau concern. |
| T-05b-24 | Info-disclosure (Scramble /docs/api publiek) | Scramble heeft een `viewApiDocs`-Gate met `?token=`-query (AppServiceProvider). In productie: `scramble.access_token` uit env-var. Niet 5b-specifiek. | Accepteer; productie-env-config zet token. Bevestigd in AppServiceProvider config. |

## Unregistered Flags

None. SUMMARY.md `## Threat Flags` sections of plans 01, 04, 05 expliciet bevestigen *"Geen nieuwe trust-boundaries die niet al in `<threat_model>` van het plan staan."* Plans 02 en 03 expose'n geen Threat Flags sectie (geen nieuwe surface).

## Coverage Summary

- **Mitigate threats:** 17/17 closed met grep-bevestigde code-evidence + named tests
- **Accept threats:** 7/7 documented in this file met rationale matching PLAN-time disposition
- **Transfer threats:** 0
- **CR-fixes geverifieerd:** 3/3 (CR-01 415-guard, CR-02 query_keys, CR-03 NULL fingerprint empty body)

## Files Audited (Read-Only)

Implementation:
- `app/Models/PassThroughCall.php`
- `database/migrations/2026_05_15_000001_create_pass_through_calls_table.php`
- `database/migrations/2026_05_15_000002_add_query_keys_to_pass_through_calls_table.php`
- `app/Services/Snelstart/HubSnelstartCredentialResolver.php`
- `app/Support/Snelstart/UpstreamErrorMapper.php`
- `app/Support/Snelstart/HeaderForwarder.php`
- `app/Http/Requests/Api/V1/StoreAccountRequest.php`
- `app/Http/Requests/Api/V1/StoreConnectionRequest.php`
- `app/Http/Resources/Api/V1/AccountResource.php`
- `app/Http/Resources/Api/V1/ConnectionResource.php`
- `app/Http/Controllers/Api/V1/AccountController.php`
- `app/Http/Controllers/Api/V1/ConnectionController.php`
- `app/Http/Middleware/ResolveSnelstartAccount.php`
- `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php`
- `routes/api.php`
- `bootstrap/app.php`
- `app/Models/Connection.php` (encrypted casts confirmed)

Tests verified by grep:
- `tests/Feature/PassThroughCallModelTest.php`
- `tests/Feature/Services/HubSnelstartCredentialResolverTest.php`
- `tests/Unit/Support/Snelstart/UpstreamErrorMapperTest.php`
- `tests/Unit/Support/Snelstart/HeaderForwarderTest.php`
- `tests/Feature/Api/V1/StoreAccountTest.php`
- `tests/Feature/Api/V1/StoreConnectionTest.php`
- `tests/Feature/Api/V1/ShowConnectionTest.php`
- `tests/Feature/Api/V1/DestroyConnectionTest.php`
- `tests/Feature/Api/V1/Snelstart/PassThroughResolutionTest.php`
- `tests/Feature/Api/V1/Snelstart/PassThroughEchoPingTest.php`
- `tests/Feature/Api/V1/Snelstart/PassThroughOdataRelatiesTest.php`
- `tests/Feature/Api/V1/Snelstart/PassThroughErrorMappingTest.php`
- `tests/Feature/Api/V1/Snelstart/PassThroughAuditNoSecretsTest.php`
- `tests/Feature/Api/V1/Snelstart/HeaderForwardingTest.php`

---

*Phase 05b-snelstart-pass-through-api security gate: PASSED.*
