---
phase: 07-account-level-subscriptions-use-case-b
plan: 01
subsystem: database
tags: [eloquent, postgres, sqlite, migration, factory, fk-constraints, partial-unique-index, mollie, subscriptions]

# Dependency graph
requires:
  - phase: 03-hub-skeleton
    provides: Account + Connection-tabel + Eloquent-modellen
  - phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
    provides: Mollie-pass-through-pattern + Connection-resolver-binding (referentie)
provides:
  - "account_subscriptions-tabel met D-03 schema (19 D-03-velden, 4 indexes, partial unique op (connection_id, mollie_subscription_id))"
  - "AccountSubscription Eloquent-model met Fillable-attribute + casts() + belongsTo(Account/Connection)"
  - "AccountSubscriptionFactory met pending/active/paused/canceled/forConnection states"
  - "hasMany(AccountSubscription::class) op Account + Connection"
  - "7 feature-tests die persist-, relatie- en FK-constraint-gedrag bewijzen"
affects: [07-02-state-machine-enum, 07-03-manager-service, 07-04-controllers, 07-05-webhook-router, 07-06-feature-tests, 07-07-integration]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Migration: Schema::create + DB::statement(partial-unique-index) — analog: 2026_05_15_000001_create_pass_through_calls_table"
    - "Model: #[Fillable] attribute + protected function casts() (Laravel 11+ style) — analog: PassThroughCall"
    - "Factory FK-chain via Account::factory() + Connection::factory()->forMollie()->for($account) — analog: PassThroughCallFactory"
    - "Factory states gespiegeld aan ConnectionFactory (pending/active/...)"

key-files:
  created:
    - "database/migrations/2026_05_18_000001_create_account_subscriptions_table.php"
    - "app/Models/AccountSubscription.php"
    - "database/factories/AccountSubscriptionFactory.php"
    - "tests/Feature/Models/AccountSubscriptionTest.php"
  modified:
    - "app/Models/Account.php — accountSubscriptions() hasMany toegevoegd"
    - "app/Models/Connection.php — accountSubscriptions() hasMany toegevoegd + HasMany-import"

key-decisions:
  - "Geen `status => SubscriptionStatus::class`-cast op het model in Plan 07-01 (komt in 07-02) — vermijdt class-not-found tijdens deze tests"
  - "Cascade-volgorde-test (Test 4) volgt admin-volgorde (subs → connections → account); directe Account::delete() onder Postgres aborteert via restrict-FK op connection_id (T-07-01-03 accepteert dat)"
  - "Postgres-only pg_constraint-assertion in Test 4 als extra schema-bewijs; SQLite-loop laat 'm netjes vallen"

patterns-established:
  - "Forward-only migration met partial unique index via DB::statement na Schema::create"
  - "Factory met state-methodes die zowel `for*Provider*` (forConnection) als status-flips (pending/active/...) ondersteunen"
  - "Feature-test gebruikt RefreshDatabase + driver-aware assertions voor DB-specifieke constraint-bewijzen"

requirements-completed: [SUB-02]  # Foundation-laag — verdere SUB-02-criteria worden in 07-02 t/m 07-08 afgerond

# Metrics
duration: 21min
completed: 2026-05-15
---

# Phase 7 Plan 01: AccountSubscription persistentie-laag Summary

**Multi-tenant AccountSubscription-tabel + Eloquent-model + factory met D-03 schema (19 velden, partial unique index op `(connection_id, mollie_subscription_id) WHERE NOT NULL`), klaar als deterministische fundering voor 07-02 t/m 07-08.**

## Performance

- **Duration:** ~21 min
- **Started:** 2026-05-15T15:35:34Z
- **Completed:** 2026-05-15T15:56:03Z
- **Tasks:** 2
- **Files modified:** 6 (2 modified, 4 created)

## Accomplishments

- Forward-only migration `2026_05_18_000001_create_account_subscriptions_table` met D-03 schema: 19 functionele velden + 4 indexes waarvan 1 partial unique (`account_subscriptions_connection_mollie_sub_unique`).
- `AccountSubscription`-Eloquent-model met Fillable-attribute (19 velden), casts (`metadata=>array`, alle timestamps + date), belongsTo(Account) + belongsTo(Connection).
- `AccountSubscriptionFactory` met `pending`/`active`/`paused`/`canceled`/`forConnection`-states.
- Chirurgische `hasMany(AccountSubscription::class)`-relatie toegevoegd aan Account.php én Connection.php.
- 7 feature-tests groen (17 assertions): factory-persist in pending, hasMany-relatie vanaf Account én Connection, partial unique blokkeert duplicate active mollie_subscription_id, NULL-id's zijn meervoudig toegestaan, admin-cascade-volgorde + restrict-FK gedrag.
- Volledige suite: 249 passed / 1 incomplete (pre-existing). Geen regressie op Phase 3/5a/6-tests.

## Task Commits

1. **Task 1: Migration + model + factory + relaties** — `248891b` (feat)
2. **Task 2: Feature-test voor persist + relaties + partial unique + cascade/restrict** — `7f50a1d` (test)

## Files Created/Modified

- `database/migrations/2026_05_18_000001_create_account_subscriptions_table.php` (created) — D-03 schema + partial unique index.
- `app/Models/AccountSubscription.php` (created) — Fillable + casts + belongsTo-relaties.
- `database/factories/AccountSubscriptionFactory.php` (created) — pending/active/paused/canceled/forConnection states.
- `app/Models/Account.php` (modified, +5 regels) — `accountSubscriptions()`-relatie.
- `app/Models/Connection.php` (modified, +6 regels) — `accountSubscriptions()`-relatie + `HasMany`-import.
- `tests/Feature/Models/AccountSubscriptionTest.php` (created) — 7 feature-tests met RefreshDatabase.

## Decisions Made

- **Enum-cast pas in 07-02.** Het plan vraagt expliciet géén `'status' => SubscriptionStatus::class`-cast in deze fase. Voorkomt class-not-found-failure in tests omdat de enum-class pas in 07-02 wordt aangemaakt.
- **Test 4 onder admin-volgorde i.p.v. directe `Account::delete()`.** D-03 cascade op `account_id` + restrict op `connection_id` aborteert onder Postgres een directe Account-delete (RESTRICT op subs.connection_id blokkeert de account→connections-cascade). T-07-01-03 accepteert dat — admin-flow ruimt eerst subs + connections op. De test bewijst dat die volgorde clean afsluit; onder Postgres voegt het test bovendien een `pg_constraint.confdeltype = 'c'`-assertion toe als bewijs van de CASCADE-disposition.
- **Driver-aware test-asserties.** PHPUnit-suite draait standaard op SQLite (`:memory:`), maar productie is Postgres. Postgres-specifieke checks staan achter `DB::connection()->getDriverName() === 'pgsql'`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Test 4 cascade-volgorde gecorrigeerd**
- **Found during:** Task 2 (Feature-test schrijven)
- **Issue:** Het plan vroeg `$account->delete()` om alle gerelateerde `account_subscriptions`-rijen te cascaderen, maar D-03's FK-rules (account_id=cascade + connection_id=restrict) blokkeren die directe cascade onder Postgres — de account→connections-cascade aborteert op de restrict-FK van `account_subscriptions.connection_id`.
- **Fix:** Test 4 volgt nu de admin-flow uit T-07-01-03 (subs → connections → account); voegt onder Postgres een `pg_constraint`-assertion toe om de cascade-disposition van de account_id-FK direct te bewijzen.
- **Files modified:** `tests/Feature/Models/AccountSubscriptionTest.php`
- **Verification:** 7/7 tests groen, geen regressie in full suite.
- **Committed in:** `7f50a1d` (Task 2 commit)

**2. [Rule 3 - Blocking] Vendor-symlink veroorzaakte fout-basePath in `php artisan test`**
- **Found during:** Task 2 (eerste test-run faalde met "no such table: account_subscriptions")
- **Issue:** De worktree begon met `vendor/` als symlink naar de main-repo's `vendor`. Laravel's `Application::inferBasePath()` valt terug op `dirname(ClassLoader registered path)`, wat door de symlink resolved naar de main-repo. Daardoor las `php artisan test` migrations uit `/main/database/migrations/` i.p.v. de worktree, en bleef onze nieuwe migration buiten beeld.
- **Fix:** Symlink verwijderd en `composer install` lokaal in de worktree gedraaid. Nu is `base_path()` correct (worktree), de migration draait, en `[MIGRATION] account_subscriptions UP called` verschijnt in test-output.
- **Files modified:** Geen (alleen `vendor/` directory in worktree omgezet van symlink naar lokale install).
- **Verification:** Diagnostic-test bewees `base_path` resolved naar worktree; 7/7 AccountSubscriptionTest groen; 249 passed in full suite.
- **Committed in:** N.v.t. — vendor/ is .gitignore'd; geen file-changes om te committen.

---

**Total deviations:** 2 auto-fixed (1 plan-bug correctie, 1 worktree-blocking)
**Impact on plan:** Beide auto-fixes vereist om de plan-tests groen te krijgen. Geen scope-creep — alleen schema/test-laag aangeraakt.

## Issues Encountered

- **Vendor-symlink trap.** Eerste reflex was om `vendor/` te symlinken naar main; PHP CLI accepteert dat, maar `php artisan test` boot via `Application::inferBasePath()` die door de symlink heen kijkt en daardoor de main-repo als basePath ziet. Resultaat: het migrate:fresh-deel van RefreshDatabase miste deze migration. Symptoom (`"no such table"`) was misleidend omdat alle andere tabellen wél bestonden — die zijn alleen via de main-repo's migrations gedekt. Oplossing: `rm vendor && composer install` in de worktree.

## Threat Flags

Geen nieuwe trust-boundary-surface buiten het `<threat_model>` van het plan. Alle 4 STRIDE-rijen (T-07-01-01 t/m T-07-01-04) zijn ge-mitigate of accepted zoals gepland:

- T-07-01-01 (duplicate-insert) — partial unique index actief, bewezen via Test `partial_unique_index_blocks_duplicate_active_mollie_subscription_id`.
- T-07-01-02 (encrypted leakage) — accepted; geen secrets in dit schema.
- T-07-01-03 (cascade-storm) — accepted; admin-flow getoetst via Test 4.
- T-07-01-04 (mass-assignment) — gemitigeerd via `#[Fillable]`-attribute met 19-veld whitelist; geen `id`/`created_at`/`updated_at` in Fillable.

## User Setup Required

None - geen externe services.

## Next Phase Readiness

- **07-02 (state-enum):** kan `'status' => SubscriptionStatus::class` toevoegen aan het model-cast; tabel + factory zijn klaar.
- **07-03 (manager-service):** factory-states `pending`/`active`/etc. zijn beschikbaar voor unit-tests.
- **07-04 t/m 07-08:** schema + relaties + Fillable zijn de fundering die alle volgende plans verwachten.

## Self-Check: PASSED

- `database/migrations/2026_05_18_000001_create_account_subscriptions_table.php` — FOUND
- `app/Models/AccountSubscription.php` — FOUND
- `database/factories/AccountSubscriptionFactory.php` — FOUND
- `tests/Feature/Models/AccountSubscriptionTest.php` — FOUND
- Commit `248891b` — FOUND in git log
- Commit `7f50a1d` — FOUND in git log
- `account_subscriptions_connection_mollie_sub_unique` partial unique index — geverifieerd via pg_indexes inspectie (Postgres) + grep in migration (DB::statement aanwezig)

---
*Phase: 07-account-level-subscriptions-use-case-b*
*Completed: 2026-05-15*
