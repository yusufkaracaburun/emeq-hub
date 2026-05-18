---
phase: 07-account-level-subscriptions-use-case-b
plan: 02
subsystem: payments
tags: [state-machine, enum, exception, mollie-subscriptions, account-subscriptions, php-enum]

# Dependency graph
requires:
  - phase: 07-account-level-subscriptions-use-case-b
    provides: AccountSubscription Eloquent-model + migration (plan 07-01) — die in plan 07-03 een `'status' => SubscriptionStatus::class`-cast krijgt op de status-kolom
provides:
  - PHP backed-string `SubscriptionStatus` enum met de 6 D-04 cases (`pending`/`active`/`paused`/`canceled`/`completed`/`unknown`)
  - `StateTransitions::assertTransition()` helper als single-source-of-truth voor legality-checks (alle 9 D-04 legal-pairs gehard)
  - `StateTransitions::isLegal()` introspectie-helper voor exception-loze checks
  - `InvalidStateTransitionException` met readonly `from`/`to` enum-properties + NL-message-conventie + static `for()`-factory
  - 38 unit-tests (132 assertions) die de volledige legal/illegal-matrix afdekken inclusief alle terminal-state-blokkades en zelf-transities
affects:
  - 07-03-PLAN.md (AccountSubscriptionManager — single-entry service voor state-flips, gaat `assertTransition` aanroepen vóór elke save)
  - 07-04-PLAN.md (HTTP-API — fouten worden via 409 met `error_code: invalid_state_transition` doorgegeven, D-23)
  - 07-05-PLAN.md (webhook-handlers — Mollie-status-updates roepen `syncFromMollie` aan die op zijn beurt `assertTransition` gebruikt)

# Tech tracking
tech-stack:
  added: []  # alleen native PHP 8.4 enum + RuntimeException — geen nieuwe packages
  patterns:
    - "Backed string enum + final-class helper + readonly-property exception (eerste state-machine in repo)"
    - "PHPUnit DataProvider-attribute (`#[DataProvider]`) voor exhaustive legality-matrix"
    - "Self-transition = idempotent no-op (webhook-replay-safety)"

key-files:
  created:
    - "app/Billing/Account/SubscriptionStatus.php (PHP-enum, 6 cases)"
    - "app/Billing/Account/StateTransitions.php (final class met assertTransition + isLegal)"
    - "app/Billing/Account/Exceptions/InvalidStateTransitionException.php (RuntimeException met readonly from/to)"
    - "tests/Unit/Billing/Account/StateTransitionsTest.php (38 tests, 132 assertions)"
  modified: []

key-decisions:
  - "Self-transitions (bv. `Active → Active`) zijn idempotent — geen exception. Reden: webhook-replay-safety (een Mollie-resync mag dezelfde status opnieuw zetten zonder te crashen)."
  - "`StateTransitions::isLegal()` toegevoegd náást `assertTransition()` voor introspectie-test-cases. Niet-essentieel maar maakt de illegal-matrix-test leesbaar zonder try/catch-noise."
  - "Test extends `PHPUnit\\Framework\\TestCase` (pure logic, geen Laravel-boot) — match style van `MollieUpstreamErrorMapperTest` i.p.v. de Laravel-backed `PlanResolverTest`. Resultaat: testsuite-duur ~14ms voor 38 tests."
  - "Exception-class blijft generiek (`from`/`to`-properties) — geen extra context-veld zoals `reason`. Manager-laag (plan 07-03) logt context apart via `Log::info('account_subscription.transition', [...])` per D-22."

patterns-established:
  - "Pattern 1: State-machine in `app/Billing/Account/` — enum-class + static-transition-helper + readonly-property-exception. Wordt analoog herbruikt als andere Hub-resources een state-flow nodig hebben."
  - "Pattern 2: NL-message + EN-identifier in custom exceptions — vervolgt de `UnknownPlanException`-conventie."
  - "Pattern 3: Exhaustive matrix via #[DataProvider] — leesbare test-namen (`pending → active`, `canceled → pending`) maken tests zelf-documenterend."

requirements-completed: [SUB-02]

# Metrics
duration: ~25min
completed: 2026-05-15
---

# Phase 07 Plan 02: State-machine fundering voor AccountSubscription Summary

**Backed `SubscriptionStatus`-enum + `StateTransitions::assertTransition()`-helper + `InvalidStateTransitionException` met readonly `from`/`to`-properties leveren de single-source-of-truth voor de 9 D-04 legal-transitions; 38 unit-tests dekken de volledige legal/illegal-matrix inclusief alle 3 terminal-state-blokkades.**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-05-15T15:44:00Z (approximate — sessie-start)
- **Completed:** 2026-05-15T16:09:20Z
- **Tasks:** 2/2
- **Files modified:** 4 created (3 production + 1 test)

## Accomplishments

- D-04 state-machine kreeg een productie-ready implementatie: enum + transition-helper + exception zijn alle drie geland in `app/Billing/Account/`.
- Plan 07-03's `AccountSubscriptionManager` en plan 07-05's webhook-handlers kunnen nu `StateTransitions::assertTransition()` consumeren — geen circulaire dependency met Wave 2.
- Threat T-07-02-02 (terminal-state-escape) is bewezen onmogelijk via een dedicated test die alle outbound-transities vanaf `canceled`/`completed`/`unknown` cross-product test.
- Idempotente self-transitions (D-04-uitbreiding ten behoeve van webhook-replay) zijn expliciet als no-op geïmplementeerd én getest voor alle 6 states.

## Task Commits

Each task was committed atomically:

1. **Task 1: SubscriptionStatus enum + InvalidStateTransitionException + StateTransitions helper** — `6b4627c` (feat)
2. **Task 2: Unit-test voor exhaustive transition-matrix** — `b221a19` (test)

## Files Created/Modified

- `app/Billing/Account/SubscriptionStatus.php` — Backed string enum met 6 cases (`pending`/`active`/`paused`/`canceled`/`completed`/`unknown`). TitleCase keys per Laravel Boost PHP-rule. Geen extra methods (pure data-enum).
- `app/Billing/Account/StateTransitions.php` — `final class` met `assertTransition(SubscriptionStatus $from, SubscriptionStatus $to): void`, `isLegal(...): bool`, en een private `legalPairs(): array` met de 9 D-04 pairs. Self-transition retourneert vroeg (idempotent).
- `app/Billing/Account/Exceptions/InvalidStateTransitionException.php` — `final class extends \RuntimeException` met readonly `from`/`to` SubscriptionStatus-properties + static `for()`-factory + NL-message `"Ongeldige state-transition: %s → %s."`.
- `tests/Unit/Billing/Account/StateTransitionsTest.php` — 8 test-methods, 38 dataset-rows, 132 assertions. Gebruikt `#[DataProvider]`-attribute (PHPUnit 12) voor `legalPairs`/`illegalPairs`/`allStates`. Extends `PHPUnit\\Framework\\TestCase` voor max-performance (~14ms total).

## Decisions Made

- **Self-transition is idempotent.** Plan beschreef het als optioneel (`Self-transition (\$from === \$to) → no-op`); ik heb het geïmplementeerd met early-return in zowel `assertTransition` als `isLegal`. Reden: webhook-replay-safety is een eerstegraads veiligheid — plan 07-05's `syncFromMollie` mag dezelfde status nooit als crash zien.
- **`isLegal()` als publieke helper toegevoegd.** Plan zei "optioneel — alleen als unit-test 'm gebruikt". De illegal-matrix-test gebruikt 'm twee keer (assertFalse + try/catch); zonder `isLegal()` zou de test óf veel try/catch-noise hebben óf de interne legal-pairs-array publiek maken. Dit is de schoonste API.
- **Test extends `PHPUnit\\Framework\\TestCase`** (geen Laravel-boot) — match style van `MollieUpstreamErrorMapperTest`. PlanResolverTest gebruikt wel `Tests\\TestCase`, maar dat is omdat die `config()`-helpers + DI-container nodig heeft. State-transition-logica is pure functions; container-boot is overhead.

## Deviations from Plan

None - plan executed exactly as written (de drie kleine implementatiekeuzes hierboven zijn binnen plan-marges: `isLegal` was als "optioneel" gemarkeerd, idempotente self-transition stond expliciet in `<action>`).

## TDD Gate Compliance

Plan 07-02 is geen `type: tdd`-plan (`type: execute`); de twee tasks dragen alleen `tdd="true"` — een per-task TDD-aanduiding. Daarom geen plan-level RED → GREEN → REFACTOR gate-volgorde. Wel: Task 2's test draait op Task 1's code, dus de test bewijst rooting in de implementatie.

In strikte TDD-zin had RED eerst gemoeten (test vóór code). De plan-volgorde (Task 1 = code, Task 2 = test) is bewust zo gekozen door de planner om Task 1's verification via een PHP-one-liner te kunnen draaien (anders moet Task 1 al een test schrijven, wat Task 2's mandate dupliceert). Resultaat is functioneel gelijk: alle gedrag is door tests gedekt vóór commit-2 landt.

## Threat Surface Scan

Geen nieuwe security-relevante surface — pure in-process state-machine. T-07-02-01/02 mitigations zijn bewezen via tests in Task 2; T-07-02-03 (audit-stille flips) blijft Phase 9-deferred per plan.

## Self-Check

- `app/Billing/Account/SubscriptionStatus.php` — FOUND
- `app/Billing/Account/StateTransitions.php` — FOUND
- `app/Billing/Account/Exceptions/InvalidStateTransitionException.php` — FOUND
- `tests/Unit/Billing/Account/StateTransitionsTest.php` — FOUND
- Commit `6b4627c` — FOUND (feat 07-02 Task 1)
- Commit `b221a19` — FOUND (test 07-02 Task 2)
- `php artisan test --compact --filter=StateTransitionsTest` — 38 passed, 132 assertions, 14ms
- `php artisan test --compact` — 288 passed (1 pre-existing incomplete), geen regressie
- `./vendor/bin/pint --dirty --format agent` — passed
- Verification items 1-5 uit plan: alle 5 geslaagd

## Self-Check: PASSED
