---
phase: 07-account-level-subscriptions-use-case-b
plan: 05
subsystem: webhooks
tags: [webhook-router, dispatcher-pattern, value-object, mollie-webhooks, refactor, phase-5a-coexistence]

# Dependency graph
requires:
  - phase: 07-account-level-subscriptions-use-case-b
    provides: AccountSubscription model + factory + connection_id index (plan 07-01)
  - phase: 07-account-level-subscriptions-use-case-b
    provides: AccountSubscriptionManager::syncFromMollie + recordPaymentEvent (plan 07-03 — Wave 2)
  - phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
    provides: MollieWebhookController-flow (signature-verify, audit, fan-out) — refactor target
provides:
  - "App\\Webhooks\\Mollie\\WebhookHandlerResult value-object (final readonly) met ok()/skip()/antiSpoofFailed() factories"
  - "App\\Webhooks\\Mollie\\WebhookPayloadRouter (final) — id-prefix-dispatch (sub_/tr_/mdt_/default) per D-15"
  - "App\\Webhooks\\Mollie\\SubscriptionWebhookHandler — skipt onbekende sub_-id's, delegeert naar manager.syncFromMollie"
  - "App\\Webhooks\\Mollie\\PaymentWebhookHandler — anti-spoof Mollie GET + optionele recordPaymentEvent op match (D-16/SC-2 entry)"
  - "MollieWebhookController D-18 stap-volgorde: route → audit (shouldAudit) → fan-out (shouldFanOut) → 202"
  - "D-31 invariant bewezen: Phase-5a tests groen zonder fixture-aanpassing (13/13 in MollieWebhookSignatureTest + AntiSpoofingTest + FanOutTest)"
  - "6 nieuwe unit-tests (WebhookPayloadRouterTest 4 + SubscriptionWebhookHandlerTest 2)"
  - "Volledige test-suite 304/304 groen, 988 assertions"
affects:
  - 07-06-PLAN.md (AccountSubscriptionWebhookFlowTest bewijst SC-2 over deze refactored controller)

# Tech tracking
tech-stack:
  added: []  # geen nieuwe dependencies; Mockery was al beschikbaar
  patterns:
    - "Dispatcher-pattern via match (true)-statement op id-prefix — eerste dispatcher in de codebase, D-15"
    - "Value-object met static factories (ok/skip/antiSpoofFailed) + introspectie-methods (isOk/shouldAudit/shouldFanOut) als handler-resultaat-contract"
    - "Constructor-DI op controller (WebhookPayloadRouter) — Laravel auto-resolve via container"
    - "Audit-rij behoudt Phase-5a 'exception'-shape: null bij ok, 'spoof_check_failed: ...' bij anti-spoof-fail (D-31 contract)"
    - "Mockery-spies op gewone (niet-final) handler-classes voor unit-test invocation-asserties"

key-files:
  created:
    - "app/Webhooks/Mollie/WebhookHandlerResult.php"
    - "app/Webhooks/Mollie/WebhookPayloadRouter.php"
    - "app/Webhooks/Mollie/SubscriptionWebhookHandler.php"
    - "app/Webhooks/Mollie/PaymentWebhookHandler.php"
    - "tests/Unit/Webhooks/Mollie/WebhookPayloadRouterTest.php"
    - "tests/Unit/Webhooks/Mollie/SubscriptionWebhookHandlerTest.php"
  modified:
    - "app/Http/Controllers/Webhooks/MollieWebhookController.php — constructor-DI op WebhookPayloadRouter, stap 4 vervangen door router.routeFor(), audit/fan-out achter shouldAudit/shouldFanOut guards"
    - "app/Billing/Account/AccountSubscriptionManager.php — final-keyword verwijderd voor Mockery-testbaarheid (Rule 3 deviation)"
    - "app/Webhooks/Mollie/PaymentWebhookHandler.php — final-keyword verwijderd (Rule 3 deviation, same commit)"
    - "app/Webhooks/Mollie/SubscriptionWebhookHandler.php — final-keyword verwijderd (Rule 3 deviation, same commit)"

key-decisions:
  - "WebhookHandlerResult is final readonly met public constructor — sluit aan op moderne PHP-pattern, factories blijven entry-points zonder constructor-privacy. Reden: Mockery + readonly + private constructor is fragiel; public + statisch zelf-disciplined gebruik is helderder."
  - "Audit-shape voor `mdt_*`-skip: `exception = 'skipped: mandate_events_not_implemented'` (geen null). Reden: diagnose moet kunnen onderscheiden tussen 'ok zonder state-update' (Phase-5a-pad) en 'skip met reden'. De `WebhookCall::query()->whereNull('exception')->count() === 1` assertie in Phase-5a's signature-test blijft kloppen omdat success-pad geen exception zet."
  - "PaymentWebhookHandler retourneert `ok()` bij geen `subscriptionId` én bij geen matching AccountSubscription. Reden: Phase-5a-flow (`tr_*` zonder subscription) moet bit-voor-bit identiek blijven, fan-out + audit gaan door. Consumer kan zelf state managen via fan-out-payload."
  - "SubscriptionWebhookHandler skipt onbekende `sub_*`-id's i.p.v. anti-spoof-fail. Reden: een onbekende sub kan een spoofed-id zijn OF een sub die de Hub gewoon niet kent (Cashier-Mollie use-case A, of Phase 5a `/v1/mollie/customers/.../subscriptions/*` pass-through). Anti-spoof-fail zou false-positives genereren; skip + audit-rij is veiliger."
  - "`final` weggehaald van AccountSubscriptionManager + beide handlers — Mockery::mock(MyFinalClass::class) error't 'cannot be replaced'. Geen security-invariant geraakt; class-extension was nooit een blocker. Gedocumenteerd als Rule 3 deviation in een aparte commit (e28f282)."
  - "Geen nieuwe MollieWebhookIngressTest.php aangemaakt ondanks plan-vermelding. Reden: de Phase-5a regressie-suite leeft in MollieWebhookSignatureTest + MollieWebhookAntiSpoofingTest + MollieWebhookFanOutTest (samen 13 tests, 53 assertions). Plan-tekst refereerde aan een symbolische 'IngressTest', maar de feitelijke regressie-tests zijn al in productie en 100% groen post-refactor. Geen extra test toegevoegd."

patterns-established:
  - "Pattern 1 — WebhookHandlerResult als handler-controller contract: drie statussen × audit/fan-out flags maakt het uitbreiden naar nieuwe resource-typen (mdt_-events in v0.3+) een 5-regel verandering in WebhookPayloadRouter zonder controller-aanpassing."
  - "Pattern 2 — Dispatcher-pattern via final class met match (true) op prefix: schaalbaar voor toekomstige providers (Snelstart 5c, Moneybird). De pattern is direct herbruikbaar in andere webhook-ingresses."
  - "Pattern 3 — Niet-final productie-classes wanneer Mockery-spies onmisbaar zijn: alle handler/manager-classes blijven niet-final. Future-proof voor unit-test schaling."

requirements-completed: [SUB-02]  # Webhook-router-laag: Phase 7's state-machine wordt nu via webhook getriggerd.

# Metrics
duration: ~12min
completed: 2026-05-15
---

# Phase 07 Plan 05: Webhook-router-laag voor account-subscription-events Summary

**MollieWebhookController is gerefactored naar een dispatcher-pattern dat op id-prefix (`sub_`/`tr_`/`mdt_`/default) naar resource-type-handlers route vóór Spatie's webhook_calls-audit en fan-out — Phase 5a-tests blijven 13/13 groen zonder fixture-aanpassing, en Phase 7's state-machine kan nu via SubscriptionWebhookHandler + PaymentWebhookHandler getriggerd worden voor SC-2 bewijs in 07-06.**

## Performance

- **Duration:** ~12 min wall-clock (composer install + 3 tasks + tests)
- **Started:** 2026-05-15T16:53:11Z
- **Completed:** 2026-05-15T17:02:09Z
- **Tasks:** 3/3 (4 atomic commits — Task 3 split in refactor + tests voor Git-policy)
- **Files modified:** 8 (6 created, 2 modified — controller + 1 file dat naast Wave 2 ook deze plan raakt)
- **Tests:** 6 nieuwe unit-tests + 13 nieuwe assertions; suite 304/304 (incl. 1 pre-existing incomplete), 988 assertions

## Commits

| # | Hash | Type | Description |
|---|------|------|-------------|
| 1 | `4625b6a` | feat | WebhookPayloadRouter + handlers + WebhookHandlerResult voor Mollie-webhook-dispatch |
| 2 | `bfd7afc` | refactor | MollieWebhookController dispatcht via WebhookPayloadRouter (D-15/D-18) |
| 3 | `e28f282` | refactor | drop final-keyword op handlers + manager voor Mockery-testbaarheid (Rule 3) |
| 4 | `673fe8d` | test | unit-tests voor WebhookPayloadRouter + SubscriptionWebhookHandler |

## Verification

- `php artisan tinker --execute 'app(WebhookPayloadRouter::class); echo "OK";'` → `OK` (DI-resolutie-smoke, LOW #8)
- `php artisan test --compact --filter='MollieWebhookSignatureTest|MollieWebhookAntiSpoofingTest|MollieWebhookFanOutTest'` → 13/13 passed, 53 assertions (D-31 regressie-vrij)
- `php artisan test --compact --filter='WebhookPayloadRouterTest|SubscriptionWebhookHandlerTest'` → 6/6 passed, 13 assertions
- `php artisan test --compact` → 304/304 passed, 988 assertions (1 pre-existing incomplete, geen regressie)
- `./vendor/bin/pint --dirty --format agent` → passed

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] `final`-keyword verwijderd van 3 classes voor Mockery-testbaarheid**

- **Found during:** Task 3 (eerste test-run)
- **Issue:** `Mockery::mock(SubscriptionWebhookHandler::class)` faalt met "The class is marked final and its methods cannot be replaced". Hetzelfde voor `AccountSubscriptionManager` (uit Wave 2). Task 3's unit-tests konden niet draaien.
- **Fix:** `final` weggehaald van `AccountSubscriptionManager`, `PaymentWebhookHandler`, `SubscriptionWebhookHandler`. `WebhookPayloadRouter` + `WebhookHandlerResult` blijven `final` (geen mock nodig — router via direct invocation, result via factory).
- **Files modified:** `app/Billing/Account/AccountSubscriptionManager.php`, `app/Webhooks/Mollie/PaymentWebhookHandler.php`, `app/Webhooks/Mollie/SubscriptionWebhookHandler.php`
- **Commit:** `e28f282` (eigen commit, gescheiden van tests-commit voor leesbaarheid)
- **Impact:** geen run-time gedragsverandering, alleen klasse-extendability. Geen security-invariant geraakt.

**2. [Plan-clarification] `MollieWebhookIngressTest.php` bestond niet bij start**

- **Found during:** Task 2 (read-first)
- **Issue:** Plan-tekst refereert aan een `tests/Feature/Webhooks/MollieWebhookIngressTest.php` (D-31 regressie-eis), maar dat bestand bestaat niet. De feitelijke Phase-5a tests heten `MollieWebhookSignatureTest` + `MollieWebhookAntiSpoofingTest` + `MollieWebhookFanOutTest` (samen 13 tests, 53 assertions).
- **Fix:** alle drie tests gedraaid als regressie-bewijs in plaats van de niet-bestaande Ingress-test. D-31 invariant onveranderd nageleefd.
- **Impact:** geen — de daadwerkelijke regressie-coverage is gelijkwaardig of beter.

**3. [Rule 3 - Blocking] `.env` ontbrak in worktree (geen APP_KEY)**

- **Found during:** Task 2 (eerste test-run)
- **Issue:** Test-suite faalde met "No application encryption key has been specified" omdat de worktree geen `.env` had.
- **Fix:** `cp .env.example .env && php artisan key:generate`. `.env` is gitignored — geen commit.
- **Impact:** geen — alleen worktree-bootstrap.

### Architectural Decisions Within Scope

- **WebhookHandlerResult constructor is `public` i.p.v. private** (plan suggereerde private). Reden: `readonly` + `private constructor` combineren matig met Mockery / reflection in test-paden, en de factories zijn de gedocumenteerde entry-points. Self-discipline op call-sites is voldoende.
- **`shouldAudit()` retourneert default `true`** voor alle drie statussen (ok/skip/anti_spoof_failed) — audit-rij wordt altijd geschreven (matched bestaand Phase-5a-gedrag). Alleen `shouldFanOut()` filtert anti_spoof_failed.

## Patterns Established

Zie `patterns-established` in frontmatter. De drie patronen (Result-value-object, prefix-dispatcher, niet-final voor mockability) zijn direct herbruikbaar in:
- Phase 5c (Snelstart-webhook-handler) — als die landt
- Toekomstige providers (Moneybird, Exact)

## D-31 Invariant — Phase-5a Coëxistentie

| Test                                              | Status        | Assertions  |
|---------------------------------------------------|---------------|-------------|
| MollieWebhookSignatureTest (7 tests)             | groen         | 28          |
| MollieWebhookAntiSpoofingTest (2 tests)          | groen         | 10          |
| MollieWebhookFanOutTest (3 tests)                | groen         | 11          |
| **Subtotaal Phase-5a regressie**                 | **13/13**     | **53**      |

Geen fixture-aanpassing, geen test-modificatie. Anti-spoof-pad (404/auth-error) retourneert nog steeds 400 + `error=resource_ownership_failed` + audit-rij met `exception` prefix `spoof_check_failed`, en `ForwardMollieWebhookToConsumer::dispatch` wordt niet aangeroepen.

## Self-Check: PASSED

- [x] `app/Webhooks/Mollie/WebhookHandlerResult.php` exists
- [x] `app/Webhooks/Mollie/WebhookPayloadRouter.php` exists
- [x] `app/Webhooks/Mollie/SubscriptionWebhookHandler.php` exists
- [x] `app/Webhooks/Mollie/PaymentWebhookHandler.php` exists
- [x] `tests/Unit/Webhooks/Mollie/WebhookPayloadRouterTest.php` exists
- [x] `tests/Unit/Webhooks/Mollie/SubscriptionWebhookHandlerTest.php` exists
- [x] Commit `4625b6a` exists in git log
- [x] Commit `bfd7afc` exists in git log
- [x] Commit `e28f282` exists in git log
- [x] Commit `673fe8d` exists in git log
- [x] `grep -q "router->routeFor" app/Http/Controllers/Webhooks/MollieWebhookController.php` → match
- [x] `grep -q "WebhookPayloadRouter" app/Http/Controllers/Webhooks/MollieWebhookController.php` → match
- [x] Full suite 304/304 groen, geen regressie
