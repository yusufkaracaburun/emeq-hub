---
phase: 07-account-level-subscriptions-use-case-b
plan: 04
subsystem: api
tags: [http-api, form-request, resource, controllers, ability-gating, cross-consumer-scope, mollie, account-subscriptions, audit, scramble]

# Dependency graph
requires:
  - phase: 07-account-level-subscriptions-use-case-b
    provides: AccountSubscription model + factory + migration (plan 07-01)
  - phase: 07-account-level-subscriptions-use-case-b
    provides: SubscriptionStatus enum + InvalidStateTransitionException (plan 07-02)
  - phase: 07-account-level-subscriptions-use-case-b
    provides: AccountSubscriptionManager service + CreateAccountSubscriptionDto (plan 07-03)
  - phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
    provides: MollieUpstreamErrorMapper + pass_through_calls audit-tabel + Sanctum-PAT-pattern
provides:
  - "POST /v1/account-subscriptions met D-09 body-shape + Idempotency-Key forward"
  - "GET /v1/account-subscriptions?account_external_id=X met cross-Consumer-scope (info-disclosure-protectie via lege list)"
  - "GET /v1/account-subscriptions/{id} met optioneel ?resync=1"
  - "DELETE /v1/account-subscriptions/{id} → manager.cancel → 204"
  - "POST /v1/account-subscriptions/{id}/pause + .../resume Hub-only state-flips"
  - "CreateAccountSubscriptionRequest met 11 D-09 rules incl. Rule::exists('accounts','external_id')->where('consumer_id',$consumerId)"
  - "AccountSubscriptionResource met enum->value + Iso8601 timestamps + nested amount"
  - "HandlesAccountSubscriptionRequests-trait dedupliceert findOwnedSubscription/notFound/stateConflict/mollieError/auditCall"
  - "Route-stack-smoke-test (2 tests, 11 assertions) pin't 6 route-names + auth+ability-middleware + 401-bij-geen-PAT"
affects:
  - 07-06-PLAN.md (feature-tests consumeren deze endpoints end-to-end)
  - 07-08-PLAN.md (Scramble OpenAPI op /docs/api pikt deze routes automatisch op)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Route-level ability-alias `ability:mollie:write,*` (write) + `ability:mollie:read,mollie:write,*` (read); repo-consistent (LOW #6)"
    - "Cross-Consumer-scope helper via trait — `whereHas('account', fn ($q) => $q->where('consumer_id', $consumerId))`; 404 op miss (T-07-04-01, D-12)"
    - "Info-disclosure-protectie op index: vreemde-Consumer-account → lege list i.p.v. 404 (mirrort Phase 5a pass-through-pattern)"
    - "@header PHPDoc op store() voor Scramble-zichtbaarheid van Idempotency-Key (LOW #7)"
    - "Mollie ApiException → MollieUpstreamErrorMapper::mapException → cloak 401 → 502 (T-07-04-05)"
    - "InvalidStateTransitionException → 409 Conflict met `error_code: invalid_state_transition` + `from`/`to` enum-values"

key-files:
  created:
    - "app/Http/Requests/Api/V1/AccountSubscriptions/CreateAccountSubscriptionRequest.php"
    - "app/Http/Resources/Api/V1/AccountSubscriptionResource.php"
    - "app/Http/Controllers/Api/V1/AccountSubscriptions/AccountSubscriptionController.php"
    - "app/Http/Controllers/Api/V1/AccountSubscriptions/PauseController.php"
    - "app/Http/Controllers/Api/V1/AccountSubscriptions/ResumeController.php"
    - "app/Http/Controllers/Api/V1/AccountSubscriptions/Concerns/HandlesAccountSubscriptionRequests.php"
    - "tests/Feature/Api/V1/AccountSubscriptions/RouteRegistrationSmokeTest.php"
  modified:
    - "routes/api.php — 6 nieuwe routes onder /v1/account-subscriptions + 3 use-imports"

key-decisions:
  - "Stub-controllers in Task 1-commit (Rule 3 - Blocking auto-fix): route-registration faalt op `__invoke`-existence-check voor single-action controllers vóór de controller-class bestaat; Task 1 levert minimal-viable stubs (501 not_implemented), Task 2 vervangt body's in dezelfde plan-execute-run"
  - "auditCall skip't write als account_id null is — pass_through_calls.account_id is NOT NULL FK; voorkomt Postgres-FK-constraint-violation bij very-early-bail-paths (geen audit-rij is acceptabel; mass-side-channel-info kunnen we niet meer leveren als de account-resolutie zelf al faalde vóór de validatie wist op te lopen)"
  - "Audit-trait gebruikt huidig pass_through_calls-schema (geen direction/query_keys/event_id kolommen) — model's Fillable houdt die velden in reserve voor toekomstige schema-uitbreidingen"
  - "Cross-Consumer-scope op mutate-endpoints = per-Consumer (whereHas('account', consumer_id-check)) — bewust gekozen scope-niveau zoals plan 07-04 §findOwnedSubscription specificeert. Verifieerd via plan 07-06's feature-tests bij phase-close"
  - "Index-route levert lege list voor unknown account_external_id i.p.v. 404 — voorkomt info-disclosure of cross-Consumer een account met die external_id bestaat"

patterns-established:
  - "Pattern 1 — Trait-deduplicatie voor controller-helpers: `Concerns/HandlesAccountSubscriptionRequests` voor 5 gedeelde helpers tussen resource-controller en 2 single-action controllers. Wordt direct herbruikbaar voor andere /v1/* domeinen met cross-Consumer-scope-eisen"
  - "Pattern 2 — Manager-DI in constructor van controllers; geen statische Mollie-call vanuit controller-laag (manager bezit MollieConnectionContext::set + idempotency-forward + state-machine)"
  - "Pattern 3 — Scramble-header-PHPDoc voor request-headers die niet in body-validatie zitten (`@header Idempotency-Key string optional ...`)"
  - "Pattern 4 — Form Request met cross-Consumer Rule::exists is canonical: write-edge-validatie + uniforme 422 voorkomt 404-vs-422-disclosure"

requirements-completed: [SUB-02]

# Metrics
duration: ~28min
completed: 2026-05-15
---

# Phase 07 Plan 04: HTTP-laag voor /v1/account-subscriptions Summary

**6 nieuwe `/v1/account-subscriptions/*` routes geland met cross-Consumer-scoping (D-12), Mollie-error-mapping (D-23), state-conflict-409 op illegal transitions, Idempotency-Key forward (D-14, @header PHPDoc voor Scramble) en pass_through_calls-audit (D-21); trait-deduplicatie van 5 helpers tussen 3 controllers; route-smoke-test bewijst middleware-stack + 401-bij-geen-PAT.**

## Performance

- **Duration:** ~28 min
- **Started:** 2026-05-15T~16:30Z
- **Completed:** 2026-05-15T~16:58Z
- **Tasks:** 2/2
- **Files modified:** 8 (7 created, 1 modified)
- **Commits:** 2 atomic

## Accomplishments

- 6 D-08 routes geregistreerd onder `/v1/account-subscriptions` met repo-consistent `ability:`-alias:
  - `POST /` + `DELETE /{id}` + `POST /{id}/pause` + `POST /{id}/resume` → `ability:mollie:write,*`
  - `GET /` + `GET /{id}` → `ability:mollie:read,mollie:write,*`
- `CreateAccountSubscriptionRequest` valideert alle 11 D-09 body-velden (incl. EUR-only currency, `amount.value`-regex met exact 2 decimals (T-07-04-06), `cst_`/`mdt_`-prefix-check, en cross-Consumer `Rule::exists` op `accounts.external_id` (T-07-04-03)).
- `AccountSubscriptionResource` retourneert D-03 shape met enum→value voor status + Iso8601 timestamps + nested `amount` object.
- `AccountSubscriptionController` orchestreert store/index/show/destroy met `AccountSubscriptionManager`-DI, Idempotency-Key forward (D-14), `MollieUpstreamErrorMapper`-cloak (T-07-04-05), `InvalidStateTransitionException` → 409 (D-23), en `pass_through_calls`-audit (D-21).
- `PauseController` + `ResumeController` als single-action `__invoke`-controllers voor de Hub-only state-flips.
- `HandlesAccountSubscriptionRequests`-trait dedupliceert 5 helpers (`findOwnedSubscription`/`notFound`/`stateConflict`/`mollieError`/`auditCall`) over de 3 controllers.
- `@header Idempotency-Key`-PHPDoc op `store()` zodat Scramble (plan 07-08) de header in `/docs/api` toont.
- Route-registration-smoke-test (2 tests, 11 assertions): 6 named routes geregistreerd + middleware-stack op write- en read-route + 401-bij-geen-PAT.
- Volledige test-suite groen: **298 passed / 975 assertions / 1 pre-existing incomplete** (+2 nieuwe tests t.o.v. plan 07-03's 296). Geen regressie op Phase 5a/6/07-01..03.

## Task Commits

1. **Task 1: Form Request + Resource + 6 routes + 3 stub-controllers + smoke-test** — `68b2b5e` (feat)
2. **Task 2: Controllers + dedupliceer-trait** — `f07c033` (feat)

## Files Created/Modified

- `app/Http/Requests/Api/V1/AccountSubscriptions/CreateAccountSubscriptionRequest.php` (created) — 11 D-09 rules + NL-messages + cross-Consumer Rule::exists.
- `app/Http/Resources/Api/V1/AccountSubscriptionResource.php` (created) — D-03 response-shape met enum→value + Iso8601.
- `app/Http/Controllers/Api/V1/AccountSubscriptions/AccountSubscriptionController.php` (created stub in Task 1 → vol body in Task 2) — store/index/show/destroy + `@header Idempotency-Key` PHPDoc + lege-list-index voor unknown account_external_id (info-disclosure-protectie).
- `app/Http/Controllers/Api/V1/AccountSubscriptions/PauseController.php` (created stub in Task 1 → vol body in Task 2) — single-action `__invoke` voor Active → Paused.
- `app/Http/Controllers/Api/V1/AccountSubscriptions/ResumeController.php` (created stub in Task 1 → vol body in Task 2) — single-action `__invoke` voor Paused → Active.
- `app/Http/Controllers/Api/V1/AccountSubscriptions/Concerns/HandlesAccountSubscriptionRequests.php` (created in Task 2) — trait met 5 helpers.
- `tests/Feature/Api/V1/AccountSubscriptions/RouteRegistrationSmokeTest.php` (created) — 2 tests / 11 assertions.
- `routes/api.php` (modified) — 6 nieuwe routes + 3 use-imports.

## Decisions Made

- **Stub-controllers in Task 1.** Plan-volgorde was Task 1 = routes + smoke-test, Task 2 = controllers. Maar Laravel's `RouteAction::makeInvokable` doet een `method_exists($action, '__invoke')` check bij route-registration voor single-action-routes — dat dwingt `PauseController`/`ResumeController` om al te bestaan vóór Task 1's smoke-test groen kan. Auto-fix Rule 3 - Blocking: maak in Task 1 minimal-viable stubs die 501 `not_implemented` retourneren; Task 2 vervangt de bodies. Beide stappen blijven atomair per plan-design.
- **auditCall skip bij `account_id===null`.** Het `pass_through_calls`-schema (5a-migration) heeft `account_id` als NOT NULL FK. In zeldzame foutpaden (validatie-failure vóór account-resolutie) is `$accountId` null; in die paden laten we de audit-rij vallen i.p.v. een Postgres-FK-violation te triggeren. Trade-off: minder audit-coverage op early-bail-paths, geen schema-change voor v0.2.
- **Per-Consumer scope op mutate-endpoints (niet per-Account).** Plan vraagt expliciet om `whereHas('account', fn($q) => $q->where('consumer_id', $consumerId))`-helper; pause/resume/destroy op een sub van Account A binnen Consumer X is allowed wanneer de PAT van Consumer X ook Account B host. Cross-Consumer = 404. Plan 07-06's feature-test `pause_on_subscription_of_other_account_same_consumer_returns_200` zal de scope-keuze pinnen.
- **Index lege list voor unknown external_id.** Voorkomt info-disclosure of cross-Consumer een account met die external_id bestaat; mirror van Phase 5a pass-through-pattern.
- **Audit-row schema-conform.** `pass_through_calls` heeft (5a-shape) géén `direction`/`query_keys`/`event_id`-kolommen; trait gebruikt alleen actuele kolommen. PassThroughCall-model's Fillable houdt extra velden in reserve voor toekomstige schema-uitbreidingen (5a heeft die als forward-compatibility geplaatst).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Stub-controllers verschoven naar Task 1**
- **Found during:** Task 1 (eerste `php artisan route:list` na route-toevoeging)
- **Issue:** Laravel's `RouteAction::makeInvokable` gooit `UnexpectedValueException: Invalid route action: [PauseController]` bij route-registration omdat `method_exists($action, '__invoke')` faalt op een nog-niet-bestaande controller-class. Plan beoogt single-actions in Task 2 te maken, maar dan kan Task 1's smoke-test niet groen committed worden.
- **Fix:** Minimal-viable stub-controllers gemaakt in Task 1 (501 `not_implemented` payload) voor alle 3 controllers; Task 2 vervangt de body's. Plan-architectuur blijft intact (Form Request + Resource + routes + smoke = Task 1, controller-logica + trait = Task 2), alleen het tijdstip van het controller-file-bestaan schuift een task vroeger.
- **Files modified:** `app/Http/Controllers/Api/V1/AccountSubscriptions/{AccountSubscriptionController,PauseController,ResumeController}.php` (stub-files in Task 1).
- **Verification:** Task 1 smoke-test 2/2 groen, Task 2 vervangt en suite blijft 298 passed.
- **Committed in:** `68b2b5e` (Task 1 stubs) + `f07c033` (Task 2 vol body).

**2. [Rule 3 - Blocking] Vendor + .env ontbraken in worktree**
- **Found during:** Pre-Task 1 (geen vendor/autoload.php)
- **Issue:** Worktree spawn levert geen `vendor/` of `.env`; alle artisan-commands en tests zouden falen. Plans 07-01 en 07-03 SUMMARY documenteren dezelfde trap.
- **Fix:** `composer install --no-interaction` + `cp .env.example .env && php artisan key:generate`. Geen tracked-files-changes (vendor/ + .env zijn ge-`.gitignore`'d).
- **Verification:** Baseline `AccountSubscriptionManager`-tests 8/8 groen vóór Task 1.
- **Committed in:** N.v.t. — worktree-setup, geen tracked-files.

**3. [Rule 1 - Bug] Audit-trait kolommen aangepast aan actueel schema**
- **Found during:** Task 2 (trait-skeleton write)
- **Issue:** Plan-tekst noemde `direction`, `query_keys` en `event_id` als kolommen op `pass_through_calls`. De `2026_05_15_000001_create_pass_through_calls_table`-migration heeft die kolommen echter niet (alleen `consumer_id/account_id/connection_id/provider/method/path/status/duration_ms/request_fingerprint/response_size_bytes/upstream_error/created_at`). `PassThroughCall::create([...])` met onbekende kolommen had Postgres-fouten gegeven.
- **Fix:** `auditCall()`-trait gebruikt alleen kolommen die in de migration staan. Model's `Fillable`-attribute houdt extra velden in reserve voor toekomstige schema-uitbreidingen — geen schema-change nodig voor v0.2.
- **Files modified:** `app/Http/Controllers/Api/V1/AccountSubscriptions/Concerns/HandlesAccountSubscriptionRequests.php`
- **Verification:** Volledige suite blijft 298 passed; geen Postgres-fout-pad in test-coverage.
- **Committed in:** `f07c033` (Task 2).

---

**Total deviations:** 3 auto-fixes (1 task-volgorde fix, 1 worktree-setup, 1 schema-correctie). Geen architecturele afwijking, geen scope-creep.

## TDD Gate Compliance

Plan 07-04 is `type: execute` zonder `tdd="true"` per-task aanduiding — strikt-TDD-gate niet van toepassing. Smoke-test in Task 1 is wel geschreven vóór de controller-stubs werden ingevuld, dus de route-registration werd RED-bewezen (UnexpectedValueException vóór stub-files) → GREEN na stub-toevoeging. Behavior-tests staan in plan 07-06 zoals plan expliciet aangeeft (parallelisatie + context-budget).

## Threat Surface Scan

Geen nieuwe trust-boundary-surface buiten het `<threat_model>` van het plan. Alle 8 STRIDE-rijen zijn ge-mitigate of accepted zoals gepland:

- T-07-04-01 (cross-Consumer-sub-read) — `findOwnedSubscription` joins `whereHas('account', consumer_id-check)`; 404 op miss. Plan 07-06 test bewijst dit end-to-end.
- T-07-04-02 (read-token kan write) — Route-level `ability:mollie:write,*` op write-routes; Sanctum CheckForAnyAbility gooit 403. Plan 07-06 test bewijst dit.
- T-07-04-03 (cross-Consumer account_external_id) — `Rule::exists('accounts','external_id')->where('consumer_id',$consumerId)` retourneert uniforme 422 ongeacht of het account bij andere Consumer bestaat.
- T-07-04-04 (mass-assignment via body) — Form Request `validated()` is whitelist; manager gebruikt `CreateAccountSubscriptionDto` met expliciete props; geen `fill($request->all())`.
- T-07-04-05 (Mollie 401 lekt access-token-state) — `MollieUpstreamErrorMapper` cloak't 401 → 502 met generic message.
- T-07-04-06 (amount.value-tampering) — Regex `/^\d+\.\d{2}$/` blokkeert negatieve + multi-decimal waarden vóór Mollie-roundtrip.
- T-07-04-07 (Idempotency-Key replay) — Manager forward't naar Mollie SDK (plan 07-03 D-14); Mollie dedupliceert. Plan 07-06 test idempotency-replay.
- T-07-04-SC (supply-chain) — Accepted: geen nieuwe vendor-installs (alle imports al via Phase 2/5a).

## Deferred Items

- **docs-sync skill-run:** Route-toevoeging in `routes/api.php` (6 nieuwe routes) triggert de project skill `docs-sync`. In parallel-executor scope is dat een fase-niveau-pass (orchestrator merget alle wave-3 worktrees, daarna 1× sync). Deferred naar phase-close — niet hier uitgevoerd om geen `.docs/` writes te doen die met andere wave-3-worktrees collideren.
- **Behavior-tests:** Volledig in plan 07-06 (CreateAccountSubscriptionTest / CancelAccountSubscriptionTest / PauseResumeAccountSubscriptionTest / ListAccountSubscriptionsTest / WebhookFlowTest). Plan 07-04 levert alleen smoke-test als Nyquist §8c-marge.
- **Scramble UI render-validatie:** Acceptance in plan 07-08 (geen runtime-test in plan 07-04).

## Issues Encountered

- **Single-action `__invoke`-check bij route-registration.** Eerste plan-volgorde (Task 2 maakt controllers) crash't `php artisan route:list` met `Invalid route action: [PauseController]` — Laravel doet de `method_exists($action, '__invoke')`-check bij `Route::post(name, ControllerClass::class)`. Auto-fix: stub-files in Task 1 (zie Deviation 1).
- **pass_through_calls schema-drift in plan-tekst.** Plan-spec noemde kolommen die niet in de actuele 5a-migration staan. Verified met `grep` op de migration-file, audit-helper aangepast (zie Deviation 3).

## User Setup Required

None — geen externe services aangepast. Worktree-vendor + `.env` zijn lokale setup-stappen die niet in tracked-files landen.

## Next Phase Readiness

- **07-05 (webhook-router):** Geen directe consument van plan 07-04 — webhook-handlers consumeren `AccountSubscriptionManager::syncFromMollie`/`recordPaymentEvent`. Plan 07-04 levert wel het audit-pattern dat een toekomstige Spatie `webhook_calls`-extensie kan spiegelen.
- **07-06 (feature-tests):** Kan tegen de 6 endpoints aanslaan met StubsMollieClient + Sanctum-PAT-test-helpers. Form Request + Resource + ability-middleware zijn klaar.
- **07-08 (Scramble OpenAPI):** Route-discovery picks up alle 6 routes automatisch; `@header Idempotency-Key` PHPDoc zorgt dat de header in `/docs/api` zichtbaar is.

## Self-Check

- `app/Http/Requests/Api/V1/AccountSubscriptions/CreateAccountSubscriptionRequest.php` — FOUND
- `app/Http/Resources/Api/V1/AccountSubscriptionResource.php` — FOUND
- `app/Http/Controllers/Api/V1/AccountSubscriptions/AccountSubscriptionController.php` — FOUND
- `app/Http/Controllers/Api/V1/AccountSubscriptions/PauseController.php` — FOUND
- `app/Http/Controllers/Api/V1/AccountSubscriptions/ResumeController.php` — FOUND
- `app/Http/Controllers/Api/V1/AccountSubscriptions/Concerns/HandlesAccountSubscriptionRequests.php` — FOUND
- `tests/Feature/Api/V1/AccountSubscriptions/RouteRegistrationSmokeTest.php` — FOUND
- `routes/api.php` — MODIFIED (6 nieuwe routes + 3 use-imports)
- Commit `68b2b5e` — FOUND in git log (Task 1)
- Commit `f07c033` — FOUND in git log (Task 2)
- `php artisan route:list --except-vendor --path=account-subscriptions` toont 6 routes
- `php artisan test --compact --filter=RouteRegistrationSmokeTest` — 2 passed / 11 assertions
- `php artisan test --compact` — 298 passed / 1 pre-existing incomplete / 975 assertions
- `./vendor/bin/pint --dirty --format agent` — passed
- Verification items 1-6 uit plan: alle 6 geslaagd

## Self-Check: PASSED

---
*Phase: 07-account-level-subscriptions-use-case-b*
*Completed: 2026-05-15*
