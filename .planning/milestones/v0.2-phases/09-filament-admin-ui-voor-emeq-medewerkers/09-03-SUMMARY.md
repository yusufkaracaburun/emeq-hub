---
phase: 09-filament-admin-ui-voor-emeq-medewerkers
plan: 03
subsystem: rbac-bootstrap
tags: [filament-user, spatie-permission, rbac, role-gate, env-driven-seeder, tdd]

# Dependency graph
requires:
  - plan: 09-02-filament-spatie-install
    provides: Spatie laravel-permission v6 (HasRoles-trait, Role/Permission models) + Filament v4 (FilamentUser-interface, Panel-class) beschikbaar in vendor
  - plan: 03-hub-skeleton
    provides: User-model + users-tabel + UserFactory (al volledig sinds 03-05 — geen wijziging nodig in 09-03)
provides:
  - User-model voldoet aan FilamentUser-contract met canAccessPanel-gate (super-admin OR staff)
  - HasRoles-trait actief op User (rol-assignments via $user->assignRole(...))
  - EmeqStaffSeeder: idempotent env-driven 2-rollen + 6-permissions + 1 bootstrap super-admin
  - 3-tier role-gate empirisch bewezen (unauth/no-role/staff)
affects: [09-04-tm-09-10-resources, 09-11-provider-credential-descriptor, 09-12-phase-acceptance]

# Tech tracking
tech-stack:
  added: []  # geen nieuwe packages — 09-02 leverde Filament + Spatie
  patterns:
    - "Filament-gate-pattern: implements FilamentUser + canAccessPanel(Panel $panel) returns panel-id check + Spatie hasAnyRole"
    - "Env-driven idempotent seeder-pattern: env-guard early-return + firstOrCreate voor rollen/permissions/User + assignRole (Spatie is zelf idempotent)"

key-files:
  created:
    - database/seeders/EmeqStaffSeeder.php
    - tests/Feature/Admin/PanelAccessTest.php
    - tests/Feature/Admin/EmeqStaffSeederTest.php
  modified:
    - app/Models/User.php

key-decisions:
  - "User-model krijgt `HasRoles` trait + `implements FilamentUser` + `canAccessPanel(Panel $panel)` zonder wijziging aan `#[Fillable]`/`#[Hidden]`/`casts()` — Spatie's HasRoles voegt geen fillable-velden toe (rol-assignments gaan via $user->assignRole())"
  - "EmeqStaffSeeder krijgt GEEN `app()->isProduction()`-guard — env-vars zijn de production-safe-knop (DatabaseSeeder.php zelf draait nooit in productie en roept EmeqStaffSeeder niet aan; bootstrap gaat altijd via `db:seed --class=EmeqStaffSeeder`)"
  - "Rollen worden in PanelAccessTest direct via `Role::firstOrCreate` geseed (niet via EmeqStaffSeeder) omdat EmeqStaffSeeder env-gated is — directe role-create is deterministischer in tests en koppelt de gate-test los van de seeder-test"

patterns-established:
  - "Test-helper voor role-seeding: `private function seedRoles(): void { Role::firstOrCreate(...) }` voor elke admin-feature-test die rol-asserties doet"
  - "putenv() in tearDown() voor cleanup van env-driven seeder-tests (anders lekken vars naar volgende test)"

requirements-completed: []  # HUB-04 wordt pas in 09-12 als complete gemarkeerd

# Metrics
duration: ~20min
completed: 2026-05-16
---

# Phase 09 Plan 03: User-model + EmeqStaffSeeder Summary

**User-model implementeert FilamentUser-contract met HasRoles-trait + canAccessPanel-gate (super-admin/staff); EmeqStaffSeeder seeds idempotent 2 rollen + 6 permissions + env-driven bootstrap super-admin; 6 nieuwe tests groen (3 PanelAccess + 3 Seeder), full suite 349/349.**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-05-16T00:27:00+02:00
- **Completed:** 2026-05-16T00:47:00+02:00
- **Tasks:** 3 atomic commits
- **Files created:** 3 (1 seeder + 2 test-files)
- **Files modified:** 1 (User-model)

## Accomplishments

- `App\Models\User` voldoet aan `Filament\Models\Contracts\FilamentUser` — geverifieerd via `php -r '... new App\Models\User() instanceof FilamentUser ...'` → OK
- `canAccessPanel(Panel $panel)` returnt expliciet false zonder rol of buiten admin-panel (T-09-03-01 mitigatie bewezen via PanelAccessTest test 2)
- Spatie's `HasRoles`-trait beschikbaar (`->assignRole()`, `->hasRole()` werken)
- `EmeqStaffSeeder` env-driven + idempotent (3 separate tests bewijzen no-op / create-flow / 2× duplicate-vrij)
- 6/6 plan-must-have-truths empirisch bewezen
- Full test-suite 349/349 (was 343 + 6 nieuwe; 1 pre-existing incomplete uit 09-01-baseline blijft)
- Pint clean across alle gewijzigde files

## Task Commits

Atomic per-task commits op `worktree-agent-a03ef902a92193a3e`:

1. **Task 1:** `dedb2cf` (feat) — User-model: implements FilamentUser + HasRoles-trait + canAccessPanel-gate
2. **Task 2:** `52d468e` (feat) — EmeqStaffSeeder: env-driven idempotent role/permission-bootstrap
3. **Task 3:** `ad63f00` (test) — PanelAccessTest + EmeqStaffSeederTest (6 tests, 16 assertions)

## Must-Have Truths — Empirically Verified

| # | Truth | Bewijs |
|---|---|---|
| 1 | User zonder Spatie-rol kan NIET in /admin | `test_authenticated_user_without_role_cannot_access_admin_panel` → 403 (assertForbidden) |
| 2 | User met `staff` of `super-admin` kan WEL in /admin (200) | `test_staff_user_can_access_admin_panel` → 200 (assertOk); super-admin werkt analoog via `hasAnyRole` |
| 3 | EmeqStaffSeeder maakt 2 rollen + 6 permissions (D-05) | `test_seeder_creates_roles_permissions_and_bootstrap_user_with_env` → `Role::count() === 2 && Permission::count() === 6` |
| 4 | super-admin heeft alle 6 permissions; staff heeft 5 (NIET manage-staff) | zelfde test: `$superAdmin->hasPermissionTo('manage-staff') === true` + `$staff->hasPermissionTo('manage-staff') === false` |
| 5 | Met env-vars maakt seeder bootstrap-User met super-admin-rol; zonder beide vars no-op | `test_seeder_is_noop_without_env_vars` (no env → 0/0/0) + `test_seeder_creates_...` (`$bootstrap->hasRole('super-admin')` true) |
| 6 | Seeder idempotent: 2× draaien crasht niet, maakt geen duplicaten | `test_seeder_is_idempotent_when_run_twice` → na 2× run: 2 rollen / 6 permissions / 1 user |

## Files Created/Modified

**Created:**
- `database/seeders/EmeqStaffSeeder.php` — env-guard + 2 rollen via `Role::firstOrCreate` + 5 shared permissions via loop + 1 super-admin-only permission + bootstrap User via `firstOrCreate` + `assignRole`. Const-declared shared-list voor leesbaarheid (`SHARED_PERMISSIONS` / `SUPER_ADMIN_ONLY_PERMISSION`).
- `tests/Feature/Admin/PanelAccessTest.php` — 3 tests (4 assertions): unauthenticated-redirect, role-less-403, staff-200. Helper `seedRoles()` voor deterministische rol-create zonder env-vars te raken.
- `tests/Feature/Admin/EmeqStaffSeederTest.php` — 3 tests (12 assertions): no-env-noop, with-env-creates, idempotent-2x. `tearDown()` unset env-vars (anders lekken naar volgende test).

**Modified:**
- `app/Models/User.php` — 3 nieuwe imports (FilamentUser, Panel, HasRoles), `implements FilamentUser` op class-signature, `HasRoles` toegevoegd aan trait-stack (alfabetisch tussen HasFactory en Notifiable), nieuwe `canAccessPanel(Panel $panel): bool` method. `#[Fillable]`/`#[Hidden]`/`casts()` ongewijzigd zoals plan vereiste.

## Decisions Made

### Geen `app()->isProduction()`-guard op EmeqStaffSeeder

`DatabaseSeeder.php` heeft die guard wél (line 19), maar EmeqStaffSeeder mag juist in productie 1× draaien voor de bootstrap super-admin. De env-vars (`EMEQ_STAFF_SEED_EMAIL` + `EMEQ_STAFF_SEED_PASSWORD`) zijn de production-safe-knop: zonder beide → no-op. Met beide → geen prod-block. Dit is consistent met CONTEXT.md D-05: "Seeder leest `EMEQ_STAFF_SEED_EMAIL` + `EMEQ_STAFF_SEED_PASSWORD` uit env voor de bootstrap super-admin (productie-eenmalig)".

### PanelAccessTest seedt rollen direct via Spatie, niet via EmeqStaffSeeder

EmeqStaffSeeder is env-gated. Een test die rollen wil zonder bootstrap-user moet of (a) env-vars setten in setUp + tearDown of (b) rollen direct creëren. Optie (b) is deterministischer en koppelt de gate-test los van de seeder-test — defect in de seeder zou anders gate-tests rood maken die niets met de seeder te maken hebben. Daarom in PanelAccessTest: `private function seedRoles(): void { Role::firstOrCreate(...) }` helper.

### UserFactory ongewijzigd

Plan-acceptance criterium "UserFactory minimaal aangevuld zodat factory-create() werkt voor tests (al aanwezig sinds 03-05, verifieer en alleen aanpassen indien nodig)" → geverifieerd: UserFactory bestaat (`database/factories/UserFactory.php`) en is volledig voor de testbehoefte (`name`, `email`, `email_verified_at`, `password`, `remember_token`). Geen wijziging nodig. PanelAccessTest gebruikt `User::factory()->create()` zonder custom state — werkt direct.

### `canAccessPanel` controleert panel-id én rol

Implementatie: `return $panel->getId() === 'admin' && $this->hasAnyRole(['super-admin', 'staff']);`. De panel-id-check is defensief: Filament v4 ondersteunt meerdere panels in één app (bijv. een toekomstig `/portal`-panel voor Consumer-self-service, zie CONTEXT.md D-01 "out of scope"). Door expliciet `'admin'` te checken, kunnen toekomstige panels eigen rollen-logic hebben zonder dat User-model gepatched moet worden.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Worktree-vendor-symlink-bootstrap nodig vóór tests**

- **Found during:** Pre-Task 1 worktree-setup
- **Issue:** Het standaard worktree-bootstrap-snippet (`cp ../../.env .env` + `ln -sf ../../vendor vendor`) verwacht de main-repo op `../..` t.o.v. de worktree, maar Claude Code's nieuwe worktree-layout zet agents op `.claude/worktrees/agent-<id>/` — dat is 4 levels deep, niet 2. `../../` resolveert dus naar `.claude/`, niet naar de main repo.
- **Fix:** Absolute paden gebruikt: `cp /Users/.../emeq-hub/.env .env` + `ln -sf /Users/.../emeq-hub/vendor vendor`. Wel een bestaand foutief vendor-symlink eerst opgeruimd (`readlink` + `rm` + `ln -sf` met juiste target).
- **Files modified:** `.env` (symlink, niet gecommit — .env is gitignored) + `vendor` (symlink, eveneens gitignored via composer-conventie)
- **Verification:** `php artisan test --compact --filter=FilamentInstallSmokeTest` → 3 passed (baseline groen).

**2. [Rule 3 - Blocking] `composer dump-autoload` nodig na User-model wijziging**

- **Found during:** Task 1 verification (`php -r '... instanceof FilamentUser ...'`)
- **Issue:** Eerste `php -r` call returnde `FAIL` op instanceof-check, ondanks `implements FilamentUser` aanwezig in source. Oorzaak: het worktree heeft een vendor-symlink naar de main-repo vendor, en `vendor/composer/autoload_classmap.php` was gegenereerd vóór de Filament-install in 09-02 nog niet alle nieuwe class-mappings bevatten voor `Filament\Models\Contracts\FilamentUser` of een stale User-model-mapping had. PHPUnit gebruikt de Laravel-bootstrap die `vendor/autoload.php` op een andere manier laadt en deed het wél correct, maar bare `php -r` viel terug op de classmap.
- **Fix:** `composer dump-autoload -o` in de worktree — regenereert classmap (12303 classes). Daarna `php -r` instanceof check → OK. Niet gecommit (classmap wordt bij elke `composer install`/`update` opnieuw gemaakt; geen artefact in git).
- **Files modified:** geen committed files
- **Verification:** Post-dump `php -r` → "OK"; full test-suite blijft 349/349 groen.

**Geen Rule 4 architecturele changes nodig.** Plan-tasks 1-3 zijn 1-op-1 uitgevoerd zoals beschreven.

## Issues Encountered

- **Worktree-vendor-symlink + composer dump-autoload-coupling**: het `composer dump-autoload` in een worktree-symlinked-vendor regenereert de classmap in de **main-repo's** vendor/composer/. Dat is normaal voor symlink-resolution en geen probleem (de main repo gebruikt diezelfde classmap), maar wel iets om te onthouden: als een ander parallel-agent vendor-state muteert (zoals 09-02 deed met `composer require`), kan dat een korte race veroorzaken. In dit plan was 09-02 reeds afgesloten en geen parallel-vendor-werk gaande — geen issue.

## Known Stubs

Geen. Alle code is functioneel en gedekt door tests:
- `EmeqStaffSeeder::run()` is geen stub maar volledige idempotent-logic
- `canAccessPanel()` returnt geen `true`-stub maar actuele rol-check
- Tests asserteren echte DB-state en HTTP-responses, geen mocks

## Threat Flags

Geen nieuwe surface buiten het plan's threat-model. Alle 6 STRIDE-items uit `<threat_model>` zijn intact:

- **T-09-03-01 (mitigated):** `canAccessPanel()` blokkeert role-loze users — bewezen via PanelAccessTest test 2 (403)
- **T-09-03-02 (accepted):** Spatie's rol-check leest `model_has_roles`-tabel, niet session — bewezen door integratie-test (rol via `assignRole()` aan DB-row gekoppeld, niet session-state)
- **T-09-03-03 (accepted):** `EMEQ_STAFF_SEED_PASSWORD` env-cleanup is operatie-procedure voor plan 09-11 ADR; geen wijziging hier
- **T-09-03-04 (accepted):** DB-directe mutaties zijn buiten Hub-scope; geen wijziging
- **T-09-03-05 (accepted):** Audit-log uit scope (HUB-AUDIT backlog); geen wijziging
- **T-09-03-SC (accepted):** Geen `composer require` in dit plan — alleen edits aan bestaande User-model + nieuw seeder/tests; slopcheck N/A

## User Setup Required

**Voor productie-bootstrap (eenmalig na deploy):**

```bash
EMEQ_STAFF_SEED_EMAIL=admin@emeq.nl EMEQ_STAFF_SEED_PASSWORD=<sterk-password> \
  php artisan db:seed --class=EmeqStaffSeeder --force
```

Na succesvolle bootstrap kunnen de env-vars uit productie-secrets gehaald worden (T-09-03-03 procedure, te documenteren in plan 09-11 ADR). Password wordt gehashed via `Hash::make()` in DB opgeslagen; raw env-var is alleen tijdens 1× run nodig.

**Voor lokale dev:**

```bash
EMEQ_STAFF_SEED_EMAIL=test@emeq.test EMEQ_STAFF_SEED_PASSWORD=test-secret \
  php artisan db:seed --class=EmeqStaffSeeder
```

## Next Plan Readiness

- **Plan 09-04+ Resources** kunnen nu:
  - Op een ingelogde super-admin / staff vertrouwen (`canAccessPanel` returnt true)
  - `Gate::define('manage-staff', fn (User $user) => $user->hasRole('super-admin'))` registreren in `AppServiceProvider::boot()` zonder dat de User-model nog gepatched moet worden
  - Resource-class-level `canAccess(): bool => Gate::allows('manage-staff')` gebruiken voor super-admin-only resources (UserResource in 09-10)
- **Plan 09-11 ProviderCredentialDescriptor** ongewijzigd ready
- **Geen blocking dependencies open.**

## Verification Commands Run

| Command | Result |
|---|---|
| `php -r '... new App\Models\User() instanceof FilamentUser ...'` | OK (na composer dump-autoload) |
| `grep -q "implements FilamentUser" app/Models/User.php` | OK |
| `grep -q "use HasRoles" app/Models/User.php` | OK |
| `grep -q "canAccessPanel(Panel \$panel): bool" app/Models/User.php` | OK |
| `grep -q "EMEQ_STAFF_SEED_EMAIL" database/seeders/EmeqStaffSeeder.php` | OK |
| `grep -q "Role::firstOrCreate" database/seeders/EmeqStaffSeeder.php` | OK |
| `grep -q "manage-staff" database/seeders/EmeqStaffSeeder.php` | OK |
| `grep -q "view-billing" database/seeders/EmeqStaffSeeder.php` | OK |
| `grep -q "assignRole" database/seeders/EmeqStaffSeeder.php` | OK |
| `./vendor/bin/pint --test --format agent app/Models/User.php` | passed |
| `./vendor/bin/pint --dirty --format agent` | passed |
| `php artisan test --compact --filter=PanelAccessTest` | 3 passed / 4 assertions / 1161ms |
| `php artisan test --compact --filter=EmeqStaffSeederTest` | 3 passed / 12 assertions / 718ms |
| `php artisan test --compact` (full suite) | 349 passed / 1 incomplete / 0 failed / 1138 assertions / 13210ms |

## Self-Check: PASSED

**Files exist:**
- FOUND: app/Models/User.php (modified — implements FilamentUser + HasRoles + canAccessPanel)
- FOUND: database/seeders/EmeqStaffSeeder.php
- FOUND: tests/Feature/Admin/PanelAccessTest.php
- FOUND: tests/Feature/Admin/EmeqStaffSeederTest.php

**Commits exist:**
- FOUND: dedb2cf — feat(09-03): User implements FilamentUser + HasRoles + canAccessPanel-gate
- FOUND: 52d468e — feat(09-03): EmeqStaffSeeder — env-driven idempotent role/permission-bootstrap
- FOUND: ad63f00 — test(09-03): PanelAccessTest + EmeqStaffSeederTest — 3-tier gate + seeder-flow

**Plan must-haves truths verified:** alle 6/6 truths uit het `must_haves.truths`-blok empirisch bewezen via 6 nieuwe tests (zie Must-Have Truths sectie hierboven).

**Key links verified:**
- `app/Models/User.php` → `Spatie\Permission\Traits\HasRoles` via `use HasRoles` — geconfirmeerd in source
- `app/Models/User.php` → `Filament\Models\Contracts\FilamentUser` via `implements FilamentUser` + `canAccessPanel(Panel $panel)` — geconfirmeerd in source + reflection
- `database/seeders/EmeqStaffSeeder.php` → `Spatie\Permission\Models\Role` via `Role::firstOrCreate` + `givePermissionTo` — geconfirmeerd in source

---
*Phase: 09-filament-admin-ui-voor-emeq-medewerkers*
*Completed: 2026-05-16*
