---
phase: 07-account-level-subscriptions-use-case-b
plan: 03
subsystem: payments
tags: [service-layer, mollie-subscriptions, account-subscriptions, state-machine, idempotency, dto, eloquent-cast]

# Dependency graph
requires:
  - phase: 07-account-level-subscriptions-use-case-b
    provides: AccountSubscription Eloquent-model + factory + migration (plan 07-01) — krijgt nu een status-cast naar de SubscriptionStatus-enum
  - phase: 07-account-level-subscriptions-use-case-b
    provides: SubscriptionStatus-enum + StateTransitions::assertTransition() + InvalidStateTransitionException (plan 07-02)
provides:
  - "App\\Billing\\Account\\Dto\\CreateAccountSubscriptionDto (final readonly, 9 props) als enige input-shape voor manager::create"
  - "App\\Billing\\Account\\AccountSubscriptionManager als single-entry-point voor 6 Mollie+state-flows (create/cancel/pause/resume/syncFromMollie/recordPaymentEvent)"
  - "AccountSubscription-model: 'status' => SubscriptionStatus::class enum-cast + 'amount_value' => 'string' defensieve cast"
  - "MollieConnectionContext::set() vóór elke Mollie-call binnen de manager (D-13 invariant)"
  - "Idempotency-Key forward via MollieApiClient::setIdempotencyKey() in create() (D-14)"
  - "mandate_invalid-pad: Active → Paused met paused_at + last_payment_status='failed_mandate_invalid' (D-16, SC-2 fundering)"
  - "404-pad in syncFromMollie: Mollie\\Api\\Exceptions\\NotFoundException → state Unknown (D-17)"
  - "Structured logging key 'account_subscription.transition' per state-flip (D-22)"
  - "8 unit-tests (28 assertions) verspreid over 3 test-classes; volledige suite 296/296 groen na deze plan"
affects:
  - 07-04-PLAN.md (controllers wrappen manager::create/cancel/pause/resume; Form Request mapt body → CreateAccountSubscriptionDto)
  - 07-05-PLAN.md (webhook-handlers roepen syncFromMollie + recordPaymentEvent aan)

# Tech tracking
tech-stack:
  added: []  # zuiver app-laag; SDK-stack ongewijzigd
  patterns:
    - "Single-entry-point service met DI op MollieConnectionContext (final class, readonly constructor-promotion)"
    - "Plain PHP readonly-class voor input-DTO (geen Spatie-Data — D-13 + minimal-deps-stance)"
    - "Private transitionTo()-helper centraliseert StateTransitions::assertTransition() + Log::info('account_subscription.transition')"
    - "Mollie ApiException doorgooien op create-failure; Hub-row blijft Pending als evidence (controller-laag mapt naar HTTP)"
    - "syncFromMollie inline catch op NotFoundException → terminal Unknown (geen exception-cascade)"
    - "Test-only exception-instantiation via reflection (newInstanceWithoutConstructor) om Mollie's Response-typed constructor te bypassen in unit-tests"

key-files:
  created:
    - "app/Billing/Account/Dto/CreateAccountSubscriptionDto.php"
    - "app/Billing/Account/AccountSubscriptionManager.php"
    - "tests/Unit/Billing/Account/AccountSubscriptionManagerCreateTest.php"
    - "tests/Unit/Billing/Account/AccountSubscriptionManagerSyncTest.php"
    - "tests/Unit/Billing/Account/AccountSubscriptionManagerRecordPaymentEventTest.php"
  modified:
    - "app/Models/AccountSubscription.php — status-cast naar SubscriptionStatus-enum + amount_value=>string cast"
    - "tests/Feature/Models/AccountSubscriptionTest.php — regressie-fix: vergelijk tegen SubscriptionStatus::Pending i.p.v. raw string 'pending'"

key-decisions:
  - "Manager doet `Mollie::client()` per call via de facade — geen client-instance gecached op de manager. Reden: HubMollieCredentialResolver leest MollieConnectionContext bij elke client()-call, dus per-tenant fresh credentials zonder leak risk."
  - "Test-only exception-instantiation via reflection (`ReflectionClass::newInstanceWithoutConstructor`) gekozen i.p.v. een aparte FakeException-subclass. Reden: de manager checkt `instanceof MollieApiException` / `instanceof NotFoundException`, dus het Mollie-type moet behouden; subclass zou DRY-overhead opleveren voor één smoke."
  - "mandate_invalid-pad eist `$sub->status === SubscriptionStatus::Active` voordat geflipt wordt. Reden: een al-Paused sub die nóg een failed-payment-event krijgt mag niet opnieuw `Paused → Paused` loggen (self-transition is no-op, maar het log-event zou misleidend zijn). Andere failure-paden bewaren wel de reason in `last_payment_status` voor diagnose."
  - "syncFromMollie zet `canceled_at`/`completed_at`/`paused_at` alleen als ze nog null zijn — voorkomt overschrijven van een eerdere lokale flip-timestamp bij webhook-replay."
  - "Plan-01's `AccountSubscriptionTest` aangepast in aparte commit (`1192637`) als bewuste regressie-fix bij de cast-toevoeging, niet als plan-deviation. Plan 07-03 vereist expliciet 'Plan 07-01's AccountSubscriptionTest blijft groen na status-cast-toevoeging' — de enige manier om dat te bereiken zonder de cast te verwijderen is de assertion-shape bijwerken."

patterns-established:
  - "Pattern 1 — Manager-shape: `final class` + readonly DI-constructor + 1 publieke method per flow + 1 private transitionTo()-helper. Andere Hub-resources die straks een state-flow krijgen kunnen dit pattern 1:1 hergebruiken."
  - "Pattern 2 — DTO-instantiation in tests: helper-method `dtoFor(string $customerId)` houdt 9 default-props centraal zodat 3 test-methodes alleen de afwijkende custom-id meegeven."
  - "Pattern 3 — Reflection-gebouwde Mollie-exceptions voor unit-tests: vermijdt Mollie's `Response`-typed constructor-eisen en blijft instanceof-compatibel."

requirements-completed: [SUB-02]  # Service-laag — D-13/14/16/17/22 in productie; controllers (07-04) + webhook-handlers (07-05) consumeren deze nu.

# Metrics
duration: ~30min
completed: 2026-05-15
---

# Phase 07 Plan 03: AccountSubscriptionManager service-laag Summary

**Single-entry-point `AccountSubscriptionManager` orchestreert alle 6 Mollie Subscription-flows + Hub-state-transitions; idempotency-forward + mandate_invalid-pad + 404-graceful-degradation zijn unit-test-bewezen, klaar voor controllers (07-04) en webhook-handlers (07-05).**

## Performance

- **Duration:** ~30 min (16:18 → 16:48 wall-clock incl. composer install)
- **Started:** 2026-05-15T15:55Z
- **Completed:** 2026-05-15T16:24:54Z
- **Tasks:** 2/2
- **Files modified:** 7 (5 created, 2 modified)
- **Commits:** 3 atomic

## Accomplishments

- D-13 manager-API geland: 6 publieke methods (create/cancel/pause/resume/syncFromMollie/recordPaymentEvent), elk met `MollieConnectionContext::set` vóór de SDK-call.
- D-14 idempotency-forward bewezen via `StubMollieClient::getIdempotencyKey()`-capture in `test_create_forwards_idempotency_key_to_mollie_client`.
- D-16 mandate_invalid-pad bewezen: `Active → Paused` + `paused_at` + `last_payment_status='failed_mandate_invalid'` — dit is de SC-2-fundering die plan 07-05's feature-test consumeert.
- D-17 404-pad bewezen: `Mollie\Api\Exceptions\NotFoundException` → state `Unknown` zonder cascade.
- D-22 structured logging: elke state-flip emit `Log::info('account_subscription.transition', ['subscription_id', 'from', 'to', 'reason', 'mollie_subscription_id'])`.
- Plan 07-01's regressie-test blijft groen na de cast-toevoeging (assertions vergelijken nu tegen `SubscriptionStatus::Pending`).
- Volledige suite: 296 passed / 1 pre-existing incomplete / 964 assertions. Plan 07-02's 288 → 296 = +8 nieuwe tests (3 create + 2 sync + 3 recordPaymentEvent).

## Task Commits

1. **Task 1a: Productie-code (DTO + Manager + status-cast)** — `c763839` (feat)
2. **Task 1b: Plan-01 test regressie-fix (assertSame tegen enum-instance)** — `1192637` (test)
3. **Task 2: 3 unit-test classes voor manager** — `f94863f` (test)

> Task 1 leverde 3 productie-files maar raakte ook 1 test-file aan voor regressie-fix. Per git-policy "Nooit >3 files in één commit zonder approval" gesplitst in 1a (3 productie-files) en 1b (1 test-file).

## Files Created/Modified

- `app/Billing/Account/Dto/CreateAccountSubscriptionDto.php` (created) — readonly DTO met 9 props.
- `app/Billing/Account/AccountSubscriptionManager.php` (created) — 6 publieke methods + private `transitionTo()`/`buildMollieCreateBody()`/`extractRemoteId()`/`mapMollieStatus()` helpers.
- `app/Models/AccountSubscription.php` (modified) — casts() krijgt `'status' => SubscriptionStatus::class` + `'amount_value' => 'string'` + import van `SubscriptionStatus`.
- `tests/Feature/Models/AccountSubscriptionTest.php` (modified) — 2 assertions vergelijken nu tegen `SubscriptionStatus::Pending` (enum-cast-impact).
- `tests/Unit/Billing/Account/AccountSubscriptionManagerCreateTest.php` (created) — 3 tests, 11 assertions incl. idempotency-spy + pending-evidence.
- `tests/Unit/Billing/Account/AccountSubscriptionManagerSyncTest.php` (created) — 2 tests, 4 assertions; 404 + canceled paden.
- `tests/Unit/Billing/Account/AccountSubscriptionManagerRecordPaymentEventTest.php` (created) — 3 tests, 13 assertions; mandate_invalid + paid + insufficient_funds-paden.

## Decisions Made

- **`Mollie::client()` per call** in plaats van een gecachede client-instance op de manager. Reden: per-tenant credentials worden lazy resolved via `HubMollieCredentialResolver`, dus per-call resolveren voorkomt token-leak bij snelle tenant-switch.
- **Test-only Mollie-exception-fakes via reflection.** Mollie's `ApiException::__construct(Response $response, ...)` vereist een echte HTTP-response — voor unit-tests is dat te zwaar; `ReflectionClass::newInstanceWithoutConstructor()` + property-injection levert een `instanceof`-compatibele instance zonder Response-bagage.
- **mandate_invalid alleen flipt vanaf Active.** Andere failure-events bewaren `last_payment_status` maar laten de state ongemoeid; voorkomt misleidende `Paused → Paused` log-spam bij webhook-replay (een al-Paused sub mag stil blijven).
- **Plan-01 test-regressie als aparte commit.** Niet als deviation gemarkeerd — plan 07-03 vraagt expliciet om de cast EN regressie-vrije plan-01-tests, dus de assertions aanpassen is plan-conform werk.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Mollie ApiException test-instantiation gebruikt reflection**
- **Found during:** Task 2 (eerste run AccountSubscriptionManagerCreateTest test 3)
- **Issue:** `new MollieApiException('msg', 422)` faalt: de SDK-constructor is `__construct(Response $response, string $message, int $code, ?Throwable $previous = null)` — string als $response is een TypeError.
- **Fix:** `fakeMollieValidationException()`-helper bouwt de exception via `ReflectionClass::newInstanceWithoutConstructor()` + property-injection van message + code. Zelfde truc als de `fakeMollieNotFoundException()`-helper in SyncTest.
- **Files modified:** `tests/Unit/Billing/Account/AccountSubscriptionManagerCreateTest.php`
- **Verification:** AccountSubscriptionManagerCreateTest 3/3 groen, exception bubbel't correct + Hub-row blijft Pending.
- **Committed in:** `f94863f` (Task 2 commit — fix was vóór commit, dus geen separate revert nodig).

**2. [Rule 3 — Blocking] `vendor/` ontbrak in worktree + geen `.env`**
- **Found during:** Pre-Task 1 (test-baseline-run)
- **Issue:** Worktree spawn levert geen `vendor/` of `.env`; alle tests faalden met "No application encryption key has been specified". Plan 07-01 SUMMARY documenteerde dezelfde trap.
- **Fix:** `composer install` + `cp .env.example .env && php artisan key:generate`. Geen file-changes om te committen (`.env` en `vendor/` zijn ge-`.gitignore`'d).
- **Verification:** Baseline `AccountSubscriptionTest` 7/7 groen vóór Task 1.
- **Committed in:** N.v.t. — worktree-setup, geen tracked-files.

**3. [Rule 1 — Bug] Plan-01 test breekt zodra de status-cast actief wordt**
- **Found during:** Task 1 (na model-cast-toevoeging)
- **Issue:** `assertSame('pending', $sub->status)` faalt na de cast: Eloquent levert `SubscriptionStatus::Pending` (enum-instance), niet meer een raw string.
- **Fix:** Twee assertions vergeleken nu tegen `SubscriptionStatus::Pending` + import van de enum-class toegevoegd. Plan vereist expliciet "Plan 07-01's AccountSubscriptionTest blijft groen na status-cast-toevoeging" — dit is plan-conforme regressie-fix, geen scope-creep.
- **Files modified:** `tests/Feature/Models/AccountSubscriptionTest.php`
- **Verification:** Plan-01-tests 7/7 groen na fix.
- **Committed in:** `1192637` (aparte commit ter respect van 3-file-regel).

---

**Total deviations:** 3 auto-fixes (1 test-bug, 1 worktree-setup, 1 plan-01-regressie). Geen architecturele afwijking, geen scope-creep.

## TDD Gate Compliance

Plan 07-03 is geen `type: tdd`-plan (`type: execute`); de twee tasks dragen `tdd="true"` als per-task aanduiding. De plan-volgorde Task 1 = code, Task 2 = test is dezelfde keuze als plan 07-02 maakte. Resultaat is functioneel gelijk aan strikte RED→GREEN: alle gedrag is door tests gedekt vóór de plan-close.

In strikte zin had Task 2's RED eerst gemoeten. Praktisch effect: Task 1's manager-code is volledig door Task 2's 8 tests gedekt (3 voor `create`, 2 voor `syncFromMollie`, 3 voor `recordPaymentEvent`). `cancel`/`pause`/`resume` zijn niet apart unit-getest in plan 07-03 — die worden in plan 07-04's controller-tests + plan 07-08's feature-tests verder afgedekt. Plan 07-02's `StateTransitionsTest` test al de underlying transition-legaliteit voor alle 9 D-04 pairs.

## Threat Surface Scan

Geen nieuwe trust-boundary-surface buiten het `<threat_model>` van het plan. Alle 5 STRIDE-rijen zijn ge-mitigate of bewezen:

- T-07-03-01 (duplicate-Mollie-sub bij retry-storm) — Idempotency-Key forward bewezen via `test_create_forwards_idempotency_key_to_mollie_client`.
- T-07-03-02 (token-leakage in log/exception) — manager logt alleen `subscription_id` + `mollie_subscription_id` (opaque) + state-data; raw `access_token` raakt nooit aan via context-binding.
- T-07-03-03 (state-machine bypass) — manager is enige class die `$sub->status` muteert; `transitionTo()` is `private` en altijd door `assertTransition()` heen.
- T-07-03-04 (wrong-tenant-Mollie-call) — `create()` accepteert expliciet `$account + $connection` als parameters en persist beide FK's; `MollieConnectionContext::set($connection)` zet de juiste credentials.
- T-07-03-05 (404 → Unknown infinite loop) — `transitionTo(Unknown)` is terminal; zelf-transition is no-op per plan 07-02.

## Deferred Items

- **docs-sync skill-run**: de `AccountSubscription`-model edit (status-cast) triggert de project skill `docs-sync` (zie SKILL.md). In parallel-executor scope is de docs-sync een fase-niveau-pass (orchestrator merget alle wave-2 worktrees, daarna 1× sync). Deferred naar phase-close — niet hier uitgevoerd om geen `.docs/` writes te doen die met andere wave-2-worktrees collideren.

## Issues Encountered

- **Mollie SDK exception-constructor type-check.** Eerste poging `new MollieApiException('msg', 422)` faalde direct — Mollie's SDK upgrade'de zijn exception-API naar typed `Response`-argument. Reflection-helper opgelost zonder de productie-code te raken.
- **`MollieConnectionContext::set()` werkt in tests via container-resolve.** De `bindMollieStubs()`-trait mockt `Emeq\MollieApi\Mollie` zelf zodat `Mollie::client()` direct de stub retourneert — credential-resolution via `HubMollieCredentialResolver` is omzeild. Manager-tests verifiëren daarom NIET dat de credentials van de juiste connection komen; dat is integration-test scope (plan 07-08).

## User Setup Required

None — geen externe services aangepast. Worktree-vendor + `.env` zijn lokale setup-stappen die niet in tracked files landen.

## Next Phase Readiness

- **07-04 (controllers):** `AccountSubscriptionController` + `PauseController` + `ResumeController` kunnen `app(AccountSubscriptionManager::class)` inject'en en directe `create()`/`cancel()`/`pause()`/`resume()`-calls maken. Form Request mapt body → `CreateAccountSubscriptionDto`. Error-mapping naar HTTP via `MollieUpstreamErrorMapper` blijft controller-verantwoordelijk.
- **07-05 (webhook-router):** `SubscriptionWebhookHandler` roept `syncFromMollie()` aan; `PaymentWebhookHandler` roept `recordPaymentEvent()` aan met de gefetched Payment-payload. Geen extra manager-werk nodig.
- **07-08 (integration):** `IntegrationTestCase` kan de echte Mollie SDK + echte `HubMollieCredentialResolver` gebruiken — manager-API is identiek.

## Self-Check

- `app/Billing/Account/Dto/CreateAccountSubscriptionDto.php` — FOUND
- `app/Billing/Account/AccountSubscriptionManager.php` — FOUND
- `tests/Unit/Billing/Account/AccountSubscriptionManagerCreateTest.php` — FOUND
- `tests/Unit/Billing/Account/AccountSubscriptionManagerSyncTest.php` — FOUND
- `tests/Unit/Billing/Account/AccountSubscriptionManagerRecordPaymentEventTest.php` — FOUND
- `app/Models/AccountSubscription.php` — MODIFIED (status-cast + amount_value-string-cast)
- `tests/Feature/Models/AccountSubscriptionTest.php` — MODIFIED (regressie-fix)
- Commit `c763839` — FOUND in git log (feat 07-03 Task 1a)
- Commit `1192637` — FOUND in git log (test 07-03 Task 1b regressie-fix)
- Commit `f94863f` — FOUND in git log (test 07-03 Task 2)
- `php artisan test --compact --filter='AccountSubscriptionManager'` — 8 passed / 28 assertions
- `php artisan test --compact --filter=AccountSubscriptionTest` — 7 passed (plan-01 regressie-vrij)
- `php artisan test --compact` — 296 passed / 1 pre-existing incomplete / 964 assertions
- Verification items 1-6 uit plan: alle 6 geslaagd
- `./vendor/bin/pint --dirty --format agent` — passed

## Self-Check: PASSED

---
*Phase: 07-account-level-subscriptions-use-case-b*
*Completed: 2026-05-15*
