---
phase: 09-filament-admin-ui-voor-emeq-medewerkers
plan: 07
subsystem: admin-ui
tags: [filament, resource, read-only, accounts, webhook-calls, spatie-webhook-client, audit-viewer]

# Dependency graph
requires:
  - plan: 09-01
    provides: webhook_calls audit-kolommen (direction/provider/consumer_id/status)
  - plan: 09-02
    provides: Filament v4 install + AdminPanelProvider met discoverResources
  - plan: 09-03
    provides: User FilamentUser + Spatie HasRoles + staff/super-admin role-seed
provides:
  - AccountResource read-only (consumer-filter + connections_count)
  - WebhookCallResource read-only (4 filters + date-range + JSON-payload viewer)
  - Filament v4 nested-resource-structuur pattern (Resource + Tables/Schemas/Pages-subfolders)
affects: [09-08-account-subscription-resource, 09-09-cashier-subscription-resource, 09-10-user-resource]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Filament v4 nested-resource layout: app/Filament/Resources/<Plural>/{Resource,Tables/,Schemas/,Pages/}"
    - "Read-only resource: alleen index + view in getPages(), Create/Edit page-classes + Form-schema verwijderd"
    - "Spatie-vendor model in Filament: $model = Spatie\\WebhookClient\\Models\\WebhookCall + raw consumer_id lookup (geen relation-method)"
    - "Filter::make('created_at')->schema([DatePicker])->query(Builder) voor date-range zonder Filament-specifieke DateRangeFilter"
    - "Dynamische SelectFilter::options(fn () => Model::query()->distinct()->pluck(...)->all()) voor runtime-options"

key-files:
  created:
    - app/Filament/Resources/Accounts/AccountResource.php
    - app/Filament/Resources/Accounts/Tables/AccountsTable.php
    - app/Filament/Resources/Accounts/Schemas/AccountInfolist.php
    - app/Filament/Resources/Accounts/Pages/ListAccounts.php
    - app/Filament/Resources/Accounts/Pages/ViewAccount.php
    - app/Filament/Resources/WebhookCalls/WebhookCallResource.php
    - app/Filament/Resources/WebhookCalls/Tables/WebhookCallsTable.php
    - app/Filament/Resources/WebhookCalls/Schemas/WebhookCallInfolist.php
    - app/Filament/Resources/WebhookCalls/Pages/ListWebhookCalls.php
    - app/Filament/Resources/WebhookCalls/Pages/ViewWebhookCall.php
    - tests/Feature/Admin/AccountResourceTest.php
    - tests/Feature/Admin/WebhookCallResourceTest.php
  modified: []

key-decisions:
  - "Plan-paths gebruiken nog Filament v3 flat-structuur (app/Filament/Resources/AccountResource.php); Filament v4 genereert auto-nested layout — paden in SUMMARY weerspiegelen wat Filament daadwerkelijk produceert"
  - "WebhookCallResource gebruikt raw consumer_id-lookup via Consumer::find() ipv een nieuwe consumer()-relation op Spatie's vendor-model (model-extension uit scope)"
  - "Date-range op created_at via Filament\\Tables\\Filters\\Filter::schema([DatePicker]) — v4 heeft geen out-of-the-box DateRangeFilter; lokale custom-filter is idiomatisch"
  - "PAT_PRESETS/CUSTOM_ONLY-conventie hoort bij 09-05 (ConsumerResource), niet 09-07. Plan beschrijft consumer.slug-kolom via belongsTo en SelectFilter::make('consumer')->relationship() — beide werken in v4"

patterns-established:
  - "Filament v4 read-only resource workflow: artisan generates Resource+Tables+Schemas+Pages → delete Create/Edit Pages + Form-schema → getPages() = index+view only → recordActions([ViewAction::make()]) zonder EditAction/Bulk"
  - "Filament v4 row-actions live op het Tables-config-object (->recordActions([])), niet op Resource. Plan-text noemde v3-style ->actions()"
  - "Filament TextColumn->badge()->color(fn (?string \\$state) => match ...) voor enum-kleurmap (vs v3's BadgeColumn-klasse)"

requirements-completed: []  # HUB-04 wordt afgevinkt door plan 09-12 phase-acceptance, niet per-plan

# Metrics
duration: ~30min
completed: 2026-05-16
---

# Phase 09 Plan 07: AccountResource + WebhookCallResource Summary

**Twee read-only Filament v4 Resources (AccountResource + WebhookCallResource) met Filament v4's nested-folder-layout, consumer-filter op Accounts en 4 audit-filters + date-range + JSON-payload-viewer op WebhookCalls — 6 feature-tests groen, geen regressie.**

## Performance

- **Duration:** ~30 min
- **Started:** 2026-05-16T01:13:00Z
- **Completed:** 2026-05-16T23:25:00Z (worktree clock)
- **Tasks:** 3 (2 build + 1 TDD test-pair)
- **Files created:** 12 (5 Account-resource files + 5 WebhookCall-resource files + 2 tests)

## Accomplishments

- AccountResource bereikbaar op `/admin/accounts` met 5 tabel-kolommen (consumer.slug, external_id, display_name, connections_count, created_at) + SelectFilter op consumer-relationship
- WebhookCallResource bereikbaar op `/admin/webhook-calls` met 6 kolommen (direction/provider/status badges + consumer-slug-lookup + name + created_at) + 5 filters (4 SelectFilters + date-range)
- WebhookCall ViewPage rendert collapsible pretty-printed JSON-payload + exception-blob
- Beide Resources volledig read-only: getPages() = `index` + `view`, geen Create/Edit/Delete-pages, recordActions zonder mutate-acties
- 6 nieuwe feature-tests in `tests/Feature/Admin/` (3 per Resource); full suite 359 passed / 1 incomplete (pre-existing) / 0 failed

## Task Commits

Atomic per-task:

1. **Task 1: AccountResource** — `61ede13` (feat) — 5 files (Resource + Tables + Schemas/Infolist + Pages: ListAccounts + ViewAccount)
2. **Task 2: WebhookCallResource** — `5663354` (feat) — 5 files (Resource + Tables + Schemas/Infolist + Pages: ListWebhookCalls + ViewWebhookCall); $model = Spatie\\WebhookClient\\Models\\WebhookCall
3. **Task 3: Feature-tests** — `0624254` (test) — AccountResourceTest (3 tests) + WebhookCallResourceTest (3 tests)

## Files Created/Modified

### AccountResource (Filament v4 nested layout)
- `app/Filament/Resources/Accounts/AccountResource.php` (new) — Resource-class, $model = Account, getPages = index+view
- `app/Filament/Resources/Accounts/Tables/AccountsTable.php` (new) — 5 kolommen + SelectFilter('consumer')->relationship('consumer', 'slug')
- `app/Filament/Resources/Accounts/Schemas/AccountInfolist.php` (new) — 6 TextEntry-fields voor view-page
- `app/Filament/Resources/Accounts/Pages/ListAccounts.php` (new) — getHeaderActions: []
- `app/Filament/Resources/Accounts/Pages/ViewAccount.php` (new) — getHeaderActions: []

### WebhookCallResource (Filament v4 nested layout)
- `app/Filament/Resources/WebhookCalls/WebhookCallResource.php` (new) — Resource-class, $model = Spatie\\WebhookClient\\Models\\WebhookCall
- `app/Filament/Resources/WebhookCalls/Tables/WebhookCallsTable.php` (new) — 6 kolommen + 5 filters (4 Select + date-range)
- `app/Filament/Resources/WebhookCalls/Schemas/WebhookCallInfolist.php` (new) — 10 TextEntry-fields incl. JSON-payload pretty-print
- `app/Filament/Resources/WebhookCalls/Pages/ListWebhookCalls.php` (new)
- `app/Filament/Resources/WebhookCalls/Pages/ViewWebhookCall.php` (new)

### Tests
- `tests/Feature/Admin/AccountResourceTest.php` (new) — 3 tests (list-all, consumer-filter, view-page-200)
- `tests/Feature/Admin/WebhookCallResourceTest.php` (new) — 3 tests (list-audit-rows, direction-filter, payload-JSON in view)

## Decisions Made

1. **Filament v4 nested-resource layout overgenomen uit generator-output** — plan-paths gingen uit van v3 flat-structuur. Filament v4 splitst Resource → 4 sub-folders (Tables/Schemas/Pages + de Resource-class zelf in een `<Plural>` namespace). De heads_up uit het orchestrator-prompt zei expliciet "volg Filament's eigen output en update plan-paths in je SUMMARY". Doorgevoerd: alle 10 resource-files in v4-nested-structuur. Plan-paths in 09-07-PLAN.md frontmatter (`files_modified`) zijn historisch; deze SUMMARY is autoritatief voor de daadwerkelijke locatie.

2. **WebhookCall consumer-resolution via raw lookup, niet via relation-method** — Spatie's `Spatie\\WebhookClient\\Models\\WebhookCall` is een vendor-model dat we niet subclassen voor scope-redenen. De `consumer_id` audit-kolom uit 09-01 wordt in de TextColumn opgelost via `Consumer::find($record->consumer_id)?->slug` — N+1 acceptabel voor admin-list met paginate. Toekomstige optimization-route: Eloquent global-scope of een dunne extension-model in `app/Models/HubWebhookCall.php` als Phase-9-12 ADR dit pickt.

3. **Date-range filter via custom Filter::schema([DatePicker])** — Filament v4 heeft geen out-of-the-box DateRangeFilter-klasse zoals plan suggereerde. Idiomatisch: `Filter::make('created_at')->schema([DatePicker::make('from'), DatePicker::make('until')])->query(Builder $query, array $data)` met `whereDate >= / <=`. Pattern is herbruikbaar voor andere date-range filters in 09-08 AccountSubscriptionResource (created_at, last_webhook_event_at).

4. **Geen `--view`-side-effects vertrouwd** — `php artisan make:filament-resource <Name> --view` genereert in v4 nog steeds Create + Edit page-classes en een `form()`-method. Handmatig verwijderd: Create/Edit Pages + Form-schema (waar van toepassing). Read-only invariant geforceerd op niveau van `getPages()` returns.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] vendor-symlink in worktree-mode breekt Filament resource discovery**
- **Found during:** Task 1 verify-step — `php artisan route:list --path=admin/accounts` returned "no routes" ondanks dat AccountResource-files bestonden.
- **Issue:** De worktree-bootstrap had `vendor/` als symlink naar de main-repo (`vendor → /Users/.../emeq-hub/vendor`). Composer's autoload genereert `$vendorDir = dirname(__DIR__)` waarbij PHP's `realpath` de symlink resolved → `$baseDir` werd het main-repo-pad → `app/Filament/Resources/` van de main repo werd gescanned (= leeg) ipv die van de worktree. `Application::inferBasePath()` in tests is centraal gefixt via `tests/TestCase.php::createApplication()` (09-01 Rule-3 deviation), maar dat geldt niet voor de `php artisan`-CLI buiten testcontext.
- **Fix:** vendor-symlink vervangen door een rsync hardlink-mirror van de main repo's vendor (`rsync -a --link-dest=...`); daarna `composer dump-autoload -o` op de worktree. `$baseDir` wijst nu correct naar de worktree-root; Filament's `discoverResources(in: app_path('Filament/Resources'))` scant de worktree-app-dir.
- **Files modified:** geen versiebeheerde files — vendor-mirror staat buiten git (zit in .gitignore via Laravel default).
- **Verification:** `php artisan route:list --path=admin/accounts` toont 2 routes; `php artisan route:list --path=admin/webhook-calls` toont 2 routes; full test suite 359 passed.
- **Committed in:** N/A — infra-fix, niet in git tree.

---

**Total deviations:** 1 auto-fixed (Rule 3 - Blocking).
**Impact on plan:** Geen scope-uitbreiding; infra-fix die alleen de worktree-execution-omgeving raakt. Voor toekomstige worktree-execute-runs: bootstrap moet vendor als hardlink-copy of fresh `composer install` doen, niet als symlink. STATE.md `Pending Todos` heeft dit al als recurring backlog-item ("Worktree-bootstrap-pattern"); SUMMARY noteert dat de hardlink-mirror-aanpak (~28s rsync) een productie-ready alternatief is voor het symlink-pattern als een runtime-fix nodig is.

## Issues Encountered

- **Filament v4 nesting weicht af van plan-paths** — plan v3-style `app/Filament/Resources/AccountResource.php`; v4 produceert `app/Filament/Resources/Accounts/AccountResource.php` met namespace `App\\Filament\\Resources\\Accounts`. De heads_up uit het orchestrator-prompt vertelde dit op voorhand; SUMMARY documenteert de afwijking.
- **`--view` flag genereert nog steeds Create/Edit** — Filament v4's `--view` toggle is een 'add view-page', niet 'read-only resource'. Verwacht (na deze plan): future plans 09-08+ doen hetzelfde patroon (rm Create/Edit/Form-schema, rewrite getPages).

## Known Stubs

Geen. WebhookCallResource toont reële data zodra Phase 5c (Snelstart webhook-handler in progress) + Phase 5a (Mollie webhooks live) rijen schrijven met de 09-01 audit-kolommen. Tests bewijzen het rendering-pad voor de full-audit-row-shape.

## Threat Flags

Geen nieuwe surface buiten threat-model. Plan 09-07 threat-register adresseert:

- T-09-07-01 (info-disclosure Spatie webhook_calls.payload) — `accept` blijft; geen PII in Mollie/Snelstart-payloads anno 2026-05-16.
- T-09-07-02 (elevation via Livewire-action-injection) — `mitigate` door geen mutate-actions in recordActions; ViewAction is read-only.
- T-09-07-03 (cross-Consumer admin-view) — `accept` per ontwerp; Filament-staff is Hub-level admin.
- T-09-07-04 (Account.external_id mutation via UI) — `mitigate` door geen EditPage.
- T-09-07-SC (geen package-install) — `accept` gerespecteerd; deze plan voegt 0 composer-deps toe.

## User Setup Required

Geen. Beide Resources verschijnen automatisch in `/admin` sidebar zodra een staff-User inlogt (Filament v4 auto-discovery via `discoverResources(in: app_path('Filament/Resources'))` in AdminPanelProvider, geland in 09-02).

## Next Phase Readiness

- **Plan 09-08 (AccountSubscriptionResource)** — kan dezelfde Filament v4 nested-layout-conventie en read-only-recipe direct overnemen. Pattern voor state-flip-actions via AccountSubscriptionManager komt erbij; geen overlap met 09-07.
- **Plan 09-09 (CashierSubscriptionResource)** — derived-status (geen DB-kolom) wordt vergelijkbaar opgelost met `TextColumn::make('derived_status')->state(fn (\\$record) => \\$record->active() ? 'active' : ...)`.
- **Plan 09-10 (UserResource)** — gate-pattern + super-admin-only navigation; geen overlap.
- **Plan 09-11 (ProviderCredentialDescriptor implementatie)** — onafhankelijk van 09-07; descriptor wordt door ConnectionResource (09-06) geconsumeerd.
- **Plan 09-12 (phase-acceptance)** — moet HUB-04 succescriteria 7 (WebhookCallResource direction/provider/status filters + cross-Consumer-isolation) afvinken; tests in deze plan dekken het filter-deel; cross-Consumer-isolation is een acceptance-by-design (admin sees all).

## Self-Check: PASSED

**Files exist (worktree-relative):**
- FOUND: app/Filament/Resources/Accounts/AccountResource.php
- FOUND: app/Filament/Resources/Accounts/Tables/AccountsTable.php
- FOUND: app/Filament/Resources/Accounts/Schemas/AccountInfolist.php
- FOUND: app/Filament/Resources/Accounts/Pages/ListAccounts.php
- FOUND: app/Filament/Resources/Accounts/Pages/ViewAccount.php
- FOUND: app/Filament/Resources/WebhookCalls/WebhookCallResource.php
- FOUND: app/Filament/Resources/WebhookCalls/Tables/WebhookCallsTable.php
- FOUND: app/Filament/Resources/WebhookCalls/Schemas/WebhookCallInfolist.php
- FOUND: app/Filament/Resources/WebhookCalls/Pages/ListWebhookCalls.php
- FOUND: app/Filament/Resources/WebhookCalls/Pages/ViewWebhookCall.php
- FOUND: tests/Feature/Admin/AccountResourceTest.php
- FOUND: tests/Feature/Admin/WebhookCallResourceTest.php

**Commits exist (git log --oneline -5):**
- FOUND: 61ede13 — feat(09-07): AccountResource — read-only met consumer-filter
- FOUND: 5663354 — feat(09-07): WebhookCallResource — read-only viewer met 4 filters + JSON-payload
- FOUND: 0624254 — test(09-07): feature-tests voor AccountResource + WebhookCallResource

**Verification commands passed:**
- `php artisan route:list --path=admin/accounts` → 2 routes (index + view)
- `php artisan route:list --path=admin/webhook-calls` → 2 routes (index + view)
- `php artisan test --compact --filter='AccountResourceTest|WebhookCallResourceTest'` → 6 passed, 18 assertions
- `php artisan test --compact` → 359 passed, 1 incomplete (pre-existing), 0 failed
- `vendor/bin/pint --dirty --format agent` → passed

---
*Phase: 09-filament-admin-ui-voor-emeq-medewerkers*
*Completed: 2026-05-16*
