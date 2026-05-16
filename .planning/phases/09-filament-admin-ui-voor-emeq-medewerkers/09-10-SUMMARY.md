---
phase: 09-filament-admin-ui-voor-emeq-medewerkers
plan: 10
subsystem: filament-user-resource
tags: [filament-resource, super-admin-gate, spatie-roles, password-hashing, livewire-tests]

# Dependency graph
requires:
  - plan: 09-03-user-model-emeq-staff-seeder
    provides: User implements FilamentUser + HasRoles-trait + canAccessPanel-gate; manage-staff is een gedefinieerde permission
  - plan: 09-02-filament-spatie-install
    provides: Filament v4 Resource-base + Spatie roles/permissions tabellen
provides:
  - Gate::define('manage-staff', fn (User $user) => $user->hasRole('super-admin')) in AppServiceProvider::boot()
  - UserResource (Filament v4 nested namespace App\Filament\Resources\Users\*) met canAccess + shouldRegisterNavigation gated op 'manage-staff'
  - Custom assignRole-action via Spatie syncRoles (één rol per user per D-05)
  - PermissionGatingTest + UserResourceTest — 6 tests bewijzen 3-tier gate + CRUD-flow
affects: [09-11-phase-acceptance]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Filament v4 Resource-gating: `public static function canAccess(): bool` + `shouldRegisterNavigation(): bool` beide gated op één Gate::allows() — sidebar-link verdwijnt + URL 403"
    - "Password-form-pattern Filament v4: `->dehydrateStateUsing(Hash::make)->dehydrated(filled)->required(fn (string \$operation) => \$operation === 'create')` — bewaart hash bij edit zonder leeg veld te overschrijven"
    - "Filament v4 custom record-action met form-schema: `Action::make(name)->schema([Select::make(...)])->action(fn (\$record, \$data) => ...)`"
    - "Livewire-test Filament v4 table-actions: `Livewire::test(ListPage::class)->callTableAction('actionName', \$record, \$data)->assertHasNoTableActionErrors()`"

key-files:
  created:
    - app/Filament/Resources/Users/UserResource.php
    - app/Filament/Resources/Users/Pages/ListUsers.php
    - app/Filament/Resources/Users/Pages/CreateUser.php
    - app/Filament/Resources/Users/Pages/EditUser.php
    - app/Filament/Resources/Users/Schemas/UserForm.php
    - app/Filament/Resources/Users/Tables/UsersTable.php
    - tests/Feature/Admin/PermissionGatingTest.php
    - tests/Feature/Admin/UserResourceTest.php
  modified:
    - app/Providers/AppServiceProvider.php

key-decisions:
  - "Filament v4 genereert 6 files in nested namespace (`App\\Filament\\Resources\\Users\\*`), niet de v3-flat 4-file structuur uit het plan — `Schemas/UserForm.php` + `Tables/UsersTable.php` zijn aparte schema-/table-configuratie-classes. Plan-acceptance criteria over 4 files aangepast naar v4-werkelijkheid (Rule 3 deviation)."
  - "Password gehashed via `dehydrateStateUsing(fn (\$state) => Hash::make((string) \$state))` op het TextInput-veld, niet via User-model casts. Reden: User-model heeft `'password' => 'hashed'` cast (`app/Models/User.php`), maar Filament's `dehydrateStateUsing` wordt vóór de cast aangeroepen — dubbele hash zou ontstaan. Daarom expliciet `Hash::make` in dehydrate + `dehydrated(fn (\$state) => filled(\$state))` om edit-flow te beschermen tegen overschrijven met lege string."
  - "`syncRoles([\$role])` ipv `assignRole(\$role)` — per D-05 ontwerp is een User altijd super-admin OF staff, niet beide. `syncRoles` wist bestaande rol; `assignRole` zou stacken."
  - "`name`-veld toegevoegd aan UserForm bovenop email+password (plan noemde alleen email+password). Reden: `UserFactory` definitie verwacht name en de UsersTable toont een name-kolom; zonder name-veld zou create-flow falen op NOT-NULL DB-constraint. Engineering.md 'lezen vóór schrijven': User-model heeft `name` in `#[Fillable]`."

# Metrics
duration: ~25min
completed: 2026-05-16
---

# Phase 09 Plan 10: UserResource CRUD + manage-staff gate Summary

**Gate `manage-staff` geregistreerd in AppServiceProvider; Filament v4 UserResource (super-admin-only via canAccess + shouldRegisterNavigation) met email/name/password-form, role-pluck-kolom en custom assignRole-action via Spatie syncRoles; 6 nieuwe Livewire-feature-tests groen (3 PermissionGating + 3 UserResource); full suite 359/359.**

## Performance

- **Duration:** ~25 min
- **Tasks:** 3 atomic commits
- **Files created:** 8 (6 UserResource + 2 tests)
- **Files modified:** 1 (AppServiceProvider)

## Accomplishments

- `Gate::define('manage-staff', ...)` actief — `php -r '... Gate::has("manage-staff")'` → OK
- UserResource correct geregistreerd: `php artisan route:list` toont `admin/users`, `admin/users/create`, `admin/users/{record}/edit`
- 5/5 plan-must-have-truths empirisch bewezen (zie tabel hieronder)
- HUB-04 success-criterium 9 ("staff-user 403 op UserResource") rood→groen
- Full test-suite 359/359 (was 353 + 6 nieuwe; pre-existing incomplete blijft)
- Pint clean across alle gewijzigde files

## Task Commits

Atomic per-task commits op `worktree-agent-a08d6aa07577452fb`:

1. **Task 1:** `5a645b9` (feat) — registreer manage-staff gate in AppServiceProvider::boot()
2. **Task 2:** `4a9c54e` (feat) — UserResource (super-admin only) met CRUD + Assign role-action
3. **Task 3:** `9e117be` (test) — PermissionGatingTest + UserResourceTest — D-05 gate + CRUD-flow

## Must-Have Truths — Empirically Verified

| # | Truth | Bewijs |
|---|---|---|
| 1 | `/admin/users` is alleen bereikbaar voor super-admin; staff → 403 | `PermissionGatingTest::test_staff_user_cannot_access_user_resource` (assertForbidden) + `test_super_admin_can_access_user_resource` (assertOk) |
| 2 | UserResource navigation-link NIET zichtbaar voor staff in sidebar | `PermissionGatingTest::test_staff_user_does_not_see_user_navigation_link` (`assertDontSee('admin/users')`) |
| 3 | Super-admin kan User aanmaken (email + password) + Assign role-action gebruiken | `UserResourceTest::test_super_admin_can_create_user_via_resource` (`Hash::check` passes) + `test_super_admin_can_assign_role_via_action` (`hasRole('staff')` true) |
| 4 | `Gate::define('manage-staff', fn (User $user) => $user->hasRole('super-admin'))` in AppServiceProvider::boot() | `grep "Gate::define('manage-staff'"` → match + `Gate::has('manage-staff')` returnt true |
| 5 | `UserResource::canAccess()` returnt `Gate::allows('manage-staff')` | Source-inspectie + gedrag-bewijs via test 1 (`assertForbidden`/`assertOk`-pair) |

## Files Created/Modified

**Created (8):**

- `app/Filament/Resources/Users/UserResource.php` — Resource met `canAccess()` + `shouldRegisterNavigation()` gated; `getPages()` met index/create/edit; navigationIcon `Heroicon::OutlinedUserGroup`
- `app/Filament/Resources/Users/Pages/ListUsers.php` — Filament-gen, default met `CreateAction`
- `app/Filament/Resources/Users/Pages/CreateUser.php` — Filament-gen, leeg (default)
- `app/Filament/Resources/Users/Pages/EditUser.php` — Filament-gen met `DeleteAction`
- `app/Filament/Resources/Users/Schemas/UserForm.php` — `TextInput::make('name')` + `TextInput::make('email')` (email + unique ignoreRecord) + `TextInput::make('password')` met `dehydrateStateUsing(Hash::make) + dehydrated(filled) + required(fn ... 'create')`
- `app/Filament/Resources/Users/Tables/UsersTable.php` — 4 kolommen (name/email/roles-via-pluck/created_at) + `EditAction` + custom `Action::make('assignRole')` met `Select` form en `syncRoles([data['role']])` callback + `Notification` op success
- `tests/Feature/Admin/PermissionGatingTest.php` — 3 tests met `seedRolesAndPermissions()` helper
- `tests/Feature/Admin/UserResourceTest.php` — 3 Livewire-tests met `actingAsSuperAdmin()` helper

**Modified (1):**

- `app/Providers/AppServiceProvider.php` — 2 regels toegevoegd in `boot()` na bestaande `viewApiDocs` Gate::define: `Gate::define('manage-staff', fn (User $user): bool => $user->hasRole('super-admin'));`

## Decisions Made

### Filament v4 genereert 6 files (geen 4) — plan-paths aangepast

`php artisan make:filament-resource User` in Filament v4 produceert 6 files in nested namespace:

```
app/Filament/Resources/Users/
├── UserResource.php
├── Pages/
│   ├── ListUsers.php
│   ├── CreateUser.php
│   └── EditUser.php
├── Schemas/
│   └── UserForm.php
└── Tables/
    └── UsersTable.php
```

Het plan's `files_modified`-blok noemde 4 v3-flat paths (`app/Filament/Resources/UserResource.php` zonder `Users/`-subdir). Heads-up in opdracht corrigeerde dit naar v4-werkelijkheid; namespace = `App\Filament\Resources\Users`. Form en Table zijn eigen configuratie-classes — `UserResource::form()` delegeert naar `UserForm::configure($schema)`, `UserResource::table()` naar `UsersTable::configure($table)`. Dit is Filament v4 default — niet `--embed-schemas` of `--simple` gebruikt.

### Password-hashing in form, niet via model-cast

User-model heeft `protected function casts(): array { return ['password' => 'hashed', …]; }` (Laravel `hashed` cast). Filament's `dehydrateStateUsing` callback wordt **vóór** de cast aangeroepen. Zonder expliciet `Hash::make` in dehydrate zou de hashed-cast óók draaien → double-hash. Daarom:

```php
TextInput::make('password')
    ->password()
    ->dehydrateStateUsing(fn ($state) => Hash::make((string) $state))
    ->dehydrated(fn ($state) => filled($state))
    ->required(fn (string $operation): bool => $operation === 'create')
```

`dehydrated(filled)` zorgt dat edit-flow met lege password het bestaande veld niet overschrijft — anders zou een leeg-laten-bij-edit de hash naar `Hash::make('')` zetten. `required` is alleen op `'create'`-operation actief.

(Heads-up gebruikte `string $operation` voor de required-callback; in Filament v4 is dat de juiste parameter-naam. Werkt via test 1 in UserResourceTest die `Hash::check('Secret123!', ...)` bewijst.)

### `syncRoles` ipv `assignRole`

D-05: User heeft één rol (`super-admin` OF `staff`). `syncRoles([$role])` overschrijft de huidige rol; `assignRole($role)` zou stacken (User kon dan beide rollen hebben). Bewezen door test 2 in UserResourceTest: `$target` heeft eerst geen rollen, na `assignRole`-action exact één (`staff`).

### `name`-veld bovenop email+password (plan-extensie, Rule 2)

Plan beschreef alleen `email` + `password` in de form. `users.name` is echter NOT-NULL (Laravel-default) en in User's `#[Fillable]`. Zonder `name`-veld faalt elke create-flow op DB-constraint. Toegevoegd als eerste form-veld — sluit aan op UsersTable's `name`-kolom. Engineering.md 'lezen vóór schrijven' bevestigt: User-model fillable = `['name', 'email', 'password']`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Worktree-vendor + .env ontbraken in agent-worktree**

- **Found during:** Pre-Task 1 worktree-bootstrap
- **Issue:** Het worktree-bootstrap-snippet verwachtte `../../.env` en `../../vendor`, maar die paths bestaan niet vanuit `.claude/worktrees/agent-a08d6aa07577452fb/`. Een initiële `cp` produceerde een gen-nested `vendor/vendor/`-structuur (cp -R aangeroepen na bestaande `vendor/`-leeg-dir gemaakt door eerder vendor-cp-niet-gefinished). PHP kon `Illuminate\Foundation\Application` niet vinden.
- **Fix:** Absolute paden gebruikt (`/Users/yusufkaracaburun/Sites/localhost/emeq-hub/{vendor,.env}`); `vendor/` weggegooid en met `rsync -a` opnieuw aangelegd; daarna `composer dump-autoload -q` om classmap-paths van vorige worktree (in `vendor/composer/autoload_*.php`) te overschrijven.
- **Files modified:** geen committed files (`.env` + `vendor/` zijn gitignored)
- **Verification:** `php artisan --version` → "Laravel Framework 13.9.0"; full suite 359/359 groen.

**2. [Rule 2 - Critical] `name`-veld toegevoegd aan UserForm**

- **Found during:** Task 2 UserResource-build
- **Issue:** Plan-acceptance criteria noemden alleen `email` + `password`. Maar `users.name` is NOT-NULL (Laravel-default migratie) en in User's `#[Fillable(['name', 'email', 'password'])]`. Zonder name-veld zou elke Filament create-flow falen op DB-constraint.
- **Fix:** `TextInput::make('name')->required()->maxLength(255)` als eerste form-veld toegevoegd; UsersTable's name-kolom toont het. UserResourceTest test 1 fill nu ook `'name' => 'Nieuwe Admin'` zodat create-flow groen blijft.
- **Files modified:** `app/Filament/Resources/Users/Schemas/UserForm.php` + `tests/Feature/Admin/UserResourceTest.php`
- **Commit:** `4a9c54e` + `9e117be`

**3. [Rule 3 - Blocking] Filament v4 nested namespace ipv v3-flat plan-paths**

- **Found during:** Task 2 `php artisan make:filament-resource User --no-interaction`
- **Issue:** Plan-paths waren `app/Filament/Resources/UserResource.php` (v3-flat). Filament v4 genereert `app/Filament/Resources/Users/UserResource.php` met namespace `App\Filament\Resources\Users` en sub-mappen `Pages/`, `Schemas/`, `Tables/`. 6 files ipv 4.
- **Fix:** Plan-paths interpretatief aangepast naar v4-realiteit (heads-up bevestigde dit al). Test-imports gebruiken `App\Filament\Resources\Users\Pages\{Create,List}User`. Geen `--embed-schemas` of `--simple` gebruikt — default v4 layout houdt UserForm/UsersTable als aparte configuratie-classes (consistent met toekomstige plans).
- **Files modified:** alle 6 Filament-files in v4-paths
- **Commit:** `4a9c54e`

**Geen Rule 4 architecturele changes nodig.** Plan-tasks 1-3 uitgevoerd zoals beschreven, met de drie auto-fixes hierboven die niets aan de gate-semantiek of CRUD-shape wijzigen.

## Issues Encountered

- **Worktree-bootstrap recurring** (zelfde pattern als 09-03 deviation 1): de meegegeven `../../...`-paden in het bootstrap-snippet zijn niet correct voor de huidige `.claude/worktrees/agent-<id>/`-layout. Absolute paths + `rsync -a` + `composer dump-autoload` is de stable workaround. Geen blocker voor de plan-tasks zelf.

## Known Stubs

Geen. Alle code is functioneel en gedekt door tests:
- `UserResource::canAccess()` is geen `true`-stub maar `Gate::allows('manage-staff')`
- `assignRole`-action callback roept echt `$record->syncRoles(...)` aan en fired een Notification
- 6 tests asserteren echte DB-state, HTTP-responses en Livewire-form-state

## Threat Flags

Geen nieuwe surface buiten het plan's threat-model. Alle 6 STRIDE-items uit `<threat_model>` zijn intact en bewezen mitigated/accepted:

- **T-09-10-01 (mitigated):** Staff-rol User upgrade zichzelf naar super-admin — `canAccess()` + `shouldRegisterNavigation()` gated; PermissionGatingTest test 1 + 3 bewijzen 403 + geen nav-link
- **T-09-10-02 (accepted):** Filament v4 navigation renders per-request (Livewire-component) — geen permanente cache; bewezen door test 3 die direct na role-assignment de sidebar inspecteert
- **T-09-10-03 (mitigated):** Password-hash nooit in HTML — `TextInput::make('password')->password()` (input-type=password); User-model `#[Hidden(['password'])]` redact in `toArray()`
- **T-09-10-04 (accepted):** DB-level UNIQUE op `users.email` vangt race-condition; Filament `unique(ignoreRecord: true)` vangt edit-eigen-email niet als duplicaat
- **T-09-10-05 (accepted):** `syncRoles([$role])` wist alle bestaande rollen — per ontwerp, één rol per User
- **T-09-10-06 (accepted):** Audit-trail out of scope (HUB-AUDIT backlog)

## User Setup Required

Geen extra setup buiten plan 09-03's EmeqStaffSeeder-bootstrap. Een super-admin kan na deploy direct via `/admin/users` nieuwe staff onboarden:

1. Login als bootstrap super-admin op `/admin/login`
2. Sidebar → "Users" → "New User"
3. Vul name + email + password → Create
4. In de tabel → "Wijs rol toe" record-action → kies `super-admin` of `staff`
5. Staff kan vanaf nu inloggen op `/admin` maar ziet de Users-sidebar-link niet

## Next Plan Readiness

- **Plan 09-11 (phase-acceptance/ADR)** ongewijzigd ready; alle 7 resources beschreven in CONTEXT.md zijn nu landed (Consumer 09-05, Connection 09-06, Account 09-07, WebhookCall 09-08, AccountSubscription 09-09, Cashier-Subscription? + User 09-10) — verifieer in 09-11 dat phase-resource-count klopt met CONTEXT.md D-01
- HUB-04 SC-9 bewezen — kan in 09-11 als ✅ gemarkeerd

## Verification Commands Run

| Command | Result |
|---|---|
| `grep -q "Gate::define('manage-staff'" app/Providers/AppServiceProvider.php` | OK |
| `grep -q "hasRole('super-admin')" app/Providers/AppServiceProvider.php` | OK |
| `php -r "... Gate::has('manage-staff') ..."` (post-bootstrap) | OK |
| `php artisan route:list` (filtered `admin/users`) | 3 routes (index/create/edit) |
| `grep -q "User::class" app/Filament/Resources/Users/UserResource.php` | OK |
| `grep -q "Gate::allows('manage-staff')" app/Filament/Resources/Users/UserResource.php` | OK |
| `grep -q "canAccess" app/Filament/Resources/Users/UserResource.php` | OK |
| `grep -q "shouldRegisterNavigation" app/Filament/Resources/Users/UserResource.php` | OK |
| `grep -q "syncRoles" app/Filament/Resources/Users/Tables/UsersTable.php` | OK |
| `./vendor/bin/pint --dirty --format agent` | passed (3× runs) |
| `php artisan test --compact --filter='PermissionGatingTest\|UserResourceTest'` | 6 passed / 26 assertions / 1713ms |
| `php artisan test --compact` (full suite) | 359 passed / 1 incomplete / 0 failed / 1177 assertions / 12591ms |

## Self-Check: PASSED

**Files exist:**
- FOUND: app/Providers/AppServiceProvider.php (modified)
- FOUND: app/Filament/Resources/Users/UserResource.php
- FOUND: app/Filament/Resources/Users/Pages/ListUsers.php
- FOUND: app/Filament/Resources/Users/Pages/CreateUser.php
- FOUND: app/Filament/Resources/Users/Pages/EditUser.php
- FOUND: app/Filament/Resources/Users/Schemas/UserForm.php
- FOUND: app/Filament/Resources/Users/Tables/UsersTable.php
- FOUND: tests/Feature/Admin/PermissionGatingTest.php
- FOUND: tests/Feature/Admin/UserResourceTest.php

**Commits exist:**
- FOUND: 5a645b9 — feat(09-10): registreer manage-staff gate in AppServiceProvider::boot()
- FOUND: 4a9c54e — feat(09-10): UserResource (super-admin only) met CRUD + Assign role-action
- FOUND: 9e117be — test(09-10): PermissionGatingTest + UserResourceTest — D-05 gate + CRUD-flow

**Plan must-haves truths verified:** alle 5/5 truths uit het `must_haves.truths`-blok empirisch bewezen via 6 nieuwe tests + grep-asserties.

**Key links verified:**
- `app/Filament/Resources/Users/UserResource.php` → `app/Providers/AppServiceProvider.php (Gate::define)` via `Gate::allows('manage-staff')` in `canAccess()` en `shouldRegisterNavigation()` — gecheckt via grep + test 1 (403/200 split)

---
*Phase: 09-filament-admin-ui-voor-emeq-medewerkers*
*Completed: 2026-05-16*
