---
phase: 06-cashier-mollie-integratie-use-case-a
plan: 03
subsystem: cashier-mollie / billable-trait
tags: [cashier-mollie, billable, consumer, eloquent, tdd, sub-01]

# Dependency graph
requires:
  - phase: 06-cashier-mollie-integratie-use-case-a
    plan: 02
    provides: "Cashier-Mollie v2.20.1 installed + subscriptions-tabel + config/cashier.php gepubliceerd"
provides:
  - "App\\Models\\Consumer gebruikt Laravel\\Cashier\\Billable trait (D-03)"
  - "config('cashier.user_model') === App\\Models\\Consumer::class (documentatie + safety-net)"
  - "ConsumerFactory::withActiveSubscription() voor 06-05/06-07 test-setup"
  - "Forward-only migration die owner_type op subscriptions-rijen normaliseert naar Consumer-FQN"
affects:
  - 06-05 (newSubscription-tests gebruiken Consumer + Billable + withActiveSubscription)
  - 06-07 (integration-suite gebruikt Consumer als Billable)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "TDD RED-first per task (RED-commit faalt op trait-presence + 4 undefined-methods; GREEN-commit groen)"
    - "Billable-trait op Authenticatable-model coexisteert met HasApiTokens + HasFactory (alfabetische trait-volgorde)"
    - "Forward-only owner_type-normalize migration (no-op op verse install, dekt staging-edge-case)"
    - "Factory-state die DB-rijen direct insert zonder Mollie-API (test-isolatie)"

key-files:
  created:
    - "tests/Feature/Billing/ConsumerBillableTest.php"
    - "database/migrations/2026_05_17_000001_align_subscriptions_owner_to_consumers.php"
  modified:
    - "app/Models/Consumer.php"
    - "config/cashier.php"
    - "database/factories/ConsumerFactory.php"

key-decisions:
  - "Test 5 design afgeweken van plan: plan testte mollieCustomerId(), maar die method roept bij lege kolom direct createAsMollieCustomer() aan (live Mollie-API-hit — onveilig in unit-suite). Vervangen door mollieMandateId()-assert (pure accessor, geen side-effect)."
  - "Pint normaliseerde \\App\\Models\\Consumer::class naar use-import + Consumer::class in config/cashier.php en ConsumerFactory.php — equivalent gedrag, schonere imports."
  - "Migration is no-op op verse install (subscriptions-tabel leeg na 06-02 publish); bestaat voor consistency-documentatie + staging-edge-case dekking."

# Metrics
duration: 22min
completed: 2026-05-15
requirements-completed: []  # SUB-01 blijft in-progress; trait + routes + webhook nog niet 100% ingelost
---

# Phase 6 Plan 03: Billable-trait op Consumer Summary

**`App\Models\Consumer` gebruikt nu `Laravel\Cashier\Billable`, met 5/5 RED→GREEN tests, 212/212 totaal groen, en `ConsumerFactory::withActiveSubscription()` als test-helper voor latere plans.**

## Performance

- **Duration:** ~22 min
- **Started:** 2026-05-15 (na composer install in worktree-bootstrap)
- **Completed:** 2026-05-15 (na full-suite-pass + Pint)
- **Tasks:** 2 (RED-tests + GREEN-implementation)
- **Files created:** 2 (1 test-class + 1 migration)
- **Files modified:** 3 (Consumer model + cashier config + ConsumerFactory)

## Accomplishments

- **D-03 ingelost:** `Consumer` extends `Authenticatable` + `use Billable, HasApiTokens, HasFactory;` (alfabetisch). `class_uses_recursive(Consumer::class)` bevat nu `Laravel\Cashier\Billable`.
- **D-04 ingelost door uitsluiting:** `app/Models/Account.php` is byte-identiek aan pre-plan-state (`git log a3d917c..HEAD -- app/Models/Account.php` = leeg).
- **5 RED-tests committed (`c902966`) → 5 GREEN-tests bewezen (`99e81b5`):** trait-presence, subscribed()-false, subscriptions-relation-empty, polymorf-owner-Consumer-FQN, mollieMandateId-null-when-absent.
- **0 regressies:** full suite van 207 (06-02 baseline) → 212 (5 nieuw), zelfde duration-profile (~4s).
- **`config('cashier.user_model')` resolves naar `App\Models\Consumer`** — bewezen via `php artisan config:show cashier.user_model`.
- **Forward-only migration** `2026_05_17_000001_align_subscriptions_owner_to_consumers.php` aanwezig; no-op op verse install.
- **Pint clean** — 2 files genormaliseerd (`config/cashier.php`, `database/factories/ConsumerFactory.php`) door `fully_qualified_strict_types` + `ordered_imports`.

## Task Commits

1. **Task 1 — RED:** `c902966` (test) — `tests/Feature/Billing/ConsumerBillableTest.php` (86 regels, 5 tests, faalde 5/5)
2. **Task 2 — GREEN:** `99e81b5` (feat) — Consumer + cashier.user_model + migration + factory-state (4 files, +75/-1)

## Files Created/Modified

### Created

**`tests/Feature/Billing/ConsumerBillableTest.php`** (86 regels)
- 5 PHPUnit-tests (PHPUnit-conventie, niet Pest — per Hub-conventie).
- `use RefreshDatabase` voor schone subscriptions-tabel per test.
- Imports: `App\Models\Consumer`, `Illuminate\Support\Facades\DB`, `Laravel\Cashier\Billable`, `Tests\TestCase`.
- Geen `StubsMollieClient`-mix-in — pure DB-state tests, geen Mollie-API-hit.

**`database/migrations/2026_05_17_000001_align_subscriptions_owner_to_consumers.php`** (verbatim, 32 regels):

```php
<?php

declare(strict_types=1);

use App\Models\Consumer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscriptions')) {
            return;
        }

        DB::table('subscriptions')
            ->where(function ($query) {
                $query->whereNull('owner_type')->orWhere('owner_type', '');
            })
            ->update(['owner_type' => Consumer::class]);
    }

    public function down(): void
    {
        // Forward-only.
    }
};
```

### Modified

**`app/Models/Consumer.php`** — Billable-trait toegevoegd. Diff:

```php
 use Database\Factories\ConsumerFactory;
 use Illuminate\Database\Eloquent\Attributes\Fillable;
 use Illuminate\Database\Eloquent\Factories\HasFactory;
 use Illuminate\Database\Eloquent\Relations\HasMany;
 use Illuminate\Foundation\Auth\User as Authenticatable;
+use Laravel\Cashier\Billable;
 use Laravel\Sanctum\HasApiTokens;

 #[Fillable(['name', 'slug', 'webhook_callback_url', 'webhook_callback_secret'])]
 class Consumer extends Authenticatable
 {
     /** @use HasFactory<ConsumerFactory> */
-    use HasApiTokens, HasFactory;
+    use Billable, HasApiTokens, HasFactory;
```

Géén verdere wijzigingen: bestaande `casts()` en `accounts()`-relation intact.

**`config/cashier.php`** — `user_model`-key toegevoegd:

```php
use App\Models\Consumer;
use Laravel\Cashier\Order\OrderNumberGenerator;

return [
    // ... webhook_url, aftercare_webhook_url, locale ...

    /*
     * The Billable model. Phase 6 D-03: Consumer is de billable in use-case A
     * (Emeq → Consumers via Emeq's eigen Mollie). NIET Account (D-04).
     *
     * Cashier-Mollie ^2.20 leest deze key niet automatisch — toegevoegd als
     * documentatie + safety-net voor toekomstige Cashier-versies en eigen
     * resolver-code die `config('cashier.user_model')` kan lezen.
     */
    'user_model' => Consumer::class,
    // ...
];
```

**`database/factories/ConsumerFactory.php`** — `withActiveSubscription()`-state toegevoegd:

```php
public function withActiveSubscription(string $planSlug = 'naschool-license', string $subscriptionName = 'main'): static
{
    return $this->afterCreating(function (Consumer $consumer) use ($planSlug, $subscriptionName): void {
        DB::table('subscriptions')->insert([
            'name' => $subscriptionName,
            'plan' => $planSlug,
            'owner_id' => $consumer->id,
            'owner_type' => Consumer::class,
            'quantity' => 1,
            'tax_percentage' => 21,
            'cycle_started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
}
```

Pint normaliseerde `\Illuminate\Support\Facades\DB::` naar `use Illuminate\Support\Facades\DB;` + `DB::`-call.

## Test-resultaat

```
{"tool":"phpunit","result":"passed","tests":212,"passed":212,"assertions":704,"duration_ms":3924,"incomplete":1}
```

- Pre-plan: 207 passed (06-02 baseline)
- Post-plan: **212 passed, 704 assertions, 3.9s** (5 nieuw, 0 regressies)
- 1 incomplete (zelfde marker als pre-plan; geen Cashier-relatie)

### 5 nieuwe tests (alle GREEN)

1. `test_consumer_uses_billable_trait` — `class_uses_recursive(Consumer::class)` bevat `Laravel\Cashier\Billable`.
2. `test_consumer_subscribed_returns_false_when_no_subscription` — `Consumer::factory()->create()->subscribed('main')` = false.
3. `test_consumer_subscriptions_relation_returns_empty_collection` — `$consumer->subscriptions` = collection met 0 items.
4. `test_subscriptions_table_polymorphic_owner_uses_consumer_class` — DB-insert met `owner_type = Consumer::class` + `owner_id = $consumer->id` → na `$consumer->refresh()` heeft `subscriptions()->count() === 1` met juiste `plan`-veld.
5. `test_consumer_mollie_mandate_id_returns_null_when_no_mandate` — `mollieMandateId()` = null + `getMollieMandateIdColumn()` = `'mollie_mandate_id'`.

## Decisions Made

- **Test 5 design afgeweken van plan-text.** Plan beschreef Test 5 als `$consumer->mollieCustomerId()` returns null. Code-inspection van `vendor/mollie/laravel-cashier-mollie/src/Billable.php:148-155` toont dat deze method bij een lege kolom direct `createAsMollieCustomer()` aanroept (live Mollie-API-hit — `app(CreateMollieCustomer::class)->execute(...)`). Unit-test zonder service-binding zou crashen of de live API hitten. Vervangen door `mollieMandateId()` (pure getter, regel 447-450). Bewijst hetzelfde succes-criterion: Cashier-accessors crashen niet op een Consumer zonder billing-state. Plan's `<truths>`-uitspraak "lazy-creates customer-record-bound state — retourneert string of null" is incorrect — actuele Cashier-Mollie code triggert API-call. Plan 06-05 zal `createAsMollieCustomer()` testen mét binding-stub (zelfde pattern als Phase 5a's `StubsMollieClient`).
- **`subscriptions` insert-payload geverifieerd tegen gepubliceerde Cashier-migration.** Plan-skeleton listte velden zoals `mollie_subscription_id` + `mollie_mandate_id` + `status` als verplicht — verificatie van `database/migrations/2026_05_15_074719_create_subscriptions_table.php` toont dat deze velden niet bestaan in Cashier v2.20.1's schema. NOT NULL kolommen zijn: `name`, `plan`, `owner_type`, `owner_id`, `cycle_started_at` (+ `id`, `timestamps` standaard). Andere velden (`next_plan`, `quantity` default 1, `tax_percentage` default 0, `ends_at`, `trial_ends_at`, `cycle_ends_at`, `scheduled_order_item_id`) zijn nullable of hebben defaults. Insert-payload aangepast: alleen verplichte velden + `quantity` + `tax_percentage` voor expliciete waarde.
- **Pint genormaliseerd: 2 files.** `config/cashier.php` en `database/factories/ConsumerFactory.php` kregen `fully_qualified_strict_types` + `ordered_imports` fixers. Equivalent gedrag, schonere imports. Geen extra commit (in dezelfde GREEN-commit gestaged).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug in plan] Test 5 zou live Mollie-API hitten**

- **Found during:** Task 1 — bij het schrijven van Test 5 op basis van plan-text las ik `vendor/mollie/laravel-cashier-mollie/src/Billable.php:148-155` om de juiste assert-shape te bepalen.
- **Issue:** Plan's Test 5 was `assertNull($consumer->mollieCustomerId())`. Code toont dat `mollieCustomerId()` bij empty column direct `createAsMollieCustomer()` aanroept → `app()->make(CreateMollieCustomer::class)->execute(...)` → live Mollie-customers-API-POST. Unit-test zonder service-binding stub zou (a) crashen op missing binding, óf (b) hitten op de echte API als binding wel gevonden wordt. Plan's `<truths>`-claim "retourneert string of null" stemt niet met deze code overeen.
- **Fix:** Test 5 omgewerkt naar `assertNull($consumer->mollieMandateId())` + `assertSame('mollie_mandate_id', $consumer->getMollieMandateIdColumn())`. `mollieMandateId()` is een pure getter (regel 447-450) — geen API-side-effect — en bewijst hetzelfde succes-criterion: een Consumer zonder billing-state kan Cashier-accessor-methodes aanroepen zonder error.
- **Files modified:** `tests/Feature/Billing/ConsumerBillableTest.php` (alleen Test 5 method-naam + implementatie).
- **Verification:** RED-commit `c902966` toont 1 assertion-fail + 4 undefined-method-errors. GREEN-commit `99e81b5`: 5/5 passed.
- **Committed in:** `c902966` (RED), `99e81b5` (GREEN).

**2. [Rule 3 — Blocking] Plan-skeleton insert-payload bevatte velden die niet in Cashier v2.20.1's subscriptions-schema zitten**

- **Found during:** Task 1 — bij het schrijven van de polymorf-owner-test las ik de gepubliceerde migration `database/migrations/2026_05_15_074719_create_subscriptions_table.php` (uit 06-02).
- **Issue:** Plan-skeleton voor Test 4 (DB::insert) listte `mollie_subscription_id`, `mollie_mandate_id`, `status` als verplichte velden. Verificatie van Cashier-Mollie's eigen migration toont dat deze velden NIET bestaan in v2.20.1's `subscriptions`-tabel. Cashier-Mollie tracked subscription-state via `cycle_started_at`/`cycle_ends_at`/`ends_at`, niet via een status-enum. Insert met deze velden zou Postgres/SQLite-error geven ("column does not exist"). Zelfde issue als 06-02's plan-acceptance-mismatch met `subscription_items`-tabel.
- **Fix:** Insert-payload aligned op upstream-werkelijkheid. NOT NULL kolommen: `name`, `plan`, `owner_type`, `owner_id`, `cycle_started_at` (+ `id`, `timestamps`). Andere velden weggelaten of explicit gezet (`quantity = 1`, `tax_percentage = 21`).
- **Files modified:** Test 4 in `tests/Feature/Billing/ConsumerBillableTest.php` + `withActiveSubscription()` in `database/factories/ConsumerFactory.php`. Beide insert-payloads consistent.
- **Verification:** Test 4 + factory-state werken — 5/5 GREEN. Plan 06-05 die `withActiveSubscription` gebruikt zal hierop bouwen.
- **Committed in:** `c902966` (Test 4), `99e81b5` (factory-state).

---

**Total deviations:** 2 auto-fixed (1 Rule-1 bug in plan-test-design, 1 Rule-3 blocking schema-mismatch). Beide essentieel om Task 1 te kunnen voltooien. Geen scope-creep — alle wijzigingen blijven binnen plan-`files_modified`-whitelist.

## Threat Flags

Geen — plan voegt geen routes, geen credentials, geen webhook-ingresses toe. Attack-surface gelijk aan 06-02 baseline. Threat-register T-06-03-01 (Spoofing van owner_type) is mitigated via de forward-only migration die owner_type naar Consumer-FQN normaliseert; T-06-03-02 t/m T-06-03-04 zijn accept/mitigate per threat_model in PLAN.

## Issues Encountered

- **Worktree was op base `907a0af` (Phase 2 era) i.p.v. expected `a3d917c` (06-02 head).** `worktree_branch_check`-block reset HEAD naar `a3d917c` — daarna `.planning/phases/06-*` aanwezig, plan files leesbaar.
- **`vendor/` en `.env` ontbraken in worktree** — composer install + `.env` kopie uit main repo nodig vóór tests. Identiek aan 06-02-SUMMARY's `.env`-issue.
- **Plan beschreef Cashier-velden die niet bestaan** (`mollie_subscription_id`, `mollie_mandate_id`, `status` op subscriptions-tabel) — zie deviation #2. Verifieerd door gepubliceerde migration uit 06-02 te lezen.

## Deferred Items

- **`/docs-sync` skill-pass** — PostToolUse:Edit/Write-hooks triggerden de skill bij elke wijziging in `app/Models/Consumer.php` + `database/migrations/2026_05_17_*`. Niet uitgevoerd in plan-scope (zelfde reasoning als 06-01/06-02 deferred-items: skill-pass kan `.docs/README.md`/`CLAUDE.md`/memory aanraken — buiten chirurgische `files_modified`-whitelist). Aanbeveling: user runt `/docs-sync` losse pass vóór `gsd-execute-phase 06-04+`.

## Next Phase Readiness

**Klaar voor plan 06-04 (PlanResolver — config/billing-plans.php + App\Billing\PlanResolver):**
- Consumer is Billable; Cashier kan via Billable-trait subscription-builders op Consumer-instance creëeren.
- `subscriptions`-tabel polymorf gealigneerd op Consumer-FQN.
- `ConsumerFactory::withActiveSubscription()` beschikbaar voor 06-05 + 06-07 test-setup.

**Klaar voor plan 06-05 (newSubscription-flow + billing routes):**
- `$consumer->newSubscription($name, $plan)` is nu een callable method via Billable-trait.
- `$consumer->subscribed($name)` werkt correct (false zonder rij, true met rij — laatste pas geverifieerd in 06-05 met echte plan-resolver).
- `$consumer->createAsMollieCustomer()` is callable; plan 06-05 zal binding-stub leveren voor unit-tests.

**Blockers:** geen. Plans 06-04 + 06-05 zijn ontblokt en parallel-uitvoerbaar.

---

*Phase: 06-cashier-mollie-integratie-use-case-a*
*Plan: 03*
*Completed: 2026-05-15*

## Self-Check: PASSED

- FOUND: `.planning/phases/06-cashier-mollie-integratie-use-case-a/06-03-SUMMARY.md` (this file)
- FOUND: `tests/Feature/Billing/ConsumerBillableTest.php`
- FOUND: `database/migrations/2026_05_17_000001_align_subscriptions_owner_to_consumers.php`
- FOUND: `app/Models/Consumer.php` (modified — Billable + import)
- FOUND: `config/cashier.php` (modified — user_model)
- FOUND: `database/factories/ConsumerFactory.php` (modified — withActiveSubscription)
- FOUND: commit `c902966` (Task 1 — RED test)
- FOUND: commit `99e81b5` (Task 2 — GREEN implementation)
- OK: `Consumer` uses `Billable, HasApiTokens, HasFactory` (alfabetisch)
- OK: `use Laravel\Cashier\Billable;` import in Consumer.php
- OK: `config/cashier.php` heeft `'user_model' => Consumer::class`
- OK: `ConsumerFactory::withActiveSubscription()` aanwezig
- OK: `app/Models/Account.php` byte-identiek (D-04 ingelost door uitsluiting)
- OK: 5/5 ConsumerBillableTest passed, 212/212 full suite passed (0 regressies)
- OK: `config('cashier.user_model') === 'App\Models\Consumer'` (artisan config:show geverifieerd)
- OK: Pint clean (2 files auto-fixed in dezelfde GREEN-commit)
