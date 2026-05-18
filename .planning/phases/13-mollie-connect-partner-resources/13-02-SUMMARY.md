---
phase: 13-mollie-connect-partner-resources
plan: 02
subsystem: api
tags: [mollie, mollie-connect, partner-token, pass-through, sanctum-abilities, php, laravel]

requires:
  - phase: 13-mollie-connect-partner-resources
    plan: 01
    provides: MollieAccessTokenResolver, MissingPartnerTokenException, pass_through_calls.token_type, MollieUpstreamErrorMapper 503-branch
  - phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
    provides: AbstractMolliePassThroughController (referentie-pattern), MollieUpstreamErrorMapper, TokenAbilities, EnsureProviderEnabled middleware
provides:
  - App\Http\Controllers\Api\V1\Mollie\Connect\AbstractMollieConnectPassThroughController (container-resolved MollieApiClient + partner-token-binding + dispatchMollieCall-wrapper + audit met token_type=partner)
  - 5 concrete Connect-controllers (ClientLinks, Onboarding, Organizations, Permissions, Profiles)
  - 2 Form Requests (CreateClientLinkRequest, CreateProfileRequest)
  - Route-group /v1/mollie/connect/* met 9 routes achter auth:sanctum + feature.provider:mollie
  - ConnectRouteRegistrationTest (smoke voor route-prefix + middleware + naming-conventie)
affects: [13-03 (feature-tests per resource), 13-04 (ADR)]

tech-stack:
  added: []
  patterns:
    - "Container-resolved SDK-client (test-affordance vanaf inceptie): app(MollieApiClient::class) i.p.v. new MollieApiClient — tests injecten een spy via $this->app->instance(MollieApiClient::class, $stub)"
    - "Centrale exception-wrapper dispatchMollieCall() in base — wikkelt Mollie\\Api\\Exceptions\\ApiException via MollieExceptionMapper::map() naar Hub-tree, géén try/catch in concrete controllers"
    - "Gescheiden hiërarchie i.p.v. shared parent (D-03): Connect-base dupliceert chirurgisch resourceToArray/collectionToArray uit Phase-5a-base — voorkomt premature abstractie tot ≥3 echt-gedeelde methods overblijven"
    - "Scramble-group via gedeelde #[Group(name: 'Mollie · Connect')]-attribute op alle 5 concrete controllers — D-12 alleen op één naam matchen, géén nested-groups nodig"

key-files:
  created:
    - app/Http/Controllers/Api/V1/Mollie/Connect/AbstractMollieConnectPassThroughController.php
    - app/Http/Controllers/Api/V1/Mollie/Connect/ClientLinksController.php
    - app/Http/Controllers/Api/V1/Mollie/Connect/OnboardingController.php
    - app/Http/Controllers/Api/V1/Mollie/Connect/OrganizationsController.php
    - app/Http/Controllers/Api/V1/Mollie/Connect/PermissionsController.php
    - app/Http/Controllers/Api/V1/Mollie/Connect/ProfilesController.php
    - app/Http/Requests/Api/V1/Mollie/Connect/CreateClientLinkRequest.php
    - app/Http/Requests/Api/V1/Mollie/Connect/CreateProfileRequest.php
    - tests/Feature/Api/V1/Mollie/Connect/ConnectControllerScaffoldTest.php
    - tests/Feature/Api/V1/Mollie/Connect/ConnectRouteRegistrationTest.php
  modified:
    - routes/api.php

key-decisions:
  - "Vendor-method-signatures gevolgd boven plan-interfaces: $client->onboarding->status() (geen ->get()), $client->permissions->list() (geen ->all()) — mollie/mollie-api-php OnboardingEndpointCollection regel 10 + PermissionEndpointCollection regel 36 zijn leidend. Route-paden + Hub-shape blijven exact zoals 13-02-PLAN.md voorschrijft."
  - "Twee TDD-cycli (RED → GREEN) per task gecommit als één feat-commit per task. Reden: de RED-phase tests blijven na GREEN bestaan en zijn integraal onderdeel van de deliverable; ze los committen zou een tussenstate achterlaten waar tests bestaan zonder implementation."
  - "Géén try/catch in concrete controllers — Phase-5a duplicatie (CustomersController regels 36-38 etc.) is bewust niet herhaald: dispatchMollieCall() in de base wrapt alles via één pad. Voordeel: één plek wanneer de mapper-tree groeit."
  - "Edit-block in routes/api.php geplaatst NA Phase-5a Mollie-merchant-routes en VÓÓR account-subscriptions — alle Mollie-routes (merchant + Connect) blijven groepsgewijs bij elkaar voor leesbaarheid in route:list output."

patterns-established:
  - "Connect-pass-through pattern: container-resolved SDK-client + partner-token-resolver + centrale dispatchMollieCall-wrapper + audit met token_type=partner — direct herbruikbaar als template voor toekomstige partner-resources (Mollie OrganizationPartners, Mollie Clients, etc.) zonder code-duplicatie van de error-mapping-laag"
  - "Connect-controllers gebruiken één gedeelde Scramble-Group via een #[Group(...)]-attribute op elke concrete subclass (Scramble groepeert op tag-naam; de base krijgt géén attribute omdat 'ie abstract is)"

requirements-completed: [MOLL-05]

duration: ~26min
completed: 2026-05-18
---

# Phase 13 Plan 02: Mollie Connect-pass-through controllers + route-group Summary

**9 routes onder `/v1/mollie/connect/*` via één Connect-base (container-resolved MollieApiClient + partner-token-binding + centrale dispatchMollieCall-wrapper) en 5 concrete controllers + 2 Form Requests, gescheiden van de Phase-5a-merchant-hiërarchie per D-03 — landt de substrate waarop Plan 13-03 feature-tests draait.**

## Performance

- **Duration:** ~26 min
- **Started:** 2026-05-18T~12:30Z (wave 2 spawn)
- **Completed:** 2026-05-18T12:56Z
- **Tasks:** 2 (beide TDD: RED → GREEN, geen REFACTOR)
- **Files created:** 10 (8 productie + 2 tests)
- **Files modified:** 1 (`routes/api.php`)

## Accomplishments

- `AbstractMollieConnectPassThroughController` levert:
  - `client(Request)` → container-resolved `MollieApiClient` met partner-token + Idempotency-Key-forward
  - `dispatchMollieCall(callable)` → centrale wrapper die `\Mollie\Api\Exceptions\ApiException` via `MollieExceptionMapper::map()` normaliseert naar de Hub-exception-tree zodat `MollieUpstreamErrorMapper` de juiste branch raakt (401→502 `mollie_auth_failed`, 422→`validation_failed`, etc.) — voldoet aan MOLL-05 SC-1
  - `handle()` → ability-guard (D-14) + 415-guard + audit-write met `token_type='partner'`, `account_id=NULL`, `connection_id=NULL`, `partner_token_fingerprint = substr(hash('sha256', $token), 0, 12)` (NULL als token-resolver gooit MissingPartnerTokenException — gebeurt al via SDK-pad)
  - `resourceToArray()` + `collectionToArray()` → chirurgische duplicatie uit Phase-5a-base (D-03), géén shared trait
- 5 concrete controllers (`ClientLinks`/`Onboarding`/`Organizations`/`Permissions`/`Profiles`) — élke SDK-call wrapped via `$this->dispatchMollieCall(fn () => $this->client($r)->...)`, géén lokale try/catch.
- 2 Form Requests (`CreateClientLinkRequest`, `CreateProfileRequest`) met required-velden per Mollie's partner-API-spec (`owner.email`/`owner.givenName`/`owner.familyName`/`name`/`address.*` voor client-links; `name`/`website`/`email` voor profiles).
- Route-group `/v1/mollie/connect/*` met 9 routes achter `auth:sanctum` + `feature.provider:mollie`; géén `resolve.mollie.account` (D-07); naming `api.mollie.connect.<resource>.<action>`.

## Task Commits

Each task was committed atomically (RED + GREEN gebundeld per task — zelfde rationale als Plan 13-01: tests blijven na GREEN bestaan en horen bij de deliverable, los committen creëert een tussenstate met dangling tests):

1. **Task 1: Connect-base + 5 controllers + 2 Form Requests + scaffold-test** — `3e1ba7e` (feat)
2. **Task 2: Route-group + ConnectRouteRegistrationTest** — `e16e19c` (feat)

_TDD-bewijs:_
- Task 1 RED: `ConnectControllerScaffoldTest` → 0/6 passed, 2 failed + 4 errors ("Class App\\Http\\Controllers\\Api\\V1\\Mollie\\Connect\\AbstractMollieConnectPassThroughController does not exist").
- Task 1 GREEN: 6/6 passed, 26 assertions.
- Task 2 RED: `ConnectRouteRegistrationTest::test_all_nine_connect_routes_..._registered` → 0 routes gevonden, expected 9.
- Task 2 GREEN: 3/3 passed, 55 assertions.

## Files Created/Modified

**Created (10):**

| Path | Provides |
|---|---|
| `app/Http/Controllers/Api/V1/Mollie/Connect/AbstractMollieConnectPassThroughController.php` | Connect-base — container-resolved client + partner-token-binding + dispatchMollieCall + audit (token_type=partner) |
| `app/Http/Controllers/Api/V1/Mollie/Connect/ClientLinksController.php` | `POST /v2/client-links` |
| `app/Http/Controllers/Api/V1/Mollie/Connect/OnboardingController.php` | `GET /v2/onboarding/me` (vendor: `->status()`) |
| `app/Http/Controllers/Api/V1/Mollie/Connect/OrganizationsController.php` | `GET /v2/organizations/me` + `GET /v2/organizations/{id}` |
| `app/Http/Controllers/Api/V1/Mollie/Connect/PermissionsController.php` | `GET /v2/permissions` (vendor: `->list()`) + `GET /v2/permissions/{id}` |
| `app/Http/Controllers/Api/V1/Mollie/Connect/ProfilesController.php` | `GET /v2/profiles` + `POST /v2/profiles` + `GET /v2/profiles/{id}` |
| `app/Http/Requests/Api/V1/Mollie/Connect/CreateClientLinkRequest.php` | Form Request voor `POST /v2/client-links` |
| `app/Http/Requests/Api/V1/Mollie/Connect/CreateProfileRequest.php` | Form Request voor `POST /v2/profiles` |
| `tests/Feature/Api/V1/Mollie/Connect/ConnectControllerScaffoldTest.php` | 6 cases — class-existence + base-method-shape + container-resolve + mapper-wiring + audit-token_type + extends-base |
| `tests/Feature/Api/V1/Mollie/Connect/ConnectRouteRegistrationTest.php` | 3 cases — 9 routes geregistreerd + middleware-keten + naam-prefix |

**Modified (1):**

- `routes/api.php`:
  - Lines 9-13: 5 use-statements voor Connect-controllers (aliased met `Connect`-prefix om naam-collision met Phase-5a `CustomersController` etc. te voorkomen — bv. `ClientLinksController as ConnectClientLinksController`).
  - Lines 97-127: nieuwe route-group `Route::prefix('mollie/connect')->middleware(['feature.provider:mollie'])->name('api.mollie.connect.')` met 9 routes, na het Phase-5a Mollie-merchant-block (regel 63-93) en vóór `account-subscriptions` (regel 128+).

## Route-tabel

| # | Method | URI | Name | Controller@Action |
|---|---|---|---|---|
| 1 | GET | `v1/mollie/connect/onboarding/me` | `api.mollie.connect.onboarding.me` | `OnboardingController@me` |
| 2 | GET | `v1/mollie/connect/organizations/me` | `api.mollie.connect.organizations.me` | `OrganizationsController@me` |
| 3 | GET | `v1/mollie/connect/organizations/{id}` | `api.mollie.connect.organizations.show` | `OrganizationsController@show` |
| 4 | GET | `v1/mollie/connect/profiles` | `api.mollie.connect.profiles.index` | `ProfilesController@index` |
| 5 | POST | `v1/mollie/connect/profiles` | `api.mollie.connect.profiles.store` | `ProfilesController@store` |
| 6 | GET | `v1/mollie/connect/profiles/{id}` | `api.mollie.connect.profiles.show` | `ProfilesController@show` |
| 7 | GET | `v1/mollie/connect/permissions` | `api.mollie.connect.permissions.index` | `PermissionsController@index` |
| 8 | GET | `v1/mollie/connect/permissions/{id}` | `api.mollie.connect.permissions.show` | `PermissionsController@show` |
| 9 | POST | `v1/mollie/connect/client-links` | `api.mollie.connect.client-links.store` | `ClientLinksController@store` |

Middleware-keten per route: `api` → `Authenticate:sanctum` → `EnsureProviderEnabled:mollie`. `resolve.mollie.account` afwezig (D-07).

## Vendor-signature-drift t.o.v. plan-interfaces

13-02-PLAN.md regel 148-151 noemt:
- `onboarding->get(): Onboarding` — **vendor heeft `->status(): Onboarding`** (`vendor/mollie/mollie-api-php/src/EndpointCollection/OnboardingEndpointCollection.php:10`). Aangepast in `OnboardingController::me()`.
- `permissions->all(): PermissionCollection` — **vendor heeft `->list(): PermissionCollection`** (`vendor/mollie/mollie-api-php/src/EndpointCollection/PermissionEndpointCollection.php:36`). Aangepast in `PermissionsController::index()`.

De plan-tekst zelf wijst hierop in regel 154 (NB-clausule): "Mocht een endpoint-property of methode-naam in vendor afwijken (versie-drift), check `vendor/mollie/mollie-api-php/src/MollieApiClient.php` voor de public properties en `vendor/mollie/mollie-api-php/src/Endpoints/*Endpoint.php` voor de signatures." → exact gevolgd.

Andere SDK-method-signatures matched het plan exact:
- `clientLinks->create(array $payload): ClientLink` ✓
- `organizations->get(string $id, $testmode = false): Organization` (`'me'` is valide; testmode-default `false`) ✓
- `profiles->page(?string $from = null, ?int $limit = null): ProfileCollection` ✓
- `profiles->create(array $payload): Profile` ✓
- `profiles->get(string $profileId, $testmode = false): Profile` ✓
- `permissions->get(string $permissionId, $testmode = false): Permission` ✓

## Waarom geen shared super-class (D-03 quote)

13-CONTEXT.md §`<decisions>` D-03: "Twee abstract base-controllers, gescheiden hiërarchie. Phase 5a heeft `AbstractMolliePassThroughController` (Connection-scoped). Phase 13 introduceert `AbstractMollieConnectPassThroughController` die: géén `MollieConnectionContext` aanroept; geen `ResolveMollieAccount`-middleware nodig heeft; **Niet** generieke base-class voor beide paden bouwen tenzij na implementatie ≥3 echt-gedeelde methods overblijven; voorkomt premature abstractie."

Gevolg in dit plan: `resourceToArray` + `collectionToArray` zijn bit-voor-bit gedupliceerd uit `AbstractMolliePassThroughController` (Phase 5a regels 134-185). `handle()` deelt 90% van de logica maar verschilt in audit-shape (`token_type='partner'`, `account_id/connection_id=NULL`, `partner_token_fingerprint` i.p.v. `request_fingerprint`-only). Eerste evaluatie-trigger voor consolidatie: wanneer een derde partner-resolver-type (b.v. OAuth `client_credentials` voor Mollie-Connect of een andere provider) een vergelijkbare base zou vereisen — pas dan ≥3 echt-gedeelde methods. Tot dat moment: dubbele code is goedkoper dan een verkeerd geabstraheerde hiërarchie.

## `client()` container-resolution + `dispatchMollieCall()` toelichting

### `client(Request $request): MollieApiClient`

- **Regel 49:** `$client = app(MollieApiClient::class);` — bewust géén `new MollieApiClient()`. Vanaf inceptie is de controller test-injectable via `$this->app->instance(MollieApiClient::class, $stub)` zonder dat Plan 13-03 productiecode moet patchen.
- **Regel 50:** `$client->setAccessToken($this->tokenResolver->resolveFor('partner'));` — wanneer `MOLLIE_PARTNER_ACCESS_TOKEN` niet geconfigureerd is, gooit de resolver `MissingPartnerTokenException` die door `handle()`-catch-all wordt opgepikt → `MollieUpstreamErrorMapper` → 503 `partner_token_missing` (Phase 13-01 leverde die mapper-branch).
- **Regel 52-55:** Idempotency-Key forward identiek aan Phase-5a (`MollieApiClient::setIdempotencyKey()` aanwezig in `vendor/mollie/mollie-api-php/src/Traits/HandlesIdempotency.php:27`).

### `dispatchMollieCall(callable $fn): mixed`

- **Reden in base i.p.v. in elke concrete controller:** `MollieUpstreamErrorMapper` matcht uitsluitend op `Emeq\MollieApi\Exceptions\*` (zie `app/Support/Mollie/MollieUpstreamErrorMapper.php` regels 44+ — `ValidationException`, `AuthenticationException`, `NotFoundException`, etc. zijn allemaal `Emeq\MollieApi\Exceptions\*`). Een raw `\Mollie\Api\Exceptions\ApiException` (b.v. uit een 401-pad) valt door naar de catch-all `mollie_unknown` → 502 — dat **mist** de specifieke 401→502 `mollie_auth_failed`-branch (MOLL-05 SC-1).
- **Implementatie (3 regels):** `try { return $fn(); } catch (MollieApiException $e) { throw MollieExceptionMapper::map($e); }`. De gemapte Hub-exception wordt door `handle()`'s `catch (Throwable $e)` opgepikt en doorgegeven aan `MollieUpstreamErrorMapper::mapException($e)`, die hem dan correct routeert.
- **DRY-winst:** in Phase 5a heeft élke concrete controller een eigen `try { ... } catch (MollieApiException $e) { throw MollieExceptionMapper::map($e); }` (zie `CustomersController` regels 30-38 + 50-56 + 62-70 + `PaymentsController` 3x). In Connect-controllers: **één try/catch in de base**, géén duplicatie in concrete controllers.

## Test-output snippets

**`ConnectControllerScaffoldTest` — 6 tests, 26 assertions:**

```
{"tool":"phpunit","result":"passed","tests":6,"passed":6,"assertions":26,"duration_ms":486}
```

**`ConnectRouteRegistrationTest` — 3 tests, 55 assertions:**

```
{"tool":"phpunit","result":"passed","tests":3,"passed":3,"assertions":55,"duration_ms":463}
```

**Full regression suite — 540/541 passed (1 pre-existing failure):**

```
{"tool":"phpunit","result":"failed","tests":541,"passed":540,"assertions":1899,"duration_ms":22155,"failed":1}
```

De ene failure is `Tests\Feature\Admin\UserResourceTest::test_super_admin_can_create_user_via_resource` — gedocumenteerd in 13-01-SUMMARY.md §"Issues Encountered" als pre-existing Filament-`UserResource`-flow voor Spatie-rollen, gelogd in STATE.md Phase-11-decisions als "SCOPE BOUNDARY, gelogd in deferred-items.md". Niet veroorzaakt door dit plan.

**`vendor/bin/pint --dirty --format agent`:**

```
{"tool":"pint","result":"passed"}
```

## Decisions Made

- **Vendor-method-signatures gevolgd boven plan-interfaces (zie Vendor-signature-drift sectie hierboven):** `onboarding->status()` en `permissions->list()` — plan-NB-clausule (regel 154) staat dit expliciet toe; route-paden + Hub-shape blijven onveranderd.
- **Aliased use-imports in `routes/api.php`:** `ClientLinksController as ConnectClientLinksController` om naam-collision met `App\Http\Controllers\Api\V1\Account*` etc. te voorkomen, niet omdat er een collision is — Connect-controllers leven in een eigen subnamespace. Bewuste keuze om in route-definities expliciet `Connect*Controller` te zien, dat scheelt context-switches bij toekomstige edits aan `routes/api.php`.
- **Geen aparte `ability:`-middleware op de Connect-routes:** ability-guard staat in `AbstractMollieConnectPassThroughController::handle()` (D-14 — Phase-5a-pattern). Plaatsing in de base i.p.v. op de route-definitie geeft één plek om de policy aan te passen. Bewust afgeweken van de pattern in `account-subscriptions` waar `ability:`-middleware wel op route-niveau staat — Phase-5a-precedent voor Mollie-routes weegt zwaarder.
- **`Connect`-prefix-aliassen in route-imports:** voorkomt menselijke verwarring tussen `OrganizationsController` (Connect, partner-token) en hypothetische merchant-`OrganizationsController` (zou in `App\Http\Controllers\Api\V1\Mollie\` staan). Future-proof bij eventuele uitbreiding zonder huidige collision.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Worktree-bootstrap: vendor/ + .env symlinks**

- **Found during:** Task 1 RED-run (test faalde met `vendor/autoload.php` not found).
- **Issue:** Claude Code's worktree-isolation mist `vendor/` en `.env`. Zelfde environment-issue als 13-01.
- **Fix:** `ln -s /Users/.../emeq-hub/vendor` + `ln -s /Users/.../emeq-hub/.env` in worktree-root, daarna `composer dump-autoload -o` om classmap met de nieuwe `App\Http\Controllers\Api\V1\Mollie\Connect\*` te refreshen.
- **Files modified:** geen tracked files; symlinks zijn worktree-lokaal en niet gecommit.
- **Verification:** `php artisan test --compact --filter=ConnectControllerScaffoldTest` exit 0 met 6/6 passed na GREEN.
- **Committed in:** N.v.t. — pure execution-environment, geen code-change.

**2. [Rule 1 - Bug] Vendor-method-signature-drift: `onboarding->get()` en `permissions->all()` bestaan niet**

- **Found during:** Task 1 implementation (Read vendor EndpointCollection-files vóór schrijven controllers).
- **Issue:** Plan 13-02 `<interfaces>` regel 148-151 noemt `onboarding->get(): Onboarding` en `permissions->all(): PermissionCollection`, maar `vendor/mollie/mollie-api-php` v3.x heeft `->status()` resp. `->list()`.
- **Fix:** `OnboardingController::me()` roept `$client->onboarding->status()` aan; `PermissionsController::index()` roept `$client->permissions->list()` aan. Route-paden, Hub-method-names, Hub-shape onveranderd.
- **Plan-acknowledgement:** 13-02-PLAN.md regel 154 staat dit expliciet toe: "Mocht een endpoint-property of methode-naam in vendor afwijken (versie-drift), check `vendor/mollie/mollie-api-php/src/MollieApiClient.php` voor de public properties en `vendor/mollie/mollie-api-php/src/Endpoints/*Endpoint.php` voor de signatures."
- **Files modified:** `app/Http/Controllers/Api/V1/Mollie/Connect/OnboardingController.php` + `PermissionsController.php` — beide hebben een inline comment die de vendor-naam toelicht.
- **Verification:** scaffold-test loopt groen; vendor-method-aanroepen worden in 13-03 met real fakes/spies gevalideerd (assertSent-stijl).
- **Committed in:** `3e1ba7e`.

---

**Total deviations:** 2 auto-fixed (1 Rule 3 - environment-blocker, 1 Rule 1 - vendor-drift documented in plan).
**Impact on plan:** Geen impact op deliverables — alle 10 plan-files landen exact zoals gespecificeerd, vendor-drift was door het plan zelf voorzien.

## Issues Encountered

- **Pre-existing `UserResourceTest::test_super_admin_can_create_user_via_resource` failure** — al gedocumenteerd in 13-01-SUMMARY.md en STATE.md (Phase-11 deferred-items). Niet veroorzaakt door dit plan; volle suite 540/541 passed.

## User Setup Required

Geen externe-service config nodig voor dit plan. Plan 13-03 (feature-tests) heeft `MOLLIE_PARTNER_ACCESS_TOKEN` nodig in test-env voor de happy-path-tests via spies; default `null` triggert de 503-mapping-tests.

## Documentation Drift

`routes/api.php` is gewijzigd → docs-sync skill-trigger: route-tabel in `.docs/decisions/mollie-passthrough-api.md` of een nieuwe `.docs/decisions/mollie-connect-partner-resources.md` (Plan 13-04 owns deze) moet de 9 Connect-routes opnemen. Plan 13-04 schrijft de ADR; deze SUMMARY flagt dat de Phase-13 docs-sync run na merge-back de route-tabel moet opnemen.

## Next Phase Readiness

**Klaar voor Plan 13-03 (feature-tests per resource):**
- Alle 5 Connect-controllers + 9 routes geregistreerd.
- `AbstractMollieConnectPassThroughController::client()` is test-injectable via `$this->app->instance(MollieApiClient::class, $stub)` — geen mid-wave-refactor nodig.
- `dispatchMollieCall()`-wrapper zorgt dat een 401-test (b.v. `Mollie\Api\Exceptions\ApiException` met `getCode()===401`) door `MollieExceptionMapper::map()` heen naar `AuthenticationException` → `MollieUpstreamErrorMapper` → 502 `mollie_auth_failed` gaat (MOLL-05 SC-1 happy-path).
- Form Requests valideren minimaal Mollie's required-velden; 422-tests kunnen tegen `mollie:write`-tokens draaien zonder de SDK te raken.

**Blockers / concerns:** geen.

## Self-Check: PASSED

- File `app/Http/Controllers/Api/V1/Mollie/Connect/AbstractMollieConnectPassThroughController.php`: FOUND
- File `app/Http/Controllers/Api/V1/Mollie/Connect/ClientLinksController.php`: FOUND
- File `app/Http/Controllers/Api/V1/Mollie/Connect/OnboardingController.php`: FOUND
- File `app/Http/Controllers/Api/V1/Mollie/Connect/OrganizationsController.php`: FOUND
- File `app/Http/Controllers/Api/V1/Mollie/Connect/PermissionsController.php`: FOUND
- File `app/Http/Controllers/Api/V1/Mollie/Connect/ProfilesController.php`: FOUND
- File `app/Http/Requests/Api/V1/Mollie/Connect/CreateClientLinkRequest.php`: FOUND
- File `app/Http/Requests/Api/V1/Mollie/Connect/CreateProfileRequest.php`: FOUND
- File `tests/Feature/Api/V1/Mollie/Connect/ConnectControllerScaffoldTest.php`: FOUND
- File `tests/Feature/Api/V1/Mollie/Connect/ConnectRouteRegistrationTest.php`: FOUND
- File `routes/api.php` modified (9 new routes registered): FOUND
- Commit `3e1ba7e`: FOUND (`feat(13-02): Mollie Connect-pass-through base + 5 controllers + 2 form-requests`)
- Commit `e16e19c`: FOUND (`feat(13-02): Mollie Connect route-group + registratie-smoke-test`)

---
*Phase: 13-mollie-connect-partner-resources*
*Completed: 2026-05-18*
