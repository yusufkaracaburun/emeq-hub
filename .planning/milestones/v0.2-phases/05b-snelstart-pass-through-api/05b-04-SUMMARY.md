---
phase: 05b-snelstart-pass-through-api
plan: 04
subsystem: api
tags:
  - laravel
  - api
  - sanctum
  - form-requests
  - api-resources
  - phpunit

# Dependency graph
requires:
  - phase: 03-hub-skeleton
    provides: "Consumer/Account/Connection models + factories + encrypted casts + unique (consumer_id, external_id) + partial unique (account_id, provider) WHERE revoked_at IS NULL"
provides:
  - "POST /v1/accounts (Sanctum-PAT auth, ability-policy: snelstart:write OR mollie:write OR consumer:manage-accounts OR *) — consumer_id altijd uit auth-context"
  - "POST /v1/connections (ability: consumer:manage-accounts OR snelstart:write OR *) — credentials encrypted-at-rest, response bevat enkel id/account_id/provider/status/fingerprint/revoked_at/created_at"
  - "GET /v1/connections/{id} (ability: snelstart:read OR snelstart:write OR consumer:manage-accounts OR *) — cross-Consumer = 404 connection_not_found"
  - "DELETE /v1/connections/{id} (ability: consumer:manage-accounts OR snelstart:write OR *) — zet revoked_at = now() en retourneert 204; dubbel-DELETE = 404 (idempotency-choice)"
  - "App\\Http\\Requests\\Api\\V1\\StoreAccountRequest — external_id + display_name validation"
  - "App\\Http\\Requests\\Api\\V1\\StoreConnectionRequest — Rule::exists('accounts', 'id')->where('consumer_id', $this->user()->id) scoped op huidige Consumer"
  - "App\\Http\\Resources\\Api\\V1\\AccountResource + ConnectionResource (geen raw credentials)"
  - "24 feature-tests (70 assertions) die HUB-05 SC-1, SC-2 en SC-5 (provisioning-deel) afdekken"
affects:
  - 05b-05 (pass-through-route + ResolveSnelstartAccount middleware leunt op POST /v1/accounts + /v1/connections endpoints om Connections in de test-setup te provisionen)
  - 05a (Mollie pass-through; analoog pattern bruikbaar — provider-enum uitbreiden naar 'mollie' en credentials-shape per-provider valideren)
  - 09 (Filament admin-UI; Connection-overzicht kan dezelfde ConnectionResource-shape hergebruiken)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Inline ability-guard via `tokenCan()` + `abort_unless(...)` in private `guardAbility()`-helper per controller — past bij 4 endpoints; geen dedicated `AbilityAnyMiddleware` nodig"
    - "Rule::exists met `->where('consumer_id', ...)` als eerste defense-in-depth-laag tegen cross-Consumer account_id; controller herhaalt scoping voor robuustheid (race-condition + explicit-by-design)"
    - "UniqueConstraintViolationException-catch i.p.v. pre-check `exists()` — race-condition-veilig en respecteert Phase-3 partial-unique-index `connections_account_id_provider_active_unique`"
    - "Cross-Consumer = 404 (niet 403): info-disclosure-veilig consistent met `<gray_areas_resolved>` uit 05b-CONTEXT.md; revoked Connection blijft leesbaar via GET maar 404 op DELETE (idempotency-keuze)"
    - "Primitive int-binding `{connection}` i.p.v. Route Model Binding — voorkomt impliciete 404-pad zonder eigen JSON-envelope; controller doet expliciete `Connection::whereHas('account', ...)->find($id)`"

key-files:
  created:
    - "app/Http/Requests/Api/V1/StoreAccountRequest.php"
    - "app/Http/Requests/Api/V1/StoreConnectionRequest.php"
    - "app/Http/Resources/Api/V1/AccountResource.php"
    - "app/Http/Resources/Api/V1/ConnectionResource.php"
    - "app/Http/Controllers/Api/V1/AccountController.php"
    - "app/Http/Controllers/Api/V1/ConnectionController.php"
    - "tests/Feature/Api/V1/StoreAccountTest.php"
    - "tests/Feature/Api/V1/StoreConnectionTest.php"
    - "tests/Feature/Api/V1/ShowConnectionTest.php"
    - "tests/Feature/Api/V1/DestroyConnectionTest.php"
  modified:
    - "routes/api.php"

key-decisions:
  - "Inline ability-guard via `tokenCan()`+`abort_unless()` in private `guardAbility(Request, list<string>)`-helper per controller (any-of-semantiek); plan suggereerde 'inline' óf dedicated middleware — 4 endpoints rechtvaardigt geen aparte middleware. Plan 05's `PassThroughController` kan dezelfde helper kopieren of een trait introduceren als 3+ controllers het delen."
  - "Connection-route gebruikt primitive int `{connection}` (geen Route Model Binding) zodat de controller eigen JSON-envelope-404 kan returnen i.p.v. Laravel's default model-not-found-html-or-json-default — consistent met `<error_response_format>` uit 05b-CONTEXT.md"
  - "Idempotency-keuze voor DELETE: revoked Connection retourneert 404 op tweede DELETE (revoked = 'weg' voor destroy-doeleinden); GET blijft 200 om audit-transparantie te bewaren — keuze gedocumenteerd in test `test_already_revoked_connection_returns_404_on_second_delete`"
  - "UniqueConstraintViolationException-catch (geen pre-`exists()`-check) — Postgres' SQLSTATE 23505 wordt door Eloquent vertaald, race-condition-veilig"
  - "Geen `declare(strict_types=1)` toegevoegd — Hub-conventie (zelfde keuze als 05b-02 SUMMARY); `grep -rl 'declare(strict_types' app/Http` = 0"

patterns-established:
  - "Provisioning-controller-shell: `guardAbility(...)` helper bovenaan + `UniqueConstraintViolationException`-catch voor 409-paden + `notFound(error, message)` helper voor consistent JSON-envelope — bruikbaar voor toekomstige `/v1/accounts/{id}` PATCH of `/v1/mollie/connections` (Phase 5a)"
  - "Feature-test-namespace `Tests\\Feature\\Api\\V1\\*` voor HTTP-tests op `/v1/*`-routes (sibling van `Tests\\Feature\\Api`); private `consumerWithToken(array $abilities)`-helper per test-class voor DRY token-aanmaak"

requirements-completed:
  - HUB-05  # SC-1 (POST /v1/accounts ✅), SC-2 (POST /v1/connections + encrypted + fingerprint-only ✅), SC-5 (cross-Consumer scoping ✅ voor provisioning; pass-through-deel landt in Plan 05)

# Metrics
duration: ~6 min
completed: 2026-05-14
---

# Phase 05b Plan 04: Provisioning-endpoints (POST /v1/accounts + POST/GET/DELETE /v1/connections) Summary

**Vier provisioning-endpoints (POST /v1/accounts, POST /v1/connections, GET/DELETE /v1/connections/{id}) + 2 Form-Requests + 2 API-Resources + 4 feature-tests die HUB-05 SC-1/SC-2/SC-5 (provisioning) end-to-end bewijzen — Consumers kunnen via Bearer-PAT Accounts aanmaken, Snelstart-credentials vastleggen (encrypted-at-rest, fingerprint-only response), eigen Connections lezen en revoken; cross-Consumer 404 (GET/DELETE) of 422 (POST /v1/connections via Rule::exists).**

## Performance

- **Duration:** ~6 min (start 16:36:55Z, eind 16:42:25Z; 330s wall-clock)
- **Tasks:** 3 (3 auto, geen TDD-task — tests komen na implementatie in Task 3 omdat Form-Requests/Resources/Controllers samen pas testbaar zijn)
- **Files created:** 10 (4 controllers/requests/resources, 4 tests, 2 zijn al meegerekend)
- **Files modified:** 1 (`routes/api.php`)
- **Commits:** 3 atomic (Task 1 → 2 → 3)

## Accomplishments

- 4 nieuwe `/v1/*`-routes registreren in `routes/api.php` onder bestaande `auth:sanctum`-group; route-list toont nu 5 v1-routes (ping + 4 nieuwe)
- AccountController + ConnectionController met inline ability-guard, cross-Consumer scoping, 409-error-paden voor unique-constraint-violations, idempotente revoke
- 2 Form-Requests met scoped `Rule::exists` + per-provider credential-validation (Snelstart in 5b; Mollie 5a kan provider-enum uitbreiden)
- 2 JsonResource-classes waarvan `ConnectionResource` aantoonbaar geen raw credentials exposeert (8 keys gewenst, 5 verboden — alle 5 grep-asserted afwezig)
- 24 feature-tests in `Tests\Feature\Api\V1\*` (7+8+4+5 = 24, >= 23 acceptance-target) — 70 assertions, allemaal groen
- Volledige Hub-suite blijft groen: **77 passed / 1 incomplete (Phase-3 placeholder) / 207 assertions / 876ms**
- Geen wijziging onder `packages/snelstart-api/` (SDK-grens-invariant respected); geen wijziging in `app/Providers/AppServiceProvider.php`

## Task Commits

Elk task atomic gecommit:

1. **Task 1 — Form-Requests + Resources** — `4cb2c6a` (feat)
   `feat(05b-04): voeg StoreAccountRequest/StoreConnectionRequest + AccountResource/ConnectionResource toe`
2. **Task 2 — Controllers + Routes** — `aaef4ab` (feat)
   `feat(05b-04): AccountController + ConnectionController + 4 provisioning-routes`
3. **Task 3 — Feature-tests** — `1423083` (test)
   `test(05b-04): feature-tests voor POST /v1/accounts + POST/GET/DELETE /v1/connections`

## Files Created/Modified

**Created:**

- `app/Http/Requests/Api/V1/StoreAccountRequest.php` — `external_id` + `display_name` validatie; `authorize()` retourneert `true` (ability-check leeft in controller)
- `app/Http/Requests/Api/V1/StoreConnectionRequest.php` — scoped `Rule::exists('accounts', 'id')->where('consumer_id', $this->user()->id)` + per-provider credential-rules
- `app/Http/Resources/Api/V1/AccountResource.php` — `id`, `external_id`, `display_name`, `created_at`
- `app/Http/Resources/Api/V1/ConnectionResource.php` — `id`, `account_id`, `provider`, `status`, `fingerprint`, `revoked_at`, `created_at` (geen `client_key/subscription_key/access_token/refresh_token/subscription_id`)
- `app/Http/Controllers/Api/V1/AccountController.php` — `store()` met `guardAbility()` + `UniqueConstraintViolationException`-catch (409 `account_exists`)
- `app/Http/Controllers/Api/V1/ConnectionController.php` — `store/show/destroy` met scoped Account-lookup, `whereHas('account', ...)` voor show/destroy, idempotente revoke
- `tests/Feature/Api/V1/StoreAccountTest.php` — 7 tests (happy 201, 3 ability-paths, 403, 422, 409, 401)
- `tests/Feature/Api/V1/StoreConnectionTest.php` — 8 tests (encrypted-at-rest, fingerprint-only, ability-paths, cross-Consumer 422 via `Rule::exists`, duplicate-active 409, revoked-allowed, validation 422)
- `tests/Feature/Api/V1/ShowConnectionTest.php` — 4 tests (own 200, cross-Consumer 404 `connection_not_found`, ability 403, revoked nog leesbaar)
- `tests/Feature/Api/V1/DestroyConnectionTest.php` — 5 tests (revoke→204+`revoked_at`, cross-Consumer 404, dubbel-DELETE 404, ability 403, persistentie via `DB::table`)

**Modified:**

- `routes/api.php` — 4 nieuwe `Route::post/get/delete` regels onder bestaande `auth:sanctum`-group, met named routes (`api.accounts.store`, `api.connections.store/show/destroy`); 2 nieuwe imports

## Decisions Made

1. **Inline ability-guard per controller via `guardAbility(Request, list<string>)`-helper** — 4 endpoints rechtvaardigen geen dedicated middleware (`AbilityAnyMiddleware`). Plan 05's `PassThroughController` kan dezelfde helper kopieren of we extracten naar trait wanneer 3+ controllers het delen. Past bij `.ai/rules/engineering.md` "chirurgisch wijzigen".
2. **Primitive int-binding `{connection}` i.p.v. Route Model Binding** — voorkomt impliciete 404-pad zonder eigen JSON-envelope; controller doet `Connection::whereHas('account', fn ($q) => $q->where('consumer_id', $consumerId))->find($id)` en retourneert `notFound('connection_not_found', ...)` bij null. Match `<error_response_format>` uit 05b-CONTEXT.md.
3. **Idempotency voor DELETE = "revoked is weg"** — tweede DELETE op een revoked Connection retourneert 404 (zelfde info-disclosure-policy als cross-Consumer); GET blijft 200 zodat audit-transparantie behouden blijft. Test 3 in `DestroyConnectionTest` + test 4 in `ShowConnectionTest` documenteren dit.
4. **`UniqueConstraintViolationException`-catch i.p.v. pre-`exists()`-check** — race-condition-veilig, vertrouwt op Postgres SQLSTATE 23505 via Eloquent-vertaling. Werkt voor zowel `accounts (consumer_id, external_id)` unique als de partial unique-index op `connections (account_id, provider) WHERE revoked_at IS NULL` uit Phase 3.
5. **Cross-Consumer `account_id` in POST /v1/connections = 422 (Rule::exists fail), niet 404** — bewust, en gedocumenteerd in `<gray_areas_resolved>` van 05b-CONTEXT.md. Semantically equivalent met de 404 op show/destroy: de Consumer leert niet of het Account bestaat-maar-niet-van-mij, alleen "account_id is invalid". Test 5 in `StoreConnectionTest` valideert het pad.
6. **Geen `declare(strict_types=1)`** — Hub-conventie (`grep -rl 'declare(strict_types' app/Http` = 0). Consistent met 05b-02 keuze.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Worktree-bootstrap (vendor + .env)**
- **Found during:** Initial setup, vóór Task 1
- **Issue:** Worktree spawned zonder `vendor/` of `.env` — `composer install`/`php artisan test` faalde anders direct.
- **Fix:** `cp /Users/yusufkaracaburun/Sites/localhost/emeq-hub/.env .env` (read-only consumptie van main-tree, geen modificatie aan main-tree) + `composer install --no-interaction` lokaal in de worktree. `vendor/` is gitignored — geen invloed op committed artifacts. Geen cross-tree symlinks gemaakt (per parallel_execution-notice in prompt).
- **Files affected:** `vendor/` + `.env` (beide gitignored)
- **Verification:** `php artisan test --compact` (baseline) → 53 passed / 1 incomplete / 137 assertions vóór enige plan-action.
- **Committed in:** geen commit (working-copy-only)

**2. [Rule 2 - Critical functionality] Defense-in-depth Account-lookup in `ConnectionController::store`**
- **Found during:** Task 2 plan-action lezen
- **Issue:** Plan beschreef Account-scoping zowel via `Rule::exists` (Form-Request) als via `Account::where('consumer_id', ...)->findOrFail($id)` in de controller, maar zei "extra defense-in-depth bovenop `Rule::exists`". Form-Request gooit 422 bij cross-Consumer, controller-pad zou via `findOrFail` → `ModelNotFoundException` → standaard 404 zonder JSON-envelope leiden. Voor consistent error-shape moet de controller een `try/catch (ModelNotFoundException)` met eigen `notFound('account_not_found', ...)`-respons doen.
- **Fix:** Toegevoegd in `ConnectionController::store`: `try { Account::where('consumer_id', $consumerId)->findOrFail($request->integer('account_id')) } catch (ModelNotFoundException) { return $this->notFound('account_not_found', ...) }`. In de praktijk is dit pad onbereikbaar omdat `Rule::exists` al 422 produceert vóór de controller wordt geraakt — maar het is correctness-preserving wanneer een toekomstige plan-wijziging de Form-Request-scoping zou versoepelen.
- **Files modified:** `app/Http/Controllers/Api/V1/ConnectionController.php` (Task 2 commit `aaef4ab`)
- **Verification:** `php artisan test` blijft groen; Test 5 van `StoreConnectionTest` (`test_cross_consumer_account_id_returns_422_via_rule_exists`) bewijst dat de 422-laag eerder afschiet dan de controller-404-laag.
- **Committed in:** `aaef4ab`

---

**Total deviations:** 2 (1 worktree-bootstrap zonder commit-impact, 1 defense-in-depth-fix die het plan al implicitly vroeg). **Impact on plan:** geen scope-creep, alle acceptance-criteria gehaald.

## Issues Encountered

- **Docs-drift trigger op `routes/api.php`-edit (Task 2):** PostToolUse-hook signaleerde mogelijk `.docs/`-drift omdat route-definitie wijzigde. Note doorgegeven aan SUMMARY's `Next Phase Readiness`-sectie; de orchestrator of een `/gsd-quick` na merge kan `docs-sync` skill draaien (zelfde patroon als 05b-01, 05b-03). Niet binnen plan-execute-scope.

## User Setup Required

None — geen externe service-configuratie, geen `.env`-mutaties, geen DB-migrations toegevoegd in dit plan. Het Phase-3 unique-index op `(account_id, provider) WHERE revoked_at IS NULL` is voldoende voor het 409-pad.

## Verification

**Task 1 (Form-Requests + Resources):**
- ✅ `class StoreAccountRequest extends FormRequest` aanwezig
- ✅ `class StoreConnectionRequest extends FormRequest` aanwezig
- ✅ `class AccountResource extends JsonResource` aanwezig
- ✅ `class ConnectionResource extends JsonResource` aanwezig
- ✅ `fingerprint` voorkomt 1× in `ConnectionResource.php`
- ✅ `client_key|subscription_key|access_token|refresh_token` voorkomt 0× in `ConnectionResource.php`
- ✅ `Rule::exists` voorkomt 1× in `StoreConnectionRequest.php`
- ✅ `php -l` clean voor alle 4 files
- ✅ `vendor/bin/pint --test --dirty` passed

**Task 2 (Controllers + Routes):**
- ✅ `class AccountController extends Controller` aanwezig
- ✅ `class ConnectionController extends Controller` aanwezig
- ✅ `TokenAbilities::` voorkomt 4× in `AccountController` (>= 3)
- ✅ `TokenAbilities::` voorkomt 10× in `ConnectionController` (>= 3)
- ✅ `account_exists|connection_exists|connection_not_found` voorkomt 1+3 = 4× in beide controllers (>= 3)
- ✅ `UniqueConstraintViolationException` voorkomt 2× in beide (>= 2 import-en-catch per file)
- ✅ `whereHas|->user()->accounts()` voorkomt 1× in `ConnectionController` (>= 1)
- ✅ `php artisan route:list --path=v1 --except-vendor` toont exact 5 routes: ping + accounts.store + connections.store/show/destroy
- ✅ `php -l` clean voor beide controllers

**Task 3 (Feature-tests):**
- ✅ `test_` voorkomt 7× in `StoreAccountTest` (>= 6)
- ✅ `test_` voorkomt 8× in `StoreConnectionTest` (>= 8)
- ✅ `test_` voorkomt 4× in `ShowConnectionTest` (>= 4)
- ✅ `test_` voorkomt 5× in `DestroyConnectionTest` (>= 5)
- ✅ Totaal 24 test-cases (>= 23)
- ✅ `CK-test|SK-test|access_token_` voorkomt 2× in `StoreConnectionTest` (>= 1 — test-fixtures voor encrypted-at-rest-bewijs)
- ✅ `php artisan test --compact --filter='StoreAccountTest|StoreConnectionTest|ShowConnectionTest|DestroyConnectionTest'` → **24 passed / 70 assertions / 637ms**
- ✅ Volledige Hub-suite: **77 passed / 1 incomplete (Phase-3 placeholder) / 207 assertions / 876ms**

**Overall:**
- ✅ Pint clean op alle gewijzigde files (`vendor/bin/pint --dirty --format agent` → passed)
- ✅ Geen wijziging onder `packages/snelstart-api/`
- ✅ Geen wijziging in `app/Providers/AppServiceProvider.php`

## Threat Flags

Geen nieuwe trust-boundaries die niet al in `<threat_model>` van het plan staan. Alle 6 mitigaties (T-05b-12 t/m T-05b-17) zijn afgedekt:

| Threat ID | Mitigation Status | Test |
|-----------|-------------------|------|
| T-05b-12 (Spoofing cross-Consumer account_id) | ✅ mitigated via `Rule::exists` + controller-`where('consumer_id', ...)` | `StoreConnectionTest::test_cross_consumer_account_id_returns_422_via_rule_exists` |
| T-05b-13 (Info-disclosure cross-Consumer GET/DELETE) | ✅ 404 i.p.v. 403, consistente `connection_not_found` | `ShowConnectionTest::test_other_consumers_connection_returns_404...` + `DestroyConnectionTest::test_other_consumers_connection_returns_404...` |
| T-05b-14 (Raw credentials in response) | ✅ `ConnectionResource` whitelist; geen `client_key/subscription_key/access_token/refresh_token` | `StoreConnectionTest::test_creates_snelstart_connection...` (key-presence-check) + `test_response_never_contains_raw_credentials` (`assertDontSeeText`) |
| T-05b-15 (Tampering consumer_id in body) | ✅ `consumer_id` niet in Form-Request rules; controller schrijft `$request->user()->accounts()->create(...)` | impliciet via `AccountController::store` + mass-assignment `#[Fillable]` op Account excludeert `consumer_id` niet, maar de write-pad zet 'm via relatie |
| T-05b-16 (EOP `snelstart:read`-PAT doet POST /v1/connections) | ✅ inline `tokenCan` + `abort_unless` → 403 | `StoreConnectionTest::test_token_without_required_ability_returns_403` |
| T-05b-17 (Repudiation revoke geen audit-trail) | ✅ accept — `revoked_at`-timestamp staat op de rij; aparte audit-tabel niet nodig | n.v.t. (geaccepteerd risk) |

Geen extra threat_flags voor de orchestrator-verifier.

## Self-Check: PASSED

**Files exist (worktree filesystem):**
- ✅ `app/Http/Requests/Api/V1/StoreAccountRequest.php`
- ✅ `app/Http/Requests/Api/V1/StoreConnectionRequest.php`
- ✅ `app/Http/Resources/Api/V1/AccountResource.php`
- ✅ `app/Http/Resources/Api/V1/ConnectionResource.php`
- ✅ `app/Http/Controllers/Api/V1/AccountController.php`
- ✅ `app/Http/Controllers/Api/V1/ConnectionController.php`
- ✅ `tests/Feature/Api/V1/StoreAccountTest.php`
- ✅ `tests/Feature/Api/V1/StoreConnectionTest.php`
- ✅ `tests/Feature/Api/V1/ShowConnectionTest.php`
- ✅ `tests/Feature/Api/V1/DestroyConnectionTest.php`
- ✅ `routes/api.php` (modified)

**Commits exist in git log:**
- ✅ `4cb2c6a` — `feat(05b-04): voeg StoreAccountRequest/StoreConnectionRequest + AccountResource/ConnectionResource toe`
- ✅ `aaef4ab` — `feat(05b-04): AccountController + ConnectionController + 4 provisioning-routes`
- ✅ `1423083` — `test(05b-04): feature-tests voor POST /v1/accounts + POST/GET/DELETE /v1/connections`

## Next Phase Readiness

- **Plan 05b-05 (Wave 3 — pass-through-route + `ResolveSnelstartAccount`-middleware + audit-write):** kan starten zodra de andere Wave 2-plans (05b-04 = dit plan) landen. `routes/api.php` wordt opnieuw aangeraakt voor `Route::any('/snelstart/{path}', ...)`-catch-all + middleware-alias. De `guardAbility(...)`-helper kan worden hergebruikt in `PassThroughController` (Plan 05 kiest of die naar een trait gaat zodra het 3× voorkomt).
- **`SanctumAbilityTest::test_token_without_required_ability_is_rejected` (Phase 3 incomplete-placeholder):** kan nu in Plan 05 ingevuld worden tegen `POST /v1/accounts` met een PAT die alleen `snelstart:read` heeft — `StoreAccountTest::test_token_without_required_ability_returns_403` bewijst het 403-pad al; de placeholder kan blijven staan voor de pass-through-route-specifieke ability-middleware óf `markTestSkipped` worden met verwijzing naar `StoreAccountTest`.
- **HUB-05 SC-1, SC-2 en SC-5 (provisioning-deel):** ✅ end-to-end bewezen via 24 feature-tests in dit plan. SC-3 (`GET /v1/snelstart/echo/ping` proxied → bewijst resolver-binding) en SC-4 (`GET /v1/snelstart/relaties?$top=5`) wachten op Plan 05.
- **Docs-sync follow-up:** `routes/api.php` aangepast (4 nieuwe routes). Hook signaleerde docs-drift. `docs-sync` skill kan na merge worden gedraaid om `.docs/README.md`-index en `CLAUDE.md`-route-listing up-to-date te houden (regel: "`routes/api.php` /v1/* — consumer-API"). Niet binnen plan-execute-scope.
- **No blockers** voor de overige Wave 2-plans of Wave 3 (Plan 05).

## TDD Gate Compliance

Plan-type is `execute` (geen plan-level `tdd`). Alle 3 tasks zijn met `tdd="true"` gemarkeerd in het plan, maar het oorspronkelijke task-1 zegt expliciet **"Geen test in deze task — Form-Requests en Resources worden indirect getest door de controller-feature-tests in Task 3"**. Task 2 doet hetzelfde: tests in Task 3 dekken het gedrag af. Daarom geen RED→GREEN-paar per Task 1/2; in plaats daarvan één test-commit (Task 3) na de implementatie-commits.

Reden voor afwijking van strikte TDD-gate: Form-Requests + Resources zijn data-transformaties zonder zelfstandig gedrag; ze worden alleen meaningful wanneer de controller ze instantieert. De tests in Task 3 testen end-to-end (POST /v1/accounts → 201) wat impliciet alle 3 lagen valideert (Form-Request rules, Controller logic, Resource shape). Dit is hetzelfde patroon als Phase 3-03 (`PingController` + `PingTest` in opeenvolgende commits zonder RED-eerst).

Geen TDD-gate-faal: de tests zouden allemaal failed zijn vóór Task 2's controller-commit (geen route, geen controller); Task 2 zonder Task 3 = no tests; Task 3 zonder Task 2 = no routes. De atomic-task-volgorde respecteert het feit dat tests routes nodig hebben die alleen Task 2 levert.

---

*Phase: 05b-snelstart-pass-through-api*
*Plan: 04*
*Completed: 2026-05-14*
