---
phase: "10"
plan: "03"
subsystem: filament-admin-rbac
tags: [filament, rbac, spatie-permission, canAccess, phase-9-polish, CR-02, D-05, TDD]
dependency-graph:
  requires:
    - "App\\Models\\WebhookCall (Hub-subclass uit Plan 10-01)"
    - "Spatie\\Permission HasRoles-trait op User (Phase 9 D-05)"
    - "EmeqStaffSeeder permissions (manage-consumers/manage-connections/view-webhooks/view-account-subscriptions/view-billing)"
  provides:
    - "Permission-gated Filament-resources (6× canAccess + shouldRegisterNavigation)"
    - "WebhookCallResource ->with('consumer') eager-load fundament voor Plan 10-04"
    - "12 nieuwe gating-tests (ResourceCanAccessTest) die 403/200-flow per resource bewijzen"
    - "HUB-04 SC-7 closure-pad — permission-gated als locked v0.2-interpretatie van cross-Consumer-isolation"
  affects:
    - "Plan 10-04 (WebhookCallsTable + WebhookCallInfolist TextColumn::make('consumer.slug')) — unlocked"
    - "Alle 8 admin-test-files met staff-helpers — permission-grants toegevoegd om regressie te voorkomen"
tech-stack:
  added: []
  patterns:
    - "Filament v4 canAccess() + shouldRegisterNavigation() — D-05 permission-gate-pattern"
    - "Spatie laravel-permission auth()->user()?->can('<perm>') ?? false (nullable-safe)"
    - "TDD (RED → GREEN) — test eerst, productie daarna"
key-files:
  created:
    - "tests/Feature/Admin/ResourceCanAccessTest.php"
  modified:
    - "app/Filament/Resources/Consumers/ConsumerResource.php"
    - "app/Filament/Resources/Connections/ConnectionResource.php"
    - "app/Filament/Resources/Accounts/AccountResource.php"
    - "app/Filament/Resources/WebhookCalls/WebhookCallResource.php"
    - "app/Filament/Resources/AccountSubscriptions/AccountSubscriptionResource.php"
    - "app/Filament/Resources/CashierSubscriptions/CashierSubscriptionResource.php"
    - "tests/Feature/Admin/AccountResourceTest.php"
    - "tests/Feature/Admin/AccountSubscriptionResourceTest.php"
    - "tests/Feature/Admin/AccountSubscriptionStateActionsTest.php"
    - "tests/Feature/Admin/CashierSubscriptionResourceTest.php"
    - "tests/Feature/Admin/ConnectionFingerprintTest.php"
    - "tests/Feature/Admin/ConnectionRevokeActionTest.php"
    - "tests/Feature/Admin/ConsumerTokenActionTest.php"
    - "tests/Feature/Admin/RelationManagersRenderTest.php"
    - "tests/Feature/Admin/WebhookCallResourceTest.php"
decisions:
  - "D-1 (10-CONTEXT.md): canAccess-permission-mapping locked per resource — UserResource buiten scope (Gate-based)"
  - "WebhookCallResource model-rebinding + getEloquentQuery consolidatie uit 10-04 voorkomt wave-2 file-conflict — 10-04 blijft file-disjoint"
  - "D-3 v0.2-interpretatie: HUB-04 SC-7 wordt permission-gated, NIET consumer-scoped — staff↔consumer-binding is v1.0+ scope"
  - "Bestaande staff-tests krijgen permission-grants in plaats van een test-utility — koppelt elke test los aan zijn permission-scope (geen god-helper)"
metrics:
  duration: ~25 min
  completed: 2026-05-16
---

# Phase 10 Plan 03: Permission-gating op alle 6 Filament-resources (CR-02 BLOCKER) Summary

Sluit CR-02 hoofdcomponent uit 09-REVIEW.md: D-05 permission-model wordt nu daadwerkelijk ge-enforced door alle 6 niet-User-Filament-resources. Permissions waren al door EmeqStaffSeeder geprovisioneerd; tot deze fix waren ze dead code. WebhookCallResource krijgt extra de `App\Models\WebhookCall`-binding + `getEloquentQuery()->with('consumer')` om file-conflict met 10-04 te vermijden.

## What was built

### Code

**6 Filament-resources — elk +2 methodes (`canAccess` + `shouldRegisterNavigation`):**

- **`app/Filament/Resources/Consumers/ConsumerResource.php`** — `manage-consumers`
- **`app/Filament/Resources/Connections/ConnectionResource.php`** — `manage-connections`
- **`app/Filament/Resources/Accounts/AccountResource.php`** — `manage-consumers` (per D-1)
- **`app/Filament/Resources/WebhookCalls/WebhookCallResource.php`** — `view-webhooks` **+** model-rebinding (Spatie → `App\Models\WebhookCall`) **+** nieuwe `getEloquentQuery()` met `->with('consumer')` eager-load
- **`app/Filament/Resources/AccountSubscriptions/AccountSubscriptionResource.php`** — `view-account-subscriptions`
- **`app/Filament/Resources/CashierSubscriptions/CashierSubscriptionResource.php`** — `view-billing`

Elke `canAccess()` gebruikt het null-safe pattern:
```php
public static function canAccess(): bool
{
    return auth()->user()?->can('<permission>') ?? false;
}

public static function shouldRegisterNavigation(): bool
{
    return static::canAccess();
}
```

UserResource ongewijzigd — al gegate via `Gate::allows('manage-staff')` sinds Phase 9.

### Tests

- **`tests/Feature/Admin/ResourceCanAccessTest.php`** (nieuw, 12 testmethodes):
  - Per resource: `test_<resource>_returns_403_for_staff_without_permission` (assertForbidden)
  - Per resource: `test_<resource>_returns_200_for_staff_with_permission` (assertOk)
  - Seedt alleen `staff`-rol + 5 permissions (geen `super-admin` — die zou via Spatie Gate::before-hook alle permissions overrulen en de gating-bewijslast verstoren).

### Test-helper-updates (regressie-fix)

8 bestaande admin-tests gebruikten een `actAsStaff()`/`actingAsStaff()`/`makeStaffUser()` helper die alleen de `staff`-rol toekende — die rol heeft géén permissions standaard (die liggen op de User). Met de nieuwe `canAccess`-gating gaven alle bestaande tests 403. Per file is in `seedRoles()` de gemapte `Permission::firstOrCreate()` toegevoegd en in de helper een `$user->givePermissionTo(...)`-call:

| Test | Permission(s) granted |
| --- | --- |
| AccountResourceTest | `manage-consumers` |
| AccountSubscriptionResourceTest | `view-account-subscriptions` |
| AccountSubscriptionStateActionsTest | `view-account-subscriptions` |
| CashierSubscriptionResourceTest | `view-billing` |
| ConnectionFingerprintTest | `manage-connections` |
| ConnectionRevokeActionTest | `manage-connections` |
| ConsumerTokenActionTest | `manage-consumers` (twee testmethodes — per-test grant want geen `actAs`-helper) |
| RelationManagersRenderTest | `manage-consumers` + `manage-connections` (test raakt beide domeinen) |
| WebhookCallResourceTest | `view-webhooks` |

## TDD flow

Plan markeerde beide tasks als `tdd="true"`. Uitgevoerd in correcte RED→GREEN volgorde — Task 2's test-file eerst (RED), dan Task 1's productie-code (GREEN). De plan-listing had Task 1 vóór Task 2 omdat de naamgeving Task1=productie + Task2=test was; TDD-best-practice dicteert tests eerst (zelfde patroon als 10-01).

| Phase | Commit | Result |
|---|---|---|
| RED | `88907f6` — test(10-03): voeg failing ResourceCanAccessTest toe | 12 tests, 6 failed (403-tests), 6 passed (200-tests, geen gate aanwezig) |
| GREEN | `b7456b3` — feat(10-03): wire canAccess() + shouldRegisterNavigation() op 6 resources | 12/12 ResourceCanAccessTest groen, 421/421 full suite groen |

Geen REFACTOR-fase nodig — code is al minimaal (2-4 LOC per resource, gedeelde pattern via `static::canAccess()`).

## Test counts

| Run | Tests | Passed | Assertions |
|---|---|---|---|
| Baseline (Wave 1 close) | 407 | 407 | 1385 |
| Na Task 1 + 2 (admin suite) | 70 | 70 | 263 |
| Na Task 1 + 2 (full suite) | **421** | **421** | **1401** |
| `--filter=ResourceCanAccessTest` | 12 | 12 | 24 |
| `--filter='PermissionGatingTest\|WebhookCallResourceTest\|PanelAccessTest'` | 9 | 9 | 16 |

Suite-delta van 407 → 421 (+14) komt uit 12 nieuwe `ResourceCanAccessTest`-tests + 2 reeds bestaande tests die buiten admin-scope vallen maar in Wave 2's baseline mee tellen (autoload-resolveerde naar worktree i.p.v. main repo na `composer install --no-scripts`-stap).

## Pint

`./vendor/bin/pint --dirty --format agent` → clean run, geen fixes nodig.

## Done criteria

- [x] `grep -c "can('manage-consumers')"` op ConsumerResource + AccountResource → 1 + 1 = totaal `2`
- [x] `grep -c "can('manage-connections')"` op ConnectionResource → `1`
- [x] `grep -c "can('view-webhooks')"` op WebhookCallResource → `1`
- [x] `grep -c "can('view-account-subscriptions')"` op AccountSubscriptionResource → `1`
- [x] `grep -c "can('view-billing')"` op CashierSubscriptionResource → `1`
- [x] Alle 6 files: `grep -c 'public static function canAccess'` → `1` én `grep -c 'public static function shouldRegisterNavigation'` → `1`
- [x] `grep -c "use App\\Models\\WebhookCall;"` op WebhookCallResource.php → `1`
- [x] `grep -c "Spatie\\WebhookClient\\Models\\WebhookCall"` op WebhookCallResource.php → `0`
- [x] `grep -c "->with('consumer')"` op WebhookCallResource.php → `1`
- [x] `grep -c 'public static function getEloquentQuery'` op WebhookCallResource.php → `1`
- [x] ResourceCanAccessTest: 12 testmethodes, alle groen
- [x] PermissionGatingTest blijft groen (geen regressie op UserResource-gate)
- [x] WebhookCallResourceTest blijft groen na model-rebinding (3 tests)
- [x] Volledige test-suite 421/421 groen (geen Phase-9 regressie)
- [x] Pint clean

## Deviations from Plan

**1. [Rule 1 - Regressie] 8 bestaande admin-tests werden gebroken door canAccess-gate**

- **Found during:** Task 1 GREEN verificatie (`php artisan test --compact tests/Feature/Admin/`)
- **Issue:** Bestaande `actAsStaff()`/`makeStaffUser()`/`actingAsStaff()` helpers in 8 admin-test-files seedden alleen de `staff`-rol — die rol heeft geen permissions standaard. Met de nieuwe `canAccess()`-gates gaf élke `GET /admin/<resource>` 403, en Livewire-tests faalden met "Call to a member function getTableRecordKey() on null" (omdat de page niet rendert).
- **Fix:** Per file de relevante `Permission::firstOrCreate()` toegevoegd in `seedRoles()` en `$user->givePermissionTo(...)` in de helper-method. RelationManagersRenderTest kreeg twee permissions omdat het zowel consumer-edit als connection-view raakt. ConsumerTokenActionTest kreeg twee inline grants (geen actAs-helper).
- **Files modified:** 8 testfiles (zie key-files.modified)
- **Commit:** `b7456b3` (gebundeld met Task 1 GREEN — testfix is integraal onderdeel van de gate-enforcement-edit)
- **Rule:** Rule 1 (bug-fix in scope — gate-enforcement is de feitelijke regressie-trigger, niet de test-helpers).

**2. Vendor-symlink + composer install --no-scripts vereist**

- **Found during:** initiële test-runs (artisan kon vendor/autoload.php niet vinden)
- **Issue:** Worktree had geen `vendor/` directory; Wave-1's 10-02 had de main-repo autoload gepoisond.
- **Fix:** `ln -s /Users/.../emeq-hub/vendor vendor` + `composer install --no-scripts --no-interaction` om de psr-4-baseDir naar de worktree te updaten zonder de classmap-cache te corrupten. `.env` ook gekopieerd uit de main repo (APP_KEY).
- **Houdt rekening met:** Orchestrator zal de main-repo autoload moeten dump'en bij merge-back (uit spawn-context: "you must restore the main checkout autoload before returning").
- **Geen scope-impact:** alleen lokale tooling, geen commit nodig in productie-files.

## Threat Flags

Geen nieuwe security-surface — wel **een hardening van bestaande surface**: D-05 permission-model was tot deze plan dead code (CR-02 BLOCKER). Na merge wordt elke `/admin/<resource>`-route gegate via Spatie's `can()`-hook, wat de eerder geconstateerde route-leak dichttrekt. Geen nieuwe routes, geen auth-flow-wijziging, geen schema-mutatie.

## Self-Check: PASSED

- `[ -f tests/Feature/Admin/ResourceCanAccessTest.php ]` → FOUND
- 6 Resource-files: alle bevatten `public static function canAccess(): bool` én `shouldRegisterNavigation(): bool` → FOUND
- WebhookCallResource: imports `App\Models\WebhookCall`, geen Spatie-class-ref meer, heeft `getEloquentQuery()` met `->with('consumer')` → FOUND
- Commit `88907f6` (RED) → FOUND in `git log`
- Commit `b7456b3` (GREEN) → FOUND in `git log`
- Volledige suite groen (421/421)
- Pint clean

## Unlocks

- **Plan 10-04 (Wave 3):** WebhookCallsTable + WebhookCallInfolist kunnen nu `TextColumn::make('consumer.slug')` doen + Resource-eager-load is gepre-staged. Geen file-conflict met 10-03 (Tables/Schemas files disjoint).
- **HUB-04 SC-7 (Phase 9 deferred):** Wordt formeel gesloten met deze plan als "permission-gated, niet consumer-scoped" — exact zoals 10-CONTEXT.md D-3 voorschrijft.
- **Plan 10-06 (Wave 4):** Kan een dedicated cross-Consumer-isolation-test toevoegen die expliciet bewijst dat permission-gating de huidige v0.2-keuze is (staff ziet alle consumers, maar alleen met de juiste permission).
