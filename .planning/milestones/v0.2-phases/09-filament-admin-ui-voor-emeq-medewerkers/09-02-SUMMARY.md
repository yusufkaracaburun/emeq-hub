---
phase: 09-filament-admin-ui-voor-emeq-medewerkers
plan: 02
subsystem: admin-panel-bootstrap
tags: [filament, spatie-permission, install, scaffold, admin-panel, rbac-bootstrap]

# Dependency graph
requires:
  - phase: 03-hub-skeleton
    provides: `web`-auth-guard + `users`-provider + User-model (waar AdminPanelProvider->authGuard('web') op bindt)
  - plan: 09-01-webhookcall-audit-columns
    provides: tests/TestCase.php APP_BASE_PATH-override (worktree-bootstrap-fix gemerged in main)
provides:
  - Filament v4 panel-stack op /admin met login op /admin/login
  - Spatie laravel-permission ^6.0 met 5 default-tabellen (permissions/roles/model_has_*/role_has_permissions)
  - AdminPanelProvider met ->discoverResources voor toekomstige Filament/Resources/-classes
  - Pre-acceptable secure-default: geen User kan inloggen (gate komt in plan 09-03 via canAccessPanel)
affects: [09-03-user-model-staff-seeder, 09-04-tm-09-10-resources, 09-11-provider-credential-descriptor]

# Tech tracking
tech-stack:
  added:
    - "filament/filament ^4.0 (geïnstalleerd: v4.11.3)"
    - "spatie/laravel-permission ^6.0 (geïnstalleerd: 6.25.0)"
    - "livewire/livewire (transitive via filament — established OSS)"
  patterns:
    - "Filament's installer registreert AdminPanelProvider automatisch in bootstrap/providers.php"
    - "Filament-published assets in public/css|fonts|js/filament/ gitignored — regenerate bij deploy via composer post-update-cmd"

key-files:
  created:
    - app/Providers/Filament/AdminPanelProvider.php
    - config/permission.php
    - database/migrations/2026_05_15_221123_create_permission_tables.php
    - tests/Feature/Admin/FilamentInstallSmokeTest.php
  modified:
    - composer.json
    - composer.lock
    - bootstrap/providers.php
    - .gitignore

key-decisions:
  - "D-SC pre-install legitimacy gate uitgevoerd inline — beide packages [LEGIT] (Filament Team + Spatie BV, ~4M + 80M downloads, geen [SUS] transitive deps)"
  - "AdminPanelProvider behoudt installer-default (id=admin, path=admin, login, colors.primary=Amber, default-middleware-stack, discoverPages/Widgets); enige Phase-9-patch is ->authGuard('web') (Phase-3 contract)"
  - "Filament-published asset-bundels (public/css|fonts|js/filament/) gitignored — regenerate via composer post-update-cmd op deploy. Voorkomt ~4MB binary-ish files in git-history en blendt met Laravel's bestaande /public/build-pattern."

patterns-established:
  - "Bootstrap pattern voor worktree-mode: `composer install` lokaal + `.env` symlink naar main repo (vendor moet lokaal staan om composer require te kunnen muteren zonder main-repo state te raken)"

requirements-completed: [HUB-04]

# Metrics
duration: ~25min
completed: 2026-05-16
---

# Phase 09 Plan 02: Filament v4 install + Spatie permission Summary

**Filament v4.11.3 + Spatie laravel-permission 6.25.0 geland; AdminPanelProvider op /admin met ->authGuard('web') + discoverResources; 5 Spatie permission-tabellen gemigreerd; 3 smoke-tests groen die /admin/login (200), tabel-bestaan en /admin → /admin/login redirect bewijzen**

## Performance

- **Duration:** ~25 min (incl. composer install bootstrap)
- **Started:** 2026-05-16T00:08:00+02:00
- **Completed:** 2026-05-16T00:14:00+02:00
- **Tasks:** 2 (1 install + 1 TDD smoke-test) + 1 pre-install checkpoint (D-SC, pre-authorized)
- **Files created:** 4
- **Files modified:** 4

## Accomplishments
- Filament v4 panel-stack live op `/admin` met login op `/admin/login` (200) en auth-redirect-pad correct
- Spatie laravel-permission ^6 default-migratie gerun — 5 tabellen leven (permissions/roles/model_has_permissions/model_has_roles/role_has_permissions)
- `AdminPanelProvider::panel()` chirurgisch gepatched (alleen `->authGuard('web')` toegevoegd, rest is installer-default)
- 3 smoke-tests pinnen install-staat; full suite 343/343 groen (1 pre-existing incomplete uit 09-01-baseline)
- `.gitignore` uitgebreid voor Filament-published asset-bundels (~4MB binary-ish files niet in git-history)

## Task Commits

Atomic per-task commits op `worktree-agent-af7e06532b2c71ec5`:

1. **Task 0 (D-SC):** Pre-install legitimacy gate — geen commit (gate, geen artefact)
2. **Task 1:** `bdc6b3f` (feat) — composer require + filament:install + Spatie publish + migrate + ->authGuard('web') patch + .gitignore-update (7 files: composer.json/lock, bootstrap/providers.php, .gitignore, AdminPanelProvider.php, permission.php, permission-migratie)
3. **Task 2:** `07e5749` (test) — smoke-test FilamentInstallSmokeTest met 3 tests / 8 assertions

## Files Created/Modified

**Created:**
- `app/Providers/Filament/AdminPanelProvider.php` — Filament v4 panel-config (gegenereerd door installer + `->authGuard('web')` patch)
- `config/permission.php` — Spatie permission-config (default, ongewijzigd)
- `database/migrations/2026_05_15_221123_create_permission_tables.php` — Spatie's default 5-tabellen-migratie
- `tests/Feature/Admin/FilamentInstallSmokeTest.php` — 3 tests / 8 assertions

**Modified:**
- `composer.json` — `filament/filament: ^4.0` + `spatie/laravel-permission: ^6.0` toegevoegd aan `require`
- `composer.lock` — Filament + Spatie + transitive deps gepind
- `bootstrap/providers.php` — `AdminPanelProvider::class` toegevoegd door installer; pint cleande tot één `use`-statement
- `.gitignore` — `/public/css|fonts|js/filament` toegevoegd

## Decisions Made

### D-SC pre-install legitimacy gate (inline executed)

Pre-install gate volgens Phase-9 security-enforcement uitgevoerd vóór `composer require`. Beide packages [LEGIT] bevestigd:

- **filament/filament v4.11.3** — maintainer Dan Harrin + Filament Team (https://github.com/filamentphp/filament), ~4M downloads op Packagist, current stable major voor Laravel 13 + PHP 8.4.
- **spatie/laravel-permission 6.25.0** — maintainer Freek Van der Herten + Spatie BV (https://github.com/spatie/laravel-permission), ~80M downloads op Packagist, current stable major voor Laravel 13.

Transitive deps gescand in `composer require` output: alleen established OSS (livewire/livewire, blade-icons, blade-heroicons, kirschbaum-development/eloquent-power-joins voor Filament; geen Spatie transitive deps buiten Laravel core). Geen [SUS]-deps aangetroffen. Orchestrator had de gate pre-authorized als `{user_response} = "approved"`; geen halt.

### AdminPanelProvider chirurgisch gepatched

Filament's installer genereert een AdminPanelProvider met `->default()`, `->id('admin')`, `->path('admin')`, `->login()`, `colors.primary=Amber`, `discoverResources/Pages/Widgets`, `Dashboard`-page, `AccountWidget`+`FilamentInfoWidget`, en de volledige default-middleware-stack. Plan-acceptance vereist exact `->path('admin')` + `->login()` + `->authGuard('web')` + `->discoverResources` — drie van de vier kreeg ik gratis uit de installer. Enige patch: `->authGuard('web')` toegevoegd tussen `->login()` en `->colors()` om aan Phase-3-contract te binden (`web`-guard + `users`-provider).

Volgens `.ai/rules/engineering.md` "chirurgisch wijzigen" geen verdere installer-defaults aangetast (colors / Dashboard / middleware / widgets blijven default). De widget-classes `AccountWidget` + `FilamentInfoWidget` zijn Filament's default-onboarding-widgets — verwijderen kan in plan 09-03 als de echte dashboard ingericht wordt; nu is dat scope-creep.

### Filament-published assets gitignored

`filament:install` publiceert via `vendor:publish --tag=laravel-assets` ~4MB aan CSS/JS/fonts naar `public/css/filament`, `public/fonts/filament`, `public/js/filament`. Filament's `composer.json` registreert `@php artisan filament:upgrade` als `post-autoload-dump` hook, wat deze assets automatisch republished bij elke `composer install`/`update`. Het is dus zowel veilig (regenerate on deploy) als verstandig (bloat-preventie) om ze niet te committen — analoog aan `/public/build` (Vite-output) dat al gitignored is.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Worktree had geen `vendor/` of `.env` — bootstrap nodig vóór composer require**
- **Found during:** Pre-Task 1 (worktree filesystem-inspectie)
- **Issue:** De agent-worktree was net aangemaakt door de orchestrator; `vendor/` ontbrak en `.env` ontbrak. Zonder vendor kon `composer require` niet draaien (gemeld als Rule-3 blocking).
- **Fix:** `.env` als symlink naar `/Users/yusufkaracaburun/Sites/localhost/emeq-hub/.env` (single-source van DB-credentials + APP_KEY). `composer install --no-interaction --prefer-dist --no-progress` lokaal in worktree (geen symlink naar main-repo vendor — composer require muteert vendor/composer/installed.json en composer.lock, dat moet geïsoleerd blijven). Bootstrap-cost ~30s; 55 packages geïnstalleerd lokaal.
- **Files modified:** `.env` (symlink, niet gecommit — .env is gitignored)
- **Verification:** `php artisan --version` → Laravel 13.9.0; `php artisan migrate:status` toont alle baseline-migraties (incl. 09-01's webhook_calls-audit-kolommen).

**2. [Rule 3 - Blocking] Filament publishes ~4MB assets naar public/ — pollueerden git status**
- **Found during:** Task 1 (post-filament:install)
- **Issue:** `filament:install --panels` publiceert assets via `vendor:publish --tag=laravel-assets` naar `public/css/filament/`, `public/fonts/filament/`, `public/js/filament/`. Zonder gitignore-update zouden die 4MB binary-ish assets in elke commit landen, en bij elke `composer install`/`update` opnieuw als `M` verschijnen (Filament's post-autoload-dump-hook republished ze).
- **Fix:** 3 paden toegevoegd aan `.gitignore`: `/public/css/filament`, `/public/fonts/filament`, `/public/js/filament`. Analoog aan bestaand `/public/build` (Vite-output) en `/public/storage` (storage-symlink) die ook gitignored zijn.
- **Files modified:** `.gitignore`
- **Verification:** `git status` na fix toont alleen relevante PHP/JSON-files, geen public/-pollutie.

---

**Total deviations:** 2 auto-fixed (beide Rule-3 blocking, beide vóór commit opgelost)
**Impact on plan:** Geen — beide deviations zijn worktree-bootstrap-mechaniek en best-practice asset-management. Plan-tasks 1+2 zijn 1-op-1 uitgevoerd zoals beschreven.

## Issues Encountered

- **composer post-update-cmd boost:update error** — `composer require` activeerde `@php artisan boost:update` als post-update-cmd hook, die faalde met "Please set up Boost with [php artisan boost:install] first." Dit is een no-op-warning (Boost is een dev-tool, geen runtime-dep) en blokkeerde geen install. Plan 09-03 kan optioneel `php artisan boost:install` als one-time setup overwegen, of de hook in composer.json conditional maken. Niet binnen scope van 09-02.

## Known Stubs

None — alle gegenereerde Provider-state is functioneel (geen placeholder-values, geen TODO's, geen mocks).

## Threat Flags

Geen nieuwe surface buiten het plan's threat-model:

- T-09-02-SC (composer install tampering): gemitigated via pre-install legitimacy gate + committed `composer.lock` voor reproducibility.
- T-09-02-01 (unprotected /admin): geaccepteerd; AdminPanelProvider bindt op Phase-3's `web`-guard. Smoke-test 3 bewijst redirect-pad.
- T-09-02-02 (Filament debug-info in prod): geaccepteerd; `APP_ENV=production` schakelt Filament debug-features default uit.
- T-09-02-03 (default admin-gate too open): gemitigated; geen User kan momenteel inloggen (User-model heeft nog geen `canAccessPanel`-impl — komt plan 09-03). Smoke-test 3 bewijst unauthenticated-redirect.
- T-09-02-04 (CSRF op Filament-actions): geaccepteerd; Filament v4 leunt op Livewire's automatische CSRF-handling via Laravel-default `web`-middleware (`PreventRequestForgery` zit in de provider's middleware-stack).

## User Setup Required

**None** — pure stack-install. Wel relevant voor plan 09-03 (User-model + EmeqStaffSeeder):
- `EMEQ_STAFF_SEED_EMAIL` + `EMEQ_STAFF_SEED_PASSWORD` env-vars moeten gezet zijn voor de bootstrap-super-admin (env-driven seeder, productie-eenmalig).

## Next Plan Readiness

- **Plan 09-03 (User-model + EmeqStaffSeeder)** kan nu:
  - `use Spatie\Permission\Traits\HasRoles` op `App\Models\User` toevoegen (trait beschikbaar)
  - `implements Filament\Models\Contracts\FilamentUser` (interface beschikbaar)
  - `canAccessPanel(Panel $panel): bool` implementeren (Panel-class beschikbaar)
  - `super-admin` + `staff` rollen seeden + 6 permissions toewijzen
- **Plan 09-04..09-10 (Resources)** kunnen straks bouwen op:
  - `discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')` is geconfigureerd
  - `Filament\Resources\Resource` base-class beschikbaar voor `php artisan make:filament-resource`-generator
- **Geen blocking dependencies open**.

## Verification Commands Run

| Command | Result |
|---|---|
| `composer install --no-interaction --prefer-dist --no-progress` | 55 packages geïnstalleerd (lokaal vendor) |
| `composer require "filament/filament:^4.0" "spatie/laravel-permission:^6.0" -W --no-interaction` | exit 0; 112 packages totaal |
| `composer show filament/filament` | v4.11.3 |
| `composer show spatie/laravel-permission` | 6.25.0 |
| `php artisan filament:install --panels --no-interaction` | "Successfully upgraded" + AdminPanelProvider gegenereerd + auto-geregistreerd |
| `php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --no-interaction` | config/permission.php + permission-migratie gepubliceerd |
| `php artisan migrate --no-interaction` | 1 migratie DONE (75.47ms) |
| `php artisan tinker --execute 'echo Schema::hasTable(...)'` | 5/5 Spatie-tabellen exist |
| `php artisan route:list --path=admin` | `GET admin/login` + `GET admin` + `POST admin/logout` registreerd |
| `php artisan config:show permission.models.role` | `Spatie\Permission\Models\Role` |
| `php artisan test --compact --filter=FilamentInstallSmokeTest` | 3 passed / 8 assertions / 913ms |
| `php artisan test --compact` | 343 passed / 1 incomplete / 0 failed / 1122 assertions |
| `vendor/bin/pint --dirty --format agent` | passed (Task 2); fixed bootstrap/providers.php (Task 1) |

## Self-Check: PASSED

**Files exist:**
- FOUND: app/Providers/Filament/AdminPanelProvider.php
- FOUND: config/permission.php
- FOUND: database/migrations/2026_05_15_221123_create_permission_tables.php
- FOUND: tests/Feature/Admin/FilamentInstallSmokeTest.php

**Commits exist:**
- FOUND: bdc6b3f — feat(09-02): installeer Filament v4 + Spatie laravel-permission v6
- FOUND: 07e5749 — test(09-02): smoke-test voor Filament install + Spatie tabellen

**Plan must_haves truths verified:**
- ✅ `composer show filament/filament` toont v4.11.3 (^4.0)
- ✅ `composer show spatie/laravel-permission` toont 6.25.0 (^6.0)
- ✅ 5 Spatie permission-tabellen bestaan na migrate
- ✅ AdminPanelProvider bestaat + geregistreerd in bootstrap/providers.php
- ✅ Provider configureert ->path('admin'), ->login(), ->authGuard('web'), ->discoverResources(...)
- ✅ GET /admin/login → 200 (test_admin_login_page_returns_200)

---
*Phase: 09-filament-admin-ui-voor-emeq-medewerkers*
*Completed: 2026-05-16*
