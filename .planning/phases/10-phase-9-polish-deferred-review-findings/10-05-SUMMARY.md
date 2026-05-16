---
phase: "10"
plan: "05"
subsystem: filament-admin-user-rbac-subscription
tags: [filament, spatie-permission, super-admin-guard, exception-fingerprint, phase-9-polish, WR-01, WR-03, WR-04, IN-02, IN-03, TDD]
dependency-graph:
  requires:
    - "Plan 10-03 — AccountSubscriptionResource::canAccess() reeds in place (file-context-conflict guard)"
    - "Spatie\\Permission HasRoles + RoleDoesNotExist exception class"
    - "Filament v4 Action->halt() + ->before() callback API"
  provides:
    - "WR-01 closed — UsersTable assignRole-action + EditUser DeleteAction last-super-admin guards"
    - "WR-03 closed — Select->in(['super-admin','staff']) + try/catch RoleDoesNotExist"
    - "WR-04 closed — EmeqStaffSeeder hard-fails op bestaande user (bootstrap-only idiom)"
    - "IN-02 closed — cancel/pause/resumeAction Throwable-catches gebruiken sha256-fingerprint"
    - "IN-03 closed — AdminPanelProvider ->default() blok-comment over multi-panel-side-effect"
  affects:
    - "v0.2.1 Phase 9 polish-tracking — sluit 5 deferred bevindingen tegelijk"
tech-stack:
  added: []
  patterns:
    - "Filament v4 idiomatic Action->halt() inside ->before()-callback (geen Halt-class-import)"
    - "Spatie\\Permission RoleDoesNotExist defensive try/catch op syncRoles"
    - "Last-super-admin invariant via User::role('super-admin')->where('id', '!=', ...)->count()"
    - "report($e) + sha256-fingerprint pattern voor exception-message-leak-protection in admin notifications"
    - "TDD (RED commit → GREEN commit) per task volgens plan tdd=\"true\""
key-files:
  created: []
  modified:
    - "app/Filament/Resources/Users/Tables/UsersTable.php"
    - "app/Filament/Resources/Users/Pages/EditUser.php"
    - "app/Filament/Resources/AccountSubscriptions/AccountSubscriptionResource.php"
    - "app/Providers/Filament/AdminPanelProvider.php"
    - "database/seeders/EmeqStaffSeeder.php"
    - "tests/Feature/Admin/UserResourceTest.php"
    - "tests/Feature/Admin/EmeqStaffSeederTest.php"
    - "tests/Feature/Admin/AccountSubscriptionStateActionsTest.php"
decisions:
  - "D-4 (10-CONTEXT.md): self-downgrade-guard fires vóór last-super-admin-guard (volgorde matters voor unique-error-messages); beide returnen vóór syncRoles — fail-closed pattern"
  - "D-4: $action->halt() inside ->before() ipv throw new Halt — minder imports, idiomatic Filament v4"
  - "D-6: try/catch RoleDoesNotExist defensief — Select->in()-validator zou al moeten weigeren, maar dubbel-vang houdt action 500-vrij bij DB-mutaties tussen form-load en submit"
  - "D-7: hard-fail-pad gekozen (niet password-update-pad) — operator-instructie naar tinker is duidelijker dan silent password-rotation"
  - "D-10: 3 manager-delegated state-actions (cancel/pause/resume) krijgen symmetrische Throwable-catch — niet alleen cancel, omdat ook pause/resume via manager Mollie-exceptions kunnen propageren"
  - "Test-strategie voor last-super-admin-guard: self-downgrade-pad triggert beide guards (self-check eerst); pure last-guard zonder self-overlap is niet praktisch testbaar in Filament zonder een tweede authenticated super-admin (gate-restrictie). De combineerde test bewijst dat het record-blijft-super-admin invariant geldt."
metrics:
  duration: ~25 min
  completed: 2026-05-16
---

# Phase 10 Plan 05: Last-super-admin guards + assignRole validators + seeder hard-fail + exception-fingerprint Summary

Wave 3 polish-bundle: vijf deferred bevindingen uit `09-REVIEW.md` (WR-01, WR-03, WR-04, IN-02, IN-03) gesloten in één plan. Sluit Phase 9's admin-paneel security-rondom-rollen + exception-message-leak-protection. Bouwt door op 10-03 (permission-gating reeds in place op AccountSubscriptionResource) zonder file-conflict.

## What was built

### Code

**`app/Filament/Resources/Users/Tables/UsersTable.php`** (WR-01 + WR-03):
- Action-callback (`assignRole`) krijgt 3-staps gate vóór `syncRoles`:
  1. Self-downgrade-guard: `if ($record->id === auth()->id() && $data['role'] !== 'super-admin')` → `Notification::danger('Je kunt jezelf niet downgraden')` + `return`.
  2. Last-super-admin-guard: `if ($record->hasRole('super-admin') && $data['role'] !== 'super-admin' && User::role('super-admin')->where('id', '!=', $record->id)->count() === 0)` → `Notification::danger('Kan laatste super-admin niet downgraden')` + `return`.
  3. try/catch `RoleDoesNotExist` op `syncRoles` → `Notification::danger('Onbekende rol')` + `return`.
- `Select::make('role')->in(['super-admin', 'staff'])` toegevoegd voor server-side validator (Filament v4).
- Success-pad ongewijzigd.

**`app/Filament/Resources/Users/Pages/EditUser.php`** (WR-01):
- `DeleteAction::make()->before(function (User $record, Action $action): void { ... })`:
  - Self-delete-guard: `if ($record->id === auth()->id())` → notification + `$action->halt()`.
  - Last-super-admin-delete-guard: zelfde count-pattern als UsersTable → notification + `$action->halt()`.
- Geen Halt-class-import; `$action->halt()` is de v4 idiomatic API.

**`database/seeders/EmeqStaffSeeder.php`** (WR-04):
- `User::firstOrCreate(...)` vervangen door explicit lookup + branch:
  - `$existing = User::where('email', $email)->first()` — null-check.
  - Bestaand: `throw new \RuntimeException("User {$email} bestaat al — reset wachtwoord via php artisan tinker, niet via seeder. EmeqStaffSeeder is bootstrap-only (D-7 / WR-04).")`.
  - Anders: `User::create([...])->assignRole($superAdmin)`.
- Roles + Permissions blijven idempotent via `firstOrCreate` (bewust — die mogen herrun worden voor permission-bumps).
- Class-docblock vermeldt expliciet "bootstrap-only / 2× runnen = RuntimeException".

**`app/Filament/Resources/AccountSubscriptions/AccountSubscriptionResource.php`** (IN-02):
- 3 manager-delegated state-actions (cancel/pause/resume) krijgen symmetrische Throwable-catch:
  - `report($e)` → logs voor debugging (admin/SRE ziet de stack-trace daar).
  - Notification body: `'Zie logs voor details — fingerprint: '.substr(hash('sha256', $e->getMessage()), 0, 12)` — geen raw `$e->getMessage()` meer in user-facing UI.
- `InvalidStateTransitionException`-catches direct daarboven blijven ongewijzigd — die exception-message is admin-vriendelijk (geen Mollie-secrets, alleen state-pair-info).

**`app/Providers/Filament/AdminPanelProvider.php`** (IN-03):
- Blok-comment toegevoegd vóór `->default()`-call:
  ```
  // IN-03: ->default() markeert dit paneel als Filament's default-panel. Side-effect:
  // Filament::auth() (zonder panel-id) pakt deze guard. Voor toekomstige consumer-portal-
  // panels (v1.0+) moet ->default() expliciet naar het nieuwe paneel verhuizen.
  ```
- Geen code-wijziging op `->default()` zelf (per 10-CONTEXT.md scope_fence).

### Tests

**`tests/Feature/Admin/UserResourceTest.php`** (+5 testmethodes → 8 totaal):
- `test_super_admin_cannot_self_downgrade_via_assign_role` — admin downgradet zichzelf → role blijft super-admin.
- `test_last_super_admin_self_downgrade_is_blocked` — enige super-admin downgradet zichzelf → role blijft + 1 super-admin in DB.
- `test_last_super_admin_cannot_be_deleted_via_edit_page` — DeleteAction op EditUser blokkeert delete → `User::find($admin->id)` blijft non-null.
- `test_assign_role_rejects_unknown_role` — onbekende rol 'foo-role' → record krijgt geen rol, geen 500.
- `test_super_admin_can_downgrade_other_super_admin_when_not_last` — happy-path: 2 supers, downgrade van 1 → target heeft staff, original blijft super-admin.

**`tests/Feature/Admin/EmeqStaffSeederTest.php`** (1 testmethode vervangen):
- `test_seeder_is_idempotent_when_run_twice` (oud) → `test_seeder_throws_runtime_exception_when_user_already_exists` (nieuw).
- Seeder 1x runnen succeed, 2e run gooit `RuntimeException` met message-substring `'bestaat al'`.
- 2 baseline-tests ongewijzigd (`is_noop_without_env_vars` + `creates_roles_permissions_and_bootstrap_user_with_env`).

**`tests/Feature/Admin/AccountSubscriptionStateActionsTest.php`** (+3 testmethodes → 8 totaal):
- `test_cancel_action_shows_generic_notification_with_fingerprint_on_throwable` — `createMock(AccountSubscriptionManager::class)->method('cancel')->willThrowException(new RuntimeException('mollie-test-error-cancel'))` + `$this->app->instance(...)` → callTableAction → `assertNotified` met expected body = fingerprint-pattern.
- `test_pause_action_shows_generic_notification_with_fingerprint_on_throwable` — symmetrisch.
- `test_resume_action_shows_generic_notification_with_fingerprint_on_throwable` — symmetrisch.
- 5 baseline state-actions tests blijven groen.

## TDD flow

Plan markeerde alle 3 tasks als `tdd="true"`. Uitgevoerd in correcte RED → GREEN volgorde per task.

| Task | Phase | Commit | Result |
|---|---|---|---|
| 1 | RED | `d20f8b4` — test(10-05): add failing tests for last-super-admin guards + assignRole validators | 3 RED / 5 baseline+already-green = 5/8 |
| 1 | GREEN | `1d77b2d` — feat(10-05): last-super-admin guards + assignRole validators | 8/8 UserResourceTest |
| 2 | RED | `544f288` — test(10-05): replace seeder-idempotency-test with hard-fail expectation | 1 RED / 2 baseline = 2/3 |
| 2 | GREEN | `6e80818` — feat(10-05): EmeqStaffSeeder hard-fail bij bestaande user | 3/3 EmeqStaffSeederTest |
| 3 | RED | `24dae99` — test(10-05): add failing tests for exception-fingerprint notifications | 3 RED / 5 baseline = 5/8 |
| 3 | GREEN | `be99c87` — feat(10-05): fingerprint Throwable-catches + ->default() comment | 8/8 AccountSubscriptionStateActionsTest |

## Test counts

| Run | Tests | Passed | Assertions |
|---|---|---|---|
| Baseline (na Wave 2 merge, op acf29c8) | 424 | 424 | ~1440 |
| Na Task 1 GREEN (UserResourceTest) | 8 | 8 | 54 |
| Na Task 2 GREEN (EmeqStaffSeederTest) | 3 | 3 | 12 |
| Na Task 3 GREEN (AccountSubscriptionStateActionsTest) | 8 | 8 | 48 |
| Na Task 3 GREEN (FilamentInstallSmokeTest) | 3 | 3 | 8 |
| Na Task 3 GREEN (admin suite) | 78 | 78 | 302 |
| Na Task 3 GREEN (full suite) | **432** | **432** | **1455** |

Suite-delta van 424 → 432 (+8) komt uit:
- 5 nieuwe UserResourceTest (3 was → 8 = +5)
- 0 netto delta EmeqStaffSeederTest (3 was → 3 = +0; 1 vervangen)
- 3 nieuwe AccountSubscriptionStateActionsTest (5 was → 8 = +3)

Geen regressie — alle Phase 9-tests + Plan 10-01..10-04 tests blijven groen.

## Pint

`./vendor/bin/pint --dirty --format agent` → clean run na elke commit. Eén fix tijdens Task 3 RED (auto-import van `Filament\Notifications\Notification` + ordered_imports) — geen handmatige aanpassing nodig.

## Done criteria

**Task 1 (WR-01 + WR-03):**
- [x] `grep -c "User::role('super-admin')->where('id', '!='" app/Filament/Resources/Users/Tables/UsersTable.php` → `1`
- [x] `grep -cE "->in\(\[['\"]super-admin['\"], ['\"]staff['\"]\]\)" app/Filament/Resources/Users/Tables/UsersTable.php` → `1`
- [x] `grep -c "RoleDoesNotExist" app/Filament/Resources/Users/Tables/UsersTable.php` → `2` (use + catch)
- [x] `grep -c "->before" app/Filament/Resources/Users/Pages/EditUser.php` → `1`
- [x] `grep -c '\$action->halt()' app/Filament/Resources/Users/Pages/EditUser.php` → `2`
- [x] `grep -c 'throw new Halt' app/Filament/Resources/Users/Pages/EditUser.php` → `0`
- [x] `grep -c '\$this->halt()' app/Filament/Resources/Users/Pages/EditUser.php` → `0`
- [x] `grep -c 'public function test_' tests/Feature/Admin/UserResourceTest.php` → `8` (was 3)
- [x] `php artisan test --compact --filter=UserResourceTest` → `8 passed`

**Task 2 (WR-04 / D-7):**
- [x] `grep -c 'RuntimeException' database/seeders/EmeqStaffSeeder.php` → `2` (docblock + throw)
- [x] `grep -c 'User::firstOrCreate' database/seeders/EmeqStaffSeeder.php` → `0` (vervangen door User::create + lookup)
- [x] `grep -c 'test_seeder_throws' tests/Feature/Admin/EmeqStaffSeederTest.php` → `1`
- [x] `php artisan test --compact --filter=EmeqStaffSeederTest` → `3 passed`

**Task 3 (IN-02 + IN-03):**
- [x] `grep -c "hash('sha256'" app/Filament/Resources/AccountSubscriptions/AccountSubscriptionResource.php` → `3` (cancel + pause + resume)
- [x] `grep -c "report(\$e)" app/Filament/Resources/AccountSubscriptions/AccountSubscriptionResource.php` → `3`
- [x] `grep -c "IN-03" app/Providers/Filament/AdminPanelProvider.php` → `1`
- [x] `grep -c 'test_cancel_action_shows_generic_notification' tests/Feature/Admin/AccountSubscriptionStateActionsTest.php` → `1`
- [x] `grep -c 'test_pause_action_shows_generic_notification' tests/Feature/Admin/AccountSubscriptionStateActionsTest.php` → `1`
- [x] `grep -c 'test_resume_action_shows_generic_notification' tests/Feature/Admin/AccountSubscriptionStateActionsTest.php` → `1`
- [x] `php artisan test --compact --filter='AccountSubscriptionStateActionsTest|FilamentInstallSmokeTest'` → all passed

**Algemene plan-criteria:**
- [x] Full admin-suite groen (78/78)
- [x] Full test-suite groen (432/432)
- [x] Pint clean
- [x] STATE.md / ROADMAP.md niet aangeraakt (orchestrator owns those)

## Deviations from Plan

**1. [Rule 1 — environment-bootstrap] Worktree had geen `vendor` + geen `.env`; Composer-autoload-baseDir-bug op gesymlinkte vendor**

- **Found during:** Eerste test-run rapporteerde `vendor/autoload.php not found`. Een symlink `vendor → /Users/.../emeq-hub/vendor` werkte voor de require, maar Composer's `autoload_psr4.php` doet `$baseDir = dirname($vendorDir)` op `__DIR__`, en `__DIR__` resolve't via realpath naar de main-repo. Resultaat: de worktree's app/-wijzigingen werden niet ingeladen — Composer laadde de main-repo's app/-classes. Test rapporteerde "guard niet getriggerd" omdat de guard-code daar niet bestaat.
- **Fix:** Symlink vervangen door `cp -r /Users/.../emeq-hub/vendor vendor` (cyclische `vendor/vendor/...` symlinks gaven harmless warnings; de worktree's vendor heeft nu een eigen baseDir-resolution). `.env` gekopieerd van main-repo voor APP_KEY + DB-config.
- **Files modified:** `vendor/` (gitignored — niet gecommit), `.env` (gitignored — niet gecommit).
- **Rule:** Rule 3 (blocker-fix — tests konden zonder lokale vendor niet draaien). Geen "composer install" gerund (Rule 3-exclusie voor package-installs); alleen filesystem-copy van bestaande vendor.

**2. [Plan-spec drift] Plan-grep-criteria voor `firstOrCreate` in EmeqStaffSeeder noemde "returnt `2`"**

- **Found during:** Done-criteria verificatie. Plan zei `grep -c 'firstOrCreate' database/seeders/EmeqStaffSeeder.php returnt 2`.
- **Werkelijkheid:** 4 actual code-calls (2× `Role::firstOrCreate` + 2× `Permission::firstOrCreate`) + 1 in een docblock-comment = `5` totaal. De intent — "geen `User::firstOrCreate` meer" — is correct vervuld; ik heb dat afzonderlijk geverifieerd via `grep -c 'User::firstOrCreate' = 0`.
- **Fix:** Geen code-fix nodig; de plan-spec was overspecified op een grep-count die niet de intent dekt. Done-criteria-tabel hierboven heeft de correcte verifier (`User::firstOrCreate = 0`).
- **Rule:** Rule 1 (plan-spec-precision-bug; geen productie-fout).

**3. [Plan-spec drift] Plan-grep-criteria voor `$e->getMessage()` in AccountSubscriptionResource noemde "returnt `3`"**

- **Found during:** Done-criteria verificatie. Plan verwachtte exact 3 `$e->getMessage()`-calls (alleen de 3 `InvalidStateTransitionException`-catches).
- **Werkelijkheid:** 6 calls — de 3 `InvalidStateTransitionException`-catches gebruiken het + de 3 `Throwable`-catches gebruiken `$e->getMessage()` ALS INPUT VOOR `hash('sha256', $e->getMessage())`. De message wordt gehasht, niet getoond — intent is satisfied (geen raw message in user-facing notification).
- **Fix:** Geen code-fix nodig. Done-criteria-tabel hierboven bewijst intent via `report($e) = 3` + `hash('sha256' = 3`.
- **Rule:** Rule 1 (plan-spec-precision-bug; geen productie-fout).

**Géén productie-code-wijzigingen buiten plan-scope.** Alle 5 file-wijzigingen volgen 10-CONTEXT.md D-4 / D-6 / D-7 / D-10 + scope_fence (IN-03 = alleen comment, geen code-change).

## Threat Flags

Geen nieuwe security-surface. Alle wijzigingen verkleinen de surface:
- WR-01 voorkomt admin-paneel-lockout (operationele safety).
- WR-03 voorkomt 500-errors op form-state-corruptie.
- WR-04 voorkomt silent password-overwrite (operator-bewustzijn).
- IN-02 dicht exception-message-leak in admin-notifications (defense-in-depth — eindbestemming is alleen admins met manage-subscriptions, maar Mollie-error-messages kunnen subscription-IDs/customer-IDs bevatten die in error-bodies leaken).
- IN-03 is alleen documentatie (geen code-change).

## Self-Check: PASSED

- `[ -f app/Filament/Resources/Users/Tables/UsersTable.php ]` → FOUND (commit `1d77b2d`)
- `[ -f app/Filament/Resources/Users/Pages/EditUser.php ]` → FOUND (commit `1d77b2d`)
- `[ -f app/Filament/Resources/AccountSubscriptions/AccountSubscriptionResource.php ]` → FOUND (commit `be99c87`)
- `[ -f app/Providers/Filament/AdminPanelProvider.php ]` → FOUND (commit `be99c87`)
- `[ -f database/seeders/EmeqStaffSeeder.php ]` → FOUND (commit `6e80818`)
- `[ -f tests/Feature/Admin/UserResourceTest.php ]` → FOUND (commits `d20f8b4` + `1d77b2d`)
- `[ -f tests/Feature/Admin/EmeqStaffSeederTest.php ]` → FOUND (commits `544f288` + `6e80818`)
- `[ -f tests/Feature/Admin/AccountSubscriptionStateActionsTest.php ]` → FOUND (commits `24dae99` + `be99c87`)
- Commit `d20f8b4` (Task 1 RED) → FOUND in `git log`
- Commit `1d77b2d` (Task 1 GREEN) → FOUND in `git log`
- Commit `544f288` (Task 2 RED) → FOUND in `git log`
- Commit `6e80818` (Task 2 GREEN) → FOUND in `git log`
- Commit `24dae99` (Task 3 RED) → FOUND in `git log`
- Commit `be99c87` (Task 3 GREEN) → FOUND in `git log`
- Volledige suite groen (432/432)
- Pint clean

## Unlocks

- **WR-01 closed** — Last-super-admin-paneel-lockout-scenario afgedicht (downgrade + delete pad).
- **WR-03 closed** — assignRole-action 500-vrij bij invalid form-state.
- **WR-04 closed** — EmeqStaffSeeder is explicit bootstrap-only; password-resets gaan via tinker.
- **IN-02 closed** — 3 manager-delegated state-actions tonen geen raw exception-messages meer.
- **IN-03 closed** — AdminPanelProvider->default() side-effect gedocumenteerd voor v1.0+ multi-panel-werk.
- **Phase 10 Wave 3 — 5 van 6 bevindingen op deze wave klaar.** Plan 10-06 (laatste wave, IN-04 + WR-05/06 + andere polish) is de volgende stap.
