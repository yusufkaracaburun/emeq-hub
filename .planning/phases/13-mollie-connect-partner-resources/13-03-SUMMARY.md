---
phase: 13-mollie-connect-partner-resources
plan: 03
subsystem: api
tags: [mollie, mollie-connect, partner-token, pass-through, feature-tests, scramble, openapi, php, laravel]

requires:
  - phase: 13-mollie-connect-partner-resources
    plan: 02
    provides: AbstractMollieConnectPassThroughController + 5 Connect-controllers + 2 Form Requests + 9 routes
  - phase: 13-mollie-connect-partner-resources
    plan: 01
    provides: MollieAccessTokenResolver + pass_through_calls.token_type/partner_token_fingerprint + 503 partner_token_missing mapper-branch
  - phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
    provides: StubMollieClient (Phase-5a-artefact dat uitgebreid wordt), Tests\Concerns\StubsMollieClient, MolliePassThroughErrorMappingTest-pattern
provides:
  - Tests\Feature\Api\V1\Mollie\Connect\StubMollieConnectClient (test-only MollieApiClient-subclass met access-token + Idempotency-Key capture)
  - Tests\Concerns\StubsMollieConnectClient (test-trait met setupMollieConnectConsumer / setPartnerToken / bindMollieConnectStubs / callMollieConnect / makeMollieResourceWithBody / makeMollieCollectionWithBody)
  - 5 per-resource feature-tests (17 tests totaal voor MOLL-05 SC-1)
  - TokenResolverIntegrationTest (3 tests voor MOLL-06 SC-2)
  - StubMollieClient 1-regel-uitbreiding (Phase-5a) met access-token + Idempotency-Key capture
  - ScrambleRouteDiscoveryTest +3 nieuwe test-methods voor MOLL-05 SC-3 (Connect-routes onder 'Mollie · Connect' tag)
affects: [13-04 (ADR — kan SUMMARY-referenties naar test-mapping gebruiken)]

tech-stack:
  added: []
  patterns:
    - "Connect-stub-client subclass MollieApiClient met publieke capture-properties (lastUsedAccessToken + lastIdempotencyKey) + endpoint-property-stub-injection via __get — sibling van Phase-5a's StubMollieClient"
    - "Container-binding voor stub-client via \$this->app->instance(MollieApiClient::class, \$stub) — Plan 13-02's app(MollieApiClient::class) resolve-call krijgt exact dezelfde instance terug"
    - "makeMollieResourceWithBody / makeMollieCollectionWithBody helper: anonymous subclass van Mollie\\Api\\Http\\Response met no-op constructor + override van body()/status() zodat resourceToArray() de geneste velden (_links/_embedded) verbatim leest via \$response->body() i.p.v. de json_encode-fallback (BLOCKER-5-fix)"
    - "MOLL-06 SC-2 spy-symmetrie via twee stub-clients met identieke \$lastUsedAccessToken-shape: merchant-pad capture'd Connection-token via inline Mollie-wrapper-mock met setAccessToken-callback; Connect-pad capture'd partner-env-var-token via productie-pad onveranderd"

key-files:
  created:
    - tests/Feature/Api/V1/Mollie/Connect/StubMollieConnectClient.php
    - tests/Concerns/StubsMollieConnectClient.php
    - tests/Feature/Api/V1/Mollie/Connect/ClientLinksTest.php
    - tests/Feature/Api/V1/Mollie/Connect/OnboardingTest.php
    - tests/Feature/Api/V1/Mollie/Connect/OrganizationsTest.php
    - tests/Feature/Api/V1/Mollie/Connect/PermissionsTest.php
    - tests/Feature/Api/V1/Mollie/Connect/ProfilesTest.php
    - tests/Feature/Api/V1/Mollie/Connect/TokenResolverIntegrationTest.php
  modified:
    - tests/Feature/Api/V1/Mollie/StubMollieClient.php
    - tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php

key-decisions:
  - "Per-resource tests gebruiken Hub-side Emeq\\MollieApi\\Exceptions\\AuthenticationException om de 401-mapping te triggeren i.p.v. raw \\Mollie\\Api\\Exceptions\\ApiException — laatste vereist een echte Response-instance in de constructor (geen no-op mogelijk); Hub-side type wordt direct door MollieUpstreamErrorMapper gemapt (identiek aan Phase-5a MolliePassThroughErrorMappingTest-pattern)"
  - "TokenResolverIntegrationTest-merchant-test gebruikt inline Mollie-wrapper-mock die expliciet stub->setAccessToken(\$connection->access_token) aanroept vóór de stub terug te geven — Phase-5a's productie Mollie::client() doet dat zelf (in een new MollieApiClient), maar de test-pipeline mockt de hele wrapper-laag dus moet de spy-call expliciet door de testopstelling getriggerd worden. Alternatief (aanroep van Mollie::credentials() i.p.v. spy) was minder symmetrisch met de Connect-test"
  - "setIdempotencyKey-override op StubMollieClient + StubMollieConnectClient gebruikt untyped \$key parameter — parent (HandlesIdempotency-trait) declareert geen type-hint; een string-typehint zou een LSP-violation geven en de class niet laten autoladen"
  - "makeMollieResourceWithBody helper gebruikt \$resource->setResponse(\$fakeResponse) (publieke setter uit HasResponse-trait) i.p.v. reflectie — schoner dan de plan-tekst voorstelde, en de fakeResponse is een anonymous subclass van Mollie\\Api\\Http\\Response met no-op constructor + body()/status() override (geen PSR-request/response/pending-request hoek nodig)"

patterns-established:
  - "Connect-feature-test pattern: setPartnerToken → setupMollieConnectConsumer → bindMollieConnectStubs met makeMollieResourceWithBody / makeMollieCollectionWithBody → callMollieConnect (zonder X-Account-Id) → assertResponse + assertDatabaseHas met token_type=partner. Direct herbruikbaar voor toekomstige Connect-resources zonder duplicatie van de stub-bouwlaag."
  - "Spy-symmetrie tussen twee pass-through-paden: één integration-test met identieke \$lastUsedAccessToken-property op beide stub-clients bewijst dat token-resolver-config-drift (b.v. iemand stilletjes de partner-token op merchant-routes routeert) zou worden gevangen door één testfile"

requirements-completed: [MOLL-05, MOLL-06]

duration: ~30min
completed: 2026-05-18
---

# Phase 13 Plan 03: Mollie Connect feature-tests + MOLL-06 SC-2 integration-test Summary

**8 nieuwe test-files (2 helpers + 5 per-resource + 1 token-resolver-integration) + 1-regel-uitbreiding op Phase-5a's StubMollieClient + 3 nieuwe Scramble-test-methods — bewijst MOLL-05 SC-1 (per-resource 200+401), MOLL-06 SC-2 (beide token-paden expliciet) en MOLL-05 SC-3 (Connect-routes onder 'Mollie · Connect' tag), met audit-rij-shape bewijs voor token_type=partner.**

## Performance

- **Duration:** ~30 min
- **Started:** 2026-05-18T15:31:00Z (wave 3 spawn)
- **Completed:** 2026-05-18T~16:00Z
- **Tasks:** 3 (alle TDD: RED → GREEN, geen REFACTOR)
- **Files created:** 8 (2 helpers + 5 per-resource + 1 token-resolver-integration)
- **Files modified:** 2 (StubMollieClient.php + ScrambleRouteDiscoveryTest.php)
- **Nieuwe tests:** 23 (17 per-resource + 3 token-resolver + 3 Scramble)
- **Totale suite:** 564 tests (vs 541 vóór Plan 13-03 = +23)

## Accomplishments

- `StubMollieConnectClient` (sibling van Phase-5a's StubMollieClient): subclass van `Mollie\Api\MollieApiClient` met publieke `$lastUsedAccessToken` + `$lastIdempotencyKey`, override van `setAccessToken(string $token)` + `setIdempotencyKey($key)`, en endpoint-property-stub-injection via `__get($name)`.
- `Tests\Concerns\StubsMollieConnectClient` trait: `setupMollieConnectConsumer()` (Consumer+PAT, géén Account/Connection — D-07), `setPartnerToken()` (config + forgetInstance van singleton-resolver), `bindMollieConnectStubs()` (instance-binding op `MollieApiClient::class` — Plan 13-02's `client()` doet `app(MollieApiClient::class)`), `callMollieConnect()` (zonder X-Account-Id-header — D-07), `makeMollieResourceWithBody()` + `makeMollieCollectionWithBody()` (anonymous Response-subclass met no-op constructor zodat `resourceToArray()` de geneste velden _links/_embedded uit `body()` leest).
- 5 per-resource feature-tests met 17 totaal-tests: ClientLinks (4), Onboarding (2), Organizations (3), Permissions (3), Profiles (5). Élke happy-path-test asserteert `assertDatabaseHas('pass_through_calls', [..., 'token_type' => 'partner', 'connection_id' => null, 'account_id' => null, ...])` + `partner_token_fingerprint` = 12-char sha256-prefix. 401-error-mapping per resource via `Emeq\MollieApi\Exceptions\AuthenticationException` (identiek aan Phase-5a MolliePassThroughErrorMappingTest-pattern).
- `TokenResolverIntegrationTest` (3 tests) bewijst MOLL-06 SC-2:
  - `test_merchant_route_uses_connection_access_token()` — inline Mollie-wrapper-mock met `setAccessToken($connection->access_token)`-callback emuleert productie-keten; `$stub->lastUsedAccessToken === $connection->access_token` na call.
  - `test_connect_route_uses_partner_access_token()` — bindMollieConnectStubs + productie-pad doet automatisch `setAccessToken(partner-env-var)`; `$stub->lastUsedAccessToken === 'access_partner_xyz'`.
  - `test_connect_route_with_missing_partner_token_returns_503_partner_token_missing()` — config-clear van partner-token, hit Connect-route, assert 503 + body.error=partner_token_missing + audit-rij met `upstream_error='partner_token_missing'`.
- `StubMollieClient` (Phase-5a) 1-regel-uitbreiding: publieke `$lastUsedAccessToken` + `$lastIdempotencyKey` properties + `setAccessToken()` + `setIdempotencyKey()` overrides. Bestaande Phase-5a-tests blijven groen (48/48 in MolliePassThrough|PaymentsTest|CustomersTest|MethodsTest|RefundsTest|MandatesTest|SubscriptionsTest|PaymentLinksTest filter).
- `ScrambleRouteDiscoveryTest` +3 test-methods: `test_openapi_spec_contains_all_nine_mollie_connect_routes` (alle 9 path+method-combinaties), `test_openapi_spec_groups_connect_routes_under_mollie_connect_tag` (3 sample-paths bewijzen tag-grouping), `test_openapi_spec_preserves_existing_mollie_merchant_tags` (regressie-vangst voor `Mollie · Payments` + `Mollie · Customers`).

## Task Commits

Each task was committed atomically (RED + GREEN gebundeld per task — zelfde rationale als Plan 13-01 + 13-02: tests blijven na GREEN bestaan en horen bij de deliverable):

1. **Task 1: StubMollieConnectClient + StubsMollieConnectClient trait** — `df51125` (test)
2. **Task 2: 5 per-resource feature-tests (17 tests)** — `7b85b9b` (test)
3. **Task 3: TokenResolverIntegrationTest + StubMollieClient capture + Scramble Connect-tags** — `8594a79` (test)

## Files Created/Modified

**Created (8):**

| Path | Provides |
|---|---|
| `tests/Feature/Api/V1/Mollie/Connect/StubMollieConnectClient.php` | Test-only MollieApiClient-subclass met access-token + Idempotency-Key capture + endpoint-property-stub-injection |
| `tests/Concerns/StubsMollieConnectClient.php` | Test-trait met setupMollieConnectConsumer / setPartnerToken / bindMollieConnectStubs / callMollieConnect / makeMollieResourceWithBody / makeMollieCollectionWithBody |
| `tests/Feature/Api/V1/Mollie/Connect/ClientLinksTest.php` | 4 tests: POST 201 + Idempotency-Key forward + 401 mapping + 422 Form Request |
| `tests/Feature/Api/V1/Mollie/Connect/OnboardingTest.php` | 2 tests: GET /me 200 + 401 mapping |
| `tests/Feature/Api/V1/Mollie/Connect/OrganizationsTest.php` | 3 tests: GET /me 200 + GET /{id} 200 + 401 mapping |
| `tests/Feature/Api/V1/Mollie/Connect/PermissionsTest.php` | 3 tests: GET list (PermissionCollection wire-shape) + GET /{id} + 401 mapping |
| `tests/Feature/Api/V1/Mollie/Connect/ProfilesTest.php` | 5 tests: GET list (ProfileCollection wire-shape) + POST 201 + GET /{id} + 422 Form Request + 401 mapping |
| `tests/Feature/Api/V1/Mollie/Connect/TokenResolverIntegrationTest.php` | 3 tests: merchant-pad spy + connect-pad spy + missing-partner-token 503 (MOLL-06 SC-2) |

**Modified (2):**

- `tests/Feature/Api/V1/Mollie/StubMollieClient.php` — Phase-5a-artefact uitgebreid met publieke `$lastUsedAccessToken` + `$lastIdempotencyKey` + `setAccessToken()` + `setIdempotencyKey()` overrides (1-regel-uitbreiding declarative).
- `tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php` — +3 nieuwe test-methods voor MOLL-05 SC-3 Connect-routes + tag-grouping + regressie-vangst voor bestaande Mollie-merchant-tags.

## MOLL-05 SC-1 mapping — per-route 200 + 401 coverage

| # | Route | Hub-pad | 200-test | 401-test |
|---|---|---|---|---|
| 1 | `GET /v1/mollie/connect/onboarding/me` | `/v2/onboarding/me` | `OnboardingTest::test_get_onboarding_me_returns_resource_and_writes_partner_audit_row` | `OnboardingTest::test_get_onboarding_with_auth_failure_maps_to_502_mollie_auth_failed` |
| 2 | `GET /v1/mollie/connect/organizations/me` | `/v2/organizations/me` | `OrganizationsTest::test_get_organizations_me_returns_resource` | `OrganizationsTest::test_get_organization_with_auth_failure_maps_to_502_mollie_auth_failed` |
| 3 | `GET /v1/mollie/connect/organizations/{id}` | `/v2/organizations/{id}` | `OrganizationsTest::test_get_organization_by_id_returns_resource` | (gedeeld met me — beide endpoints raken dezelfde mapper-branch) |
| 4 | `GET /v1/mollie/connect/profiles` | `/v2/profiles` | `ProfilesTest::test_get_profiles_returns_paginated_collection` | `ProfilesTest::test_get_profiles_with_auth_failure_maps_to_502_mollie_auth_failed` |
| 5 | `POST /v1/mollie/connect/profiles` | `/v2/profiles` | `ProfilesTest::test_post_profile_creates_resource_returns_201` | (gedeeld + extra: `test_post_profile_with_missing_required_fields_returns_422` voor Form Request) |
| 6 | `GET /v1/mollie/connect/profiles/{id}` | `/v2/profiles/{id}` | `ProfilesTest::test_get_profile_by_id_returns_resource` | (gedeeld) |
| 7 | `GET /v1/mollie/connect/permissions` | `/v2/permissions` | `PermissionsTest::test_get_permissions_returns_collection_200` | `PermissionsTest::test_get_permissions_with_auth_failure_maps_to_502_mollie_auth_failed` |
| 8 | `GET /v1/mollie/connect/permissions/{id}` | `/v2/permissions/{id}` | `PermissionsTest::test_get_permission_by_id_returns_resource` | (gedeeld — beide endpoints raken dezelfde mapper-branch via Hub-side AuthenticationException) |
| 9 | `POST /v1/mollie/connect/client-links` | `/v2/client-links` | `ClientLinksTest::test_post_client_link_creates_resource_and_returns_201_with_audit_row` | `ClientLinksTest::test_post_client_link_with_mollie_auth_failure_maps_to_502_mollie_auth_failed` + extra `test_post_client_link_with_idempotency_key_forwards_to_mollie_client` + `test_post_client_link_with_invalid_payload_returns_422_validation_failed` |

5 expliciete 401-tests (één per resource) + 4 impliciete via gedeelde mapper-pad voor sibling-endpoints binnen dezelfde resource (organizations/me + /{id}, profiles/* x3, permissions list+show).

## MOLL-06 SC-2 mapping — beide token-paden expliciet

| Test-method | Pad | Spy-mechanisme | Assertion |
|---|---|---|---|
| `test_merchant_route_uses_connection_access_token` | `POST /v1/mollie/payments` via Mollie-facade | `StubMollieClient::$lastUsedAccessToken` (inline Mollie-wrapper-mock met setAccessToken-callback emuleert productie-keten) | `$stub->lastUsedAccessToken === $connection->access_token` (decrypted Connection-token, niet partner-env-var) |
| `test_connect_route_uses_partner_access_token` | `GET /v1/mollie/connect/permissions` via container-resolved client | `StubMollieConnectClient::$lastUsedAccessToken` (productie-pad: AbstractMollieConnectPassThroughController::client() roept zelf setAccessToken aan) | `$stub->lastUsedAccessToken === 'access_partner_xyz'` (env-var-waarde via config('services.mollie.partner_access_token')) |
| `test_connect_route_with_missing_partner_token_returns_503_partner_token_missing` | `GET /v1/mollie/connect/permissions` met `partner_access_token = null` | Geen spy nodig — MollieAccessTokenResolver gooit MissingPartnerTokenException → MollieUpstreamErrorMapper → 503 | `$response->assertStatus(503)->assertJsonPath('error', 'partner_token_missing')` + audit-rij met `upstream_error='partner_token_missing'`, `token_type='partner'` |

Symmetrische spy-shape: beide stub-clients hebben publieke `$lastUsedAccessToken` property + override van `setAccessToken()` die de property zet. Eén test-file dekt beide paden expliciet zoals ROADMAP's `success_criteria` vraagt: "Een integration-test dekt beide paden expliciet".

## MOLL-05 SC-3 mapping — Scramble Connect-routes onder 'Mollie · Connect' tag

| Test-method | Wat het bewijst |
|---|---|
| `test_openapi_spec_contains_all_nine_mollie_connect_routes` | Alle 9 path+method-combinaties uit Plan 13-02 staan in `/docs/api.json` (assertArrayHasKey × 11: 9 path-entries waarvan 1 path 2 methods heeft = 10 method-checks + 9 path-checks) |
| `test_openapi_spec_groups_connect_routes_under_mollie_connect_tag` | 3 sample-paths (`/mollie/connect/onboarding/me` GET, `/mollie/connect/profiles` POST, `/mollie/connect/client-links` POST) hebben `'Mollie · Connect'` in hun `tags`-array |
| `test_openapi_spec_preserves_existing_mollie_merchant_tags` | Bestaande tags (`Mollie · Payments` op `/mollie/payments` POST, `Mollie · Customers` op `/mollie/customers` GET) blijven onveranderd na Phase-13 group-attribute-additions |

3 nieuwe test-methods in ScrambleRouteDiscoveryTest, samen 8 assertArrayHasKey + 3 assertContains-asserties.

## Final test-count

| Filter | Tests | Assertions |
|---|---|---|
| `tests/Feature/Api/V1/Mollie/Connect/` (resource-tests + scaffold + route-reg + integration) | 29 | 199 |
| `tests/Feature/Api/V1/Mollie/Connect/` minus Phase-13-02 scaffold/route-reg (9) | 20 | (17 resource + 3 integration) |
| `tests/Feature/Documentation/ScrambleRouteDiscoveryTest` (11 bestaand + 3 nieuw) | 14 | 83 |
| Phase-5a regressie-filter (`MolliePassThrough\|PaymentsTest\|CustomersTest\|MethodsTest\|RefundsTest\|MandatesTest\|SubscriptionsTest\|PaymentLinksTest`) | 48 | 182 |
| **Volledige suite** | **564 (563 passed + 1 pre-existing failure)** | **2013** |

Pre-Plan-13-03 baseline (Plan 13-02 SUMMARY): 541/541 (suite excl. failure).
Phase-13-03 delta: +23 tests (17 resource + 3 integration + 3 scramble). Volledige suite-failure unchanged: `UserResourceTest::test_super_admin_can_create_user_via_resource` (Filament/Spatie-rollen, Phase-11 deferred-items).

## Vendor-signature-drift t.o.v. plan

- **Plan 13-03 regel 283:** "Stub clientLinks->create gooit `new \Mollie\Api\Exceptions\ApiException('Unauthorized', 401)`". **Implementatie:** stubs gooien `Emeq\MollieApi\Exceptions\AuthenticationException`. Reden: raw `Mollie\Api\Exceptions\ApiException` heeft een verplichte `Response`-parameter in de constructor (`public function __construct(Response $response, string $message, int $code, …)`) — niet eenvoudig op te bouwen zonder een mock-PSR-stack. Hub-side `AuthenticationException` wordt direct door `MollieUpstreamErrorMapper::mapException()` gemapt naar 502 `mollie_auth_failed`, identiek aan Phase-5a `MolliePassThroughErrorMappingTest` (regel 42: `new AuthenticationException('upstream auth failure')`). Dezelfde HTTP-shape, dezelfde mapper-pad, simpelere setup.

- **Plan 13-03 regel 169:** "public function setIdempotencyKey(string $key): self". **Implementatie:** `public function setIdempotencyKey($key): self` (untyped $key). Reden: parent (`Mollie\Api\Traits\HandlesIdempotency::setIdempotencyKey($key)`) declareert geen type-hint; een string-typehint zou een LSP-violation geven en de class niet laten autoladen.

- **Plan 13-03 regel 206-216 (reflection-pad):** "Mollie's BaseResource heeft een protected $response — gebruik reflectie". **Implementatie:** `$resource->setResponse($fakeResponse)` via de publieke setter uit `HasResponse`-trait (regel 28 van `vendor/mollie/mollie-api-php/src/Traits/HasResponse.php`). Geen reflectie nodig — schoner.

## Test-output snippets

**Per-resource Connect-tests (17 tests, 73 assertions):**

```
{"tool":"phpunit","result":"passed","tests":17,"passed":17,"assertions":73,"duration_ms":1126}
```

**TokenResolverIntegrationTest (3 tests, 10 assertions):**

```
{"tool":"phpunit","result":"passed","tests":3,"passed":3,"assertions":10,"duration_ms":829}
```

**ScrambleRouteDiscoveryTest (14 tests = 11 bestaand + 3 nieuw, 83 assertions):**

```
{"tool":"phpunit","result":"passed","tests":14,"passed":14,"assertions":83,"duration_ms":5964}
```

**Phase-5a regressie-filter (`MolliePassThrough|PaymentsTest|CustomersTest|MethodsTest|RefundsTest|MandatesTest|SubscriptionsTest|PaymentLinksTest`):**

```
{"tool":"phpunit","result":"passed","tests":48,"passed":48,"assertions":182,"duration_ms":3516}
```

**Volledige suite (564 tests):**

```
{"tool":"phpunit","result":"failed","tests":564,"passed":563,"assertions":2013,"duration_ms":25831,"failed":1,"failures":[{"test":"Tests\\Feature\\Admin\\UserResourceTest::test_super_admin_can_create_user_via_resource", …}],"incomplete":1}
```

De ene failure is identiek aan Plan 13-01 + 13-02 SUMMARY § Issues Encountered: pre-existing Filament `UserResource`-flow voor Spatie-rollen (`Component has errors: "data.roles"`), Phase-11 deferred-items.

**Pint:**

```
{"tool":"pint","result":"passed"}
```

## Decisions Made

- **Stubs gooien Hub-side Emeq-exception i.p.v. raw Mollie ApiException** (zie Vendor-signature-drift sectie): test-setup simpel, mapping-pad identiek aan Phase-5a-precedent (MolliePassThroughErrorMappingTest).
- **Inline Mollie-wrapper-mock in `test_merchant_route_uses_connection_access_token`** i.p.v. uitbreiden van `bindMollieStubs()`: spy-callback emuleert productie-keten alleen voor deze ene test; geen Phase-5a-trait-wijziging die andere tests zou kunnen raken.
- **`setResponse()` publieke setter** i.p.v. reflectie in helper: Mollie SDK levert deze al via `HasResponse`-trait — geen reden voor reflection-truc.
- **Geen Mollie-Connect-resolver-config-flag op merchant-tests** (zoals plan-tekst suggereerde via `Mollie::credentials()`): de existing Phase-5a `Mollie::client()`-mock via wrapper-replace blijft de canonieke test-injectie. Merchant-spy is een aanvulling, geen vervanging.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Worktree-bootstrap: vendor/ + .env symlinks**

- **Found during:** Initial dependency-check.
- **Issue:** Worktree miste `vendor/` en `.env` — zelfde environment-issue als Plan 13-01 + 13-02.
- **Fix:** `ln -s /Users/.../emeq-hub/vendor` + `ln -s /Users/.../emeq-hub/.env` in worktree-root, daarna `composer dump-autoload -o`.
- **Files modified:** geen tracked files.
- **Verification:** `php artisan test --compact tests/Feature/Api/V1/Mollie/Connect/ConnectRouteRegistrationTest.php` baseline groen (3/3).

**2. [Rule 3 - Blocking] LSP-violation in setIdempotencyKey-override**

- **Found during:** Eerste tinker-check na Task 1 write (`Whoops\Run::handleShutdown` met signature-mismatch).
- **Issue:** `public function setIdempotencyKey(string $key): self` op zowel StubMollieConnectClient als StubMollieClient — parent `Mollie\Api\Traits\HandlesIdempotency::setIdempotencyKey($key)` declareert geen type-hint, dus child mag geen strikter type-hint introduceren.
- **Fix:** Override-signature gewijzigd naar `public function setIdempotencyKey($key): self` met inline `is_string($key) ? $key : (string) $key` voor de capture-property.
- **Files modified:** tests/Feature/Api/V1/Mollie/Connect/StubMollieConnectClient.php + tests/Feature/Api/V1/Mollie/StubMollieClient.php.
- **Verification:** tinker class-existence + autoload schoon na fix; Phase-5a regressie-filter 48/48 groen.

**3. [Rule 1 - Bug] Plan-veronderstelling over Phase-5a Mollie::client() spy-mechanisme klopt niet productie-1-op-1**

- **Found during:** Schrijven van `test_merchant_route_uses_connection_access_token` — analyse van wat er in de Phase-5a `bindMollieStubs()`-keten gebeurt.
- **Issue:** Plan 13-03 §action regel 406 zegt: "HubMollieCredentialResolver zet het Connection-token via `Mollie::client()->setAccessToken(...)`. Bevestig dit door eenmalig in de test te asserten dat `$stub->lastUsedAccessToken !== null` na de call." In werkelijkheid roept productie `Mollie::client()` (Mollie-wrapper in `vendor/emeq/mollie-api/src/Mollie.php` regel 60) `$client->setAccessToken()` aan op een **nieuw** `MollieApiClient`-object, niet op het object dat naar de caller wordt teruggegeven. De Phase-5a test-trait `bindMollieStubs()` mockt de hele wrapper-laag — `Mollie::client()` returnt direct de stub, bypassing de `setAccessToken`-call. Dus zonder explicietere setup ziet `$stub->lastUsedAccessToken` enkel de constructor-seed (`'access_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'`).
- **Fix:** Inline Mollie-wrapper-mock in `TokenResolverIntegrationTest::test_merchant_route_uses_connection_access_token()`: `$mollie->method('client')->willReturnCallback(function () use ($stub, $expectedToken) { $stub->setAccessToken($expectedToken); return $stub; })`. Dit emuleert productie-keten **voor deze ene test** zonder de gedeelde StubsMollieClient-trait te wijzigen (alle bestaande Phase-5a-tests blijven werken).
- **Plan-alternatief overwogen:** assert via `Mollie::credentials()->accessToken === $connection->access_token` — bewees minder symmetrisch met de Connect-pad-spy (geen `$lastUsedAccessToken`-property aan beide kanten). Inline-mock geeft visueel hetzelfde assertie-shape voor beide test-methods → leesbaarheid wint.
- **Files modified:** tests/Feature/Api/V1/Mollie/Connect/TokenResolverIntegrationTest.php.
- **Verification:** test passes (3/3 in TokenResolverIntegrationTest), Phase-5a regressie-filter blijft 48/48 groen.

---

**Total deviations:** 3 auto-fixed (2 Rule 3 environment + LSP-blocker, 1 Rule 1 plan-assumption-mismatch).
**Impact on plan:** Geen impact op deliverables — 8 plan-files landen exact zoals gespecificeerd, en de inline-mock in TokenResolverIntegrationTest is een test-only patroon dat geen productiecode raakt.

## Issues Encountered

- **Pre-existing `UserResourceTest::test_super_admin_can_create_user_via_resource` failure** — al gedocumenteerd in 13-01 + 13-02 SUMMARY's en STATE.md (Phase-11 deferred-items). Niet veroorzaakt door dit plan; volle suite 563/564 passed.

## User Setup Required

Geen externe-service config nodig voor dit plan. Phase 13 als geheel is testbaar zonder productie-MOLLIE_PARTNER_ACCESS_TOKEN — TokenResolverIntegrationTest test 3 dekt het `config: null`-pad expliciet (503-mapping).

## Documentation Drift

Geen drift in dit plan — geen productie-routes, modellen, of API-shapes gewijzigd. Plan 13-04 (ADR) zal de tests-coverage-mapping (boven, MOLL-05/06 mapping-tabellen) als bron gebruiken bij het schrijven van `.docs/decisions/mollie-connect-partner-resources.md`.

## Next Phase Readiness

**Klaar voor Plan 13-04 (ADR):**
- Alle test-bewijs voor MOLL-05 SC-1, MOLL-05 SC-3, MOLL-06 SC-2 staat in `tests/Feature/Api/V1/Mollie/Connect/` + `tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php`.
- ADR kan verwijzen naar concrete test-method-namen voor "acceptance criteria proof" sectie.
- Geen open architectuur-keuzes — Plan 13-04 documenteert wat Plan 13-01 + 13-02 + 13-03 hebben gebouwd.

**Blockers / concerns:** geen.

## Self-Check: PASSED

- File `tests/Feature/Api/V1/Mollie/Connect/StubMollieConnectClient.php`: FOUND
- File `tests/Concerns/StubsMollieConnectClient.php`: FOUND
- File `tests/Feature/Api/V1/Mollie/Connect/ClientLinksTest.php`: FOUND
- File `tests/Feature/Api/V1/Mollie/Connect/OnboardingTest.php`: FOUND
- File `tests/Feature/Api/V1/Mollie/Connect/OrganizationsTest.php`: FOUND
- File `tests/Feature/Api/V1/Mollie/Connect/PermissionsTest.php`: FOUND
- File `tests/Feature/Api/V1/Mollie/Connect/ProfilesTest.php`: FOUND
- File `tests/Feature/Api/V1/Mollie/Connect/TokenResolverIntegrationTest.php`: FOUND
- File `tests/Feature/Api/V1/Mollie/StubMollieClient.php` (modified — capture-property + setAccessToken/setIdempotencyKey overrides): FOUND
- File `tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php` (modified — +3 test-methods): FOUND
- Commit `df51125`: FOUND (`test(13-03): Connect-stub-client + trait met makeMollieResourceWithBody-helper`)
- Commit `7b85b9b`: FOUND (`test(13-03): 5 per-resource feature-tests voor Mollie Connect (17 tests)`)
- Commit `8594a79`: FOUND (`test(13-03): TokenResolverIntegrationTest + StubMollieClient capture + Scramble Connect-tags`)

---
*Phase: 13-mollie-connect-partner-resources*
*Completed: 2026-05-18*
