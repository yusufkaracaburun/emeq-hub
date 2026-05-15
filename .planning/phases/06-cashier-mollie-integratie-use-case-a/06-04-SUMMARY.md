---
phase: 06-cashier-mollie-integratie-use-case-a
plan: 04
subsystem: cashier-mollie / plan-resolver
tags: [cashier-mollie, plan-resolver, config, sub-01, tdd]

# Dependency graph
requires:
  - phase: 06-cashier-mollie-integratie-use-case-a
    plan: 02
    provides: "Cashier-Mollie v2.20.1 installed + config/cashier.php gepubliceerd"
provides:
  - "config/billing-plans.php (D-05) — single source of truth voor plan-definities"
  - "App\\Billing\\PlanResolver::find(string $slug): array (D-06)"
  - "App\\Billing\\PlanResolver::all(): array — slug-indexed plan-array"
  - "App\\Billing\\Exceptions\\UnknownPlanException::forSlug() — typed exception"
affects:
  - 06-05 (newSubscription-flow consumeert PlanResolver::find())
  - 06-07 (integration-suite gebruikt plan-slugs)
  - 06-09 (Filament BillingResource in Phase 9 leest via PlanResolver::all())

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "TDD RED-first (RED-commit faalt op 6x Class-not-found; GREEN-commit groen)"
    - "Config-driven plan-storage buiten vendor-config-tree (D-05 anti-vendor:publish-overwrite)"
    - "Static factory-method op typed exception (UnknownPlanException::forSlug)"
    - "PHPDoc array-shape annotations voor Cashier-shape return-types"

key-files:
  created:
    - "tests/Unit/Billing/PlanResolverTest.php"
    - "config/billing-plans.php"
    - "app/Billing/PlanResolver.php"
    - "app/Billing/Exceptions/UnknownPlanException.php"
  modified: []

key-decisions:
  - "Plan-prijzen op '0.00' placeholder gehouden — plan 06-05 executor vult business-prijzen. Mollie weigert €0.00 subscriptions = safety-net tegen deploy-zonder-bizz-input."
  - "PlanResolver gebruikt geen constructor-property-promotion: stateloos, leest alleen `config()`-helper. Container resolves via default-constructor."
  - "config/cashier_plans.php (Cashier-vendor) blijft byte-identiek — D-05 anti-vendor-tree-mutatie ingelost."

# Metrics
duration: 3min
completed: 2026-05-15
requirements-completed: []  # SUB-01 blijft in-progress; newSubscription-routes + webhook nog niet gebouwd
---

# Phase 6 Plan 04: PlanResolver + config/billing-plans Summary

**`App\Billing\PlanResolver` + `App\Billing\Exceptions\UnknownPlanException` + `config/billing-plans.php` geland; 6/6 RED→GREEN unit-tests; full suite 212 → 218 zonder regressies.**

## Performance

- **Duration:** ~3 min
- **Started:** 2026-05-15T08:07:16Z
- **Completed:** 2026-05-15
- **Tasks:** 2 (RED-tests + GREEN-implementation)
- **Files created:** 4 (1 test-class + 1 config + 1 service-class + 1 exception)
- **Files modified:** 0

## Accomplishments

- **D-05 ingelost:** `config/billing-plans.php` bestaat met 2 plan-definities (`naschool-license`, `planny-license`) in EUR + '1 month' interval. Cashier-vendor-tree (`config/cashier_plans.php`) byte-identiek.
- **D-06 ingelost:** `App\Billing\PlanResolver::find(string $slug): array` retourneert plan-array of gooit `UnknownPlanException`. `all(): array` retourneert slug-indexed array.
- **Cashier-shape preserved:** beide plans volgen Cashier-Mollie ^2.20's verwachte schema (`amount.value`/`amount.currency`/`interval`/`description`) zodat plan 06-05 ze 1:1 aan `SubscriptionBuilder` kan doorgeven.
- **Multi-currency uit scope:** alle plans op `'EUR'`. PlanResolver doet geen currency-validatie — config is source of truth.
- **Typed exception:** `UnknownPlanException` extends `RuntimeException`, `final class`, static `::forSlug(string)`-factory met Nederlandstalige debug-message.
- **6 RED-tests committed (`c91c224`) → 6 GREEN-tests bewezen (`756406c`):** find happy-path, find-throws-on-unknown, all()-shape, plan-shape-match, case-sensitivity, container-bindable.
- **0 regressies:** full suite 212 → 218 (6 nieuw), zelfde duration-profile (~4s).
- **Pint clean** — geen normalisatie nodig; files volgen al de project-conventies.

## Task Commits

1. **Task 1 — RED:** `c91c224` (test) — `tests/Unit/Billing/PlanResolverTest.php` (87 regels, 6 tests, alle 6 errored op `Class not found`)
2. **Task 2 — GREEN:** `756406c` (feat) — config + PlanResolver + UnknownPlanException (3 files, +104/-0)

## Files Created

### `config/billing-plans.php` (verbatim, 36 regels)

```php
<?php

declare(strict_types=1);

/*
 * D-05 / D-06: plan-definities voor Cashier-Mollie use-case A
 * (Emeq factureert aan Consumers). Schema matched
 * `mollie/laravel-cashier-mollie ^2.20`'s plan-shape:
 * - amount.value: string, 2 decimals (Mollie-validatie-vereiste)
 * - amount.currency: 'EUR' (multi-currency expliciet uit scope v0.2)
 * - interval: Cashier-Mollie ondersteunt '1 month', '12 months',
 *   '1 year', etc. — zie Cashier-Mollie SubscriptionBuilder.
 * - description: verschijnt op Mollie's invoice-emails.
 *
 * Plan 06-05 executor vult de echte prijzen in (uit business);
 * placeholders blijven hier op '0.00'. Op '0.00' weigert Mollie
 * de subscription-create, wat een safety-net is tegen per-ongeluk
 * deploy zonder bizz-input.
 */
return [
    'naschool-license' => [
        'amount' => [
            'value' => '0.00',
            'currency' => 'EUR',
        ],
        'interval' => '1 month',
        'description' => 'Naschool SaaS license — Emeq Hub access',
    ],
    'planny-license' => [
        'amount' => [
            'value' => '0.00',
            'currency' => 'EUR',
        ],
        'interval' => '1 month',
        'description' => 'Planny SaaS license — Emeq Hub access',
    ],
];
```

### `app/Billing/PlanResolver.php` (verbatim, 47 regels)

```php
<?php

declare(strict_types=1);

namespace App\Billing;

use App\Billing\Exceptions\UnknownPlanException;

/**
 * Config-driven plan-resolver voor Cashier-Mollie subscriptions.
 *
 * D-05: plans worden in config/billing-plans.php gedefinieerd (niet in
 *       Cashier's eigen vendor-config-tree).
 * D-06: simpele find()/all() public API zonder Eloquent Plan-model.
 *
 * Retourneert plan-arrays in Cashier-Mollie ^2.20's verwachte shape
 * zodat plan 06-05 ze 1:1 aan SubscriptionBuilder kan voeren.
 */
final class PlanResolver
{
    /**
     * @return array{amount: array{value: string, currency: string}, interval: string, description: string}
     *
     * @throws UnknownPlanException Wanneer de slug niet in config/billing-plans.php staat.
     */
    public function find(string $slug): array
    {
        /** @var array<string, mixed>|null $plan */
        $plan = config("billing-plans.{$slug}");

        if (! is_array($plan)) {
            throw UnknownPlanException::forSlug($slug);
        }

        /** @var array{amount: array{value: string, currency: string}, interval: string, description: string} $plan */
        return $plan;
    }

    /**
     * @return array<string, array{amount: array{value: string, currency: string}, interval: string, description: string}>
     */
    public function all(): array
    {
        /** @var array<string, array{amount: array{value: string, currency: string}, interval: string, description: string}> $plans */
        $plans = config('billing-plans', []);

        return $plans;
    }
}
```

### `app/Billing/Exceptions/UnknownPlanException.php` (verbatim, 17 regels)

```php
<?php

declare(strict_types=1);

namespace App\Billing\Exceptions;

use RuntimeException;

final class UnknownPlanException extends RuntimeException
{
    public static function forSlug(string $slug): self
    {
        return new self(sprintf(
            'Onbekende plan-slug: "%s". Definieer in config/billing-plans.php.',
            $slug,
        ));
    }
}
```

### `tests/Unit/Billing/PlanResolverTest.php` (87 regels)

- PHPUnit-test in `Tests\Unit\Billing`-namespace, extends `Tests\TestCase`.
- Geen `RefreshDatabase`-trait (pure config-test, geen DB).
- `setUp()` configureert 2 plans via `config(['billing-plans' => [...]])` met realistische placeholder-prijzen (`49.00` + `29.00`).
- 6 PHPUnit-tests die alle assertions uit de plan-`<behavior>`-spec dekken.

## Test-resultaat

```
{"tool":"phpunit","result":"passed","tests":218,"passed":218,"assertions":721,"duration_ms":4261,"incomplete":1}
```

- Pre-plan baseline: 212 passed (06-03 baseline)
- Post-plan: **218 passed, 721 assertions, 4.3s** (6 nieuw, 0 regressies)
- 1 incomplete (zelfde marker als pre-plan; geen PlanResolver-relatie)

### 6 nieuwe tests (alle GREEN)

1. `test_find_returns_plan_array_for_known_slug` — `find('naschool-license')` heeft `amount`/`interval`/`description` keys + `interval === '1 month'`.
2. `test_find_throws_unknown_plan_exception_for_unknown_slug` — `find('does-not-exist')` gooit `UnknownPlanException`; message bevat `'does-not-exist'` (regex-match).
3. `test_all_returns_indexed_array_of_all_configured_plans` — `all()` heeft count = 2, keys = `naschool-license` + `planny-license`.
4. `test_returned_plan_shape_matches_cashier_expected_shape` — `amount` is array; `amount.value` is string matching `/^\d+\.\d{2}$/`; `amount.currency === 'EUR'`.
5. `test_find_is_case_sensitive` — `find('Naschool-License')` (capitalized) gooit `UnknownPlanException`; slugs zijn lowercase per Hub-conventie.
6. `test_resolver_is_container_bindable` — `app(PlanResolver::class)` resolves zonder error.

## Decisions Made

- **Plan-prijzen op `'0.00'` placeholder gehouden.** D-05 specifieert dat business-cijfers later komen. Mollie weigert €0.00 subscriptions = bevestigde safety-net tegen deploy-zonder-input. Plan 06-05 executor overschrijft deze waardes met bizz-cijfers.
- **PlanResolver is stateless + zonder constructor-deps.** Leest alleen `config()`-helper. Container resolves via default-constructor (`new PlanResolver()`) — test 6 bewijst dat dit werkt zonder explicit binding in een service-provider. Bij toekomstige caching-eisen (bv. `Cache::rememberForever`) kan de class een `CacheRepository`-dep krijgen, maar v0.2 heeft die complexiteit niet nodig.
- **Cashier-vendor-tree intact.** `config/cashier_plans.php` (gepubliceerd in 06-02) wordt NIET aangeraakt. PlanResolver leest exclusief uit `config/billing-plans.php`. Plan 06-05 zal ofwel (a) ook van `billing-plans` lezen, of (b) bij boot de inhoud van `billing-plans` mergen in `cashier_plans` runtime-config — dat besluit valt in 06-05's CONTEXT.
- **Test 1's plan-prijs is `'49.00'`, niet `'0.00'`.** In de test-config (niet in `config/billing-plans.php`!) zetten we realistische placeholder-prijzen (`49.00` voor naschool-license + `29.00` voor planny-license). Reden: Test 4 asserteert dat `amount.value` matched `/^\d+\.\d{2}$/`. `'0.00'` matched die regex óók, dus het maakt voor assertion-gedrag niet uit — maar realistische bedragen maken de test self-documenting voor toekomstige readers.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Stray write naar main repo bij eerste Task 1-Write**

- **Found during:** Task 1 — direct na de eerste `Write`-call landde `tests/Unit/Billing/PlanResolverTest.php` in de main-repo i.p.v. de worktree. De `<parallel_execution>`-block waarschuwt expliciet voor "past plans had stray writes to main repo" — dit is exact dat issue.
- **Fix:** File via `mv` verplaatst naar de juiste worktree-path (`/Users/yusufkaracaburun/Sites/localhost/emeq-hub/.claude/worktrees/agent-ad12295c3131080c8/tests/Unit/Billing/PlanResolverTest.php`) + lege main-repo directory opgeruimd via `rmdir`. Alle volgende `Write`-calls expliciet absolute worktree-paths gebruikt om herhaling te voorkomen.
- **Verification:** `git status` in worktree toont alleen de gewenste untracked files; main-repo's `tests/Unit/` heeft alleen z'n pre-bestaande `ExampleTest.php`.
- **Committed in:** N.v.t. (geen code-wijziging; alleen file-system fix vóór RED-commit).

**Total deviations:** 1 (Rule 3 — file-system fix, geen code-change). Geen Rule-1/2/4 issues; plan-skeleton was correct.

## Threat Flags

Geen — plan voegt geen routes, geen credentials, geen webhook-ingresses, geen DB-schema, geen Mollie-API-calls toe. Attack-surface gelijk aan 06-03 baseline. Threat-register T-06-04-01 t/m T-06-04-03 zijn allemaal `accept` of `mitigate`-via-typed-exception — laatste is ingelost via `UnknownPlanException`.

## Issues Encountered

- **Stray write naar main repo** (zie deviation #1) — gefixt door `mv` + opvolgende absolute paths.
- **`php artisan test --compact tests/Unit/Billing/PlanResolverTest.php`** vond het bestand initieel niet ("Test file not found"); workaround was `./vendor/bin/phpunit tests/Unit/Billing/PlanResolverTest.php` (direct PHPUnit) — dezelfde aanroep die het plan zelf voorschrijft via verify-command. Geen impact op resultaat.
- **`vendor/` en `.env` ontbraken in worktree bij start** — `cp ../../.env .env` + `composer install` (uit main-repo cache, ~snel) nodig vóór RED-run. Identiek aan 06-02/06-03's bootstrap-issue.

## Deferred Items

- **`/docs-sync` skill-pass** — `app/Billing/` is een nieuwe top-level namespace die in `.docs/README.md`-index zou kunnen landen, en in CLAUDE.md's architectuur-pointers. Niet uitgevoerd in plan-scope (zelfde reasoning als 06-03 deferred-items: skill-pass kan `.docs/` + `CLAUDE.md` aanraken — buiten chirurgische `files_modified`-whitelist). Aanbeveling: user runt `/docs-sync` losse pass na merge.
- **Service-provider-binding voor PlanResolver** — niet strikt nodig (default-constructor + container-auto-resolution werken), maar als plan 06-05 een dependency wil injecteren (bv. caching) kan een expliciete binding in `app/Providers/AppServiceProvider.php` waarde toevoegen. Niet in scope hier.

## Next Phase Readiness

**Klaar voor plan 06-05 (newSubscription-flow + billing routes):**
- `App\Billing\PlanResolver::find('naschool-license')` is callable + retourneert Cashier-shape array.
- `$resolver->all()` levert lijst voor admin-API of Filament-resource (Phase 9).
- `UnknownPlanException` is typed + heeft slug in message — debug-vriendelijk voor route-Form-Request slug-validatie.

**Klaar voor plan 06-06 (Cashier webhook controller):**
- Plan-resolver staat los van webhook-flow; geen blocking dependency.

**Blockers:** geen. Plans 06-05 + 06-06 zijn ontblokt.

---

*Phase: 06-cashier-mollie-integratie-use-case-a*
*Plan: 04*
*Completed: 2026-05-15*

## Self-Check: PASSED

- FOUND: `.planning/phases/06-cashier-mollie-integratie-use-case-a/06-04-SUMMARY.md` (this file)
- FOUND: `tests/Unit/Billing/PlanResolverTest.php`
- FOUND: `config/billing-plans.php`
- FOUND: `app/Billing/PlanResolver.php`
- FOUND: `app/Billing/Exceptions/UnknownPlanException.php`
- FOUND: commit `c91c224` (Task 1 — RED test)
- FOUND: commit `756406c` (Task 2 — GREEN implementation)
- OK: `final class PlanResolver` met `find(string): array` + `all(): array`
- OK: `final class UnknownPlanException extends RuntimeException` + `::forSlug()`-factory
- OK: `config/billing-plans.php` bevat `naschool-license` + `planny-license` met EUR + `'1 month'` interval
- OK: 6/6 PlanResolverTest passed, 218/218 full suite passed (0 regressies, 212 → 218)
- OK: Cashier-vendor `config/cashier_plans.php` byte-identiek (geen wijzigingen)
- OK: Pint clean (geen normalisatie nodig)
