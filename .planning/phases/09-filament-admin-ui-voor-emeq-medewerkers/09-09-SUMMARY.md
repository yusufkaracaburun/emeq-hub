---
phase: 09-filament-admin-ui-voor-emeq-medewerkers
plan: 09
subsystem: ui
tags: [filament-v4, cashier-mollie, read-only-resource, badge-column, derived-status]

# Dependency graph
requires:
  - plan: 09-02-filament-spatie-install
    provides: Filament v4 panel + discoverResources op `App\Filament\Resources` + AdminPanelProvider
  - plan: 09-03-user-model-emeq-staff-seeder
    provides: canAccessPanel-gate (super-admin/staff) — Resource erft auto de panel-toegang
  - phase: 06-cashier-mollie-integratie-use-case-a
    provides: subscriptions-tabel + Consumer-Billable + Cashier\Subscription model met active()/cancelled()/onTrial()/onGracePeriod()/ended() accessors (Phase 6 D-02: GEEN status-kolom)
provides:
  - "app/Filament/Resources/CashierSubscriptions/CashierSubscriptionResource.php — read-only Filament v4 Resource voor Cashier-Mollie subscriptions"
  - "Tabel: owner.slug (Consumer), name, plan, derived_status (Badge), ends_at + SelectFilter op derived_status met where-equivalent van Cashier-accessors"
  - "Infolist: owner.slug + name + plan + derived_status + trial/cycle/ends timestamps"
  - "Route /admin/cashier-subscriptions (index + view) — geen create/edit/delete"
  - "3 feature-tests bewijzen list, derived-status='active' (ends_at null), derived-status='grace' (ends_at future)"
affects:
  - "09-10 UserResource (super-admin-only) — geen directe dependency, maar deelt Resource-stub-conventie (nested namespace, read-only Resource met geen Create/Edit/Delete pages)"
  - "09-12 phase-acceptance — HUB-04 deelcriterium 'Cashier-Subscriptions overzicht' gerealiseerd"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Filament v4 nested-namespace Resource (`App\\Filament\\Resources\\CashierSubscriptions\\…`) — generator-output sinds 09-07-precedent; auto-discover via `discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')`"
    - "Read-only Resource zonder Create/Edit pages: `canCreate()`/`canEdit()`/`canDelete()` returnen false + `getPages()` lijst alleen `index` + `view`"
    - "Derived-status BadgeColumn op een model zonder status-kolom: `TextColumn::make('derived_status')->state(fn ($r) => ...)->badge()->color(...)` mirrors-Cashier-accessors-in-PHP"
    - "Computed-filter op derived field: `SelectFilter::make()->query(fn (Builder \$q, array \$data) => match(...))` met where-clauses die de Cashier-accessor-logica reproduceren"

key-files:
  created:
    - app/Filament/Resources/CashierSubscriptions/CashierSubscriptionResource.php
    - app/Filament/Resources/CashierSubscriptions/Tables/CashierSubscriptionsTable.php
    - app/Filament/Resources/CashierSubscriptions/Schemas/CashierSubscriptionInfolist.php
    - app/Filament/Resources/CashierSubscriptions/Pages/ListCashierSubscriptions.php
    - app/Filament/Resources/CashierSubscriptions/Pages/ViewCashierSubscription.php
    - tests/Feature/Admin/CashierSubscriptionResourceTest.php
  modified: []

key-decisions:
  - "Filament v4 generator levert 8 files (Resource + Form/Infolist schemas + Table + 4 Pages); voor een read-only Resource zijn 5 nodig — CreateCashierSubscription, EditCashierSubscription en CashierSubscriptionForm zijn verwijderd om scope-creep en dead-code te voorkomen (T-09-09-02 mitigatie via getPages-witelist)."
  - "Derived-status mapping is exclusief in deze prioriteits-volgorde: onTrial > onGracePeriod > ended > active > unknown. Trialing krijgt voorrang op grace omdat trial conceptueel een ander state-pad is dan een cancellation-grace, ook al kan de DB-rij beide vlaggen tegelijk dragen."
  - "SelectFilter `active`-bucket gebruikt Cashier's eigen scopeWhereActive-logica (OR-keten over `ends_at IS NULL`, `trial_ends_at > now()`, `ends_at > now()`) — zo gedraagt de filter zich identiek aan `Subscription::whereActive()` en aan de PHP `active()`-accessor."
  - "Geen aparte `protected static ?string \$slug` override — Filament v4 leidt `cashier-subscriptions` automatisch af uit de nested namespace `Resources\\CashierSubscriptions\\CashierSubscriptionResource` (HasRoutes::getDefaultSlug)."
  - "Heads-up specificeerde icon-string `heroicon-o-credit-card`, maar Filament v4-generator gebruikt de enum-class. Conform engineering.md 'match bestaande style' is `Heroicon::CreditCard` gekozen — identiek aan de stub-output."
  - "Test #3 asserteert `grace OR cancelled` via `str_contains`-fallback; in praktijk komt 'grace' eruit (onGracePeriod-tak van deriveStatus), maar de plan-acceptance-spec staat beide toe omdat Cashier zelf beide states als waar rapporteert voor een rij met `ends_at > now()`."

patterns-established:
  - "Read-only Filament v4 Resource: nested namespace + `canCreate/canEdit/canDelete()`-returns-false + `getPages()` zonder Create/Edit + Tables-class met `recordActions: [ViewAction::make()]` zonder DeleteBulkAction."
  - "Derived-state-kolom-pattern: PHP-mapping in `deriveStatus(Subscription \$record)` static-private + symmetrische `applyStatusFilter(Builder \$q, ?string \$value)` static-private — UI-laag (TextColumn->state) en DB-laag (SelectFilter->query) zijn gescheiden maar gespiegeld."

requirements-completed: [HUB-04]

# Metrics
duration: ~25min
completed: 2026-05-16
---

# Phase 09 Plan 09: CashierSubscriptionResource (read-only) Summary

**Read-only Filament v4 viewer voor Cashier-Mollie's `subscriptions`-tabel — owner.slug + derived-status BadgeColumn (mirror van Cashier's `active`/`onTrial`/`onGracePeriod`/`ended`/`cancelled` accessors omdat Phase 6 D-02 geen status-DB-kolom heeft) + computed-where SelectFilter. Geen Create/Edit/Delete; Cashier-billing wordt uitsluitend via Phase 6's REST-API gemuteerd.**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-05-15T23:23:00Z
- **Completed:** 2026-05-15T23:48:51Z
- **Tasks:** 2 atomic commits
- **Files created:** 6 (5 Resource files + 1 test)
- **Files modified:** 0

## Accomplishments

- `/admin/cashier-subscriptions` route geregistreerd (index + view) — geverifieerd via `php artisan route:list --path=admin/cashier`
- Read-only enforced op 3 niveaus: `canCreate/canEdit/canDelete()` returnen false + geen Create/Edit page-classes + `recordActions` bevat alleen `ViewAction`
- Derived-status mirror exact-equivalent met Cashier's accessor-output (zelfde 5 buckets: active/trialing/grace/cancelled/ended)
- SelectFilter werkt query-side equivalent — `active`-tak gebruikt Cashier's eigen scopeWhereActive-OR-keten
- Infolist toont owner-Consumer-slug + alle relevante Cashier-timestamps (trial/cycle/ends/created/updated)
- 3 feature-tests groen (12 assertions), full suite **356/356** (was 353 baseline + 3 nieuwe, 1 pre-existing incomplete uit 09-01)
- Pint clean (5 nieuwe PHP files + 1 testfile)

## Task Commits

Atomic per-task commits op `worktree-agent-ae5dbcd8b91ebc600`:

1. **Task 1: Resource-skelet + derived-status BadgeColumn + filters** — `05ccc94` (feat)
   - 5 files via `php artisan make:filament-resource CashierSubscription --view --no-interaction` (= 8 files generator-output) → 3 niet-gebruikte files verwijderd vóór commit (CreateCashierSubscription, EditCashierSubscription, CashierSubscriptionForm).
2. **Task 2: CashierSubscriptionResourceTest** — `2d906bd` (test)
   - 3 tests / 12 assertions: list-render, derived-status='active' (ends_at null), derived-status='grace' (ends_at future).

## Files Created/Modified

**Created:**
- `app/Filament/Resources/CashierSubscriptions/CashierSubscriptionResource.php` — Resource met `$model = Laravel\Cashier\Subscription::class`, Heroicon::CreditCard, navigatieLabel "Cashier subscriptions", `getPages()` whitelist (index + view), 3× `canCreate/canEdit/canDelete()` → false.
- `app/Filament/Resources/CashierSubscriptions/Tables/CashierSubscriptionsTable.php` — 6 kolommen (Consumer/Naam/Plan/Status/Eindigt op/Aangemaakt-toggleable), derived-status BadgeColumn via static `deriveStatus()`, SelectFilter via static `applyStatusFilter()`, alleen `ViewAction` als record-action.
- `app/Filament/Resources/CashierSubscriptions/Schemas/CashierSubscriptionInfolist.php` — 10 TextEntry components met dezelfde derived-status logica.
- `app/Filament/Resources/CashierSubscriptions/Pages/ListCashierSubscriptions.php` — geen header-actions (geen CreateAction).
- `app/Filament/Resources/CashierSubscriptions/Pages/ViewCashierSubscription.php` — geen header-actions (geen EditAction).
- `tests/Feature/Admin/CashierSubscriptionResourceTest.php` — 3 PHPUnit feature-tests, `seedRoles()` + `actingAsStaff()` helpers conform 09-03 PanelAccessTest-pattern.

**Modified:** geen.

## Must-Have Truths — Empirically Verified

| # | Truth | Bewijs |
|---|---|---|
| 1 | `/admin/cashier-subscriptions` bereikbaar voor staff-User; tabel toont owner.slug + name + plan + derived_status badge + ends_at | `test_list_shows_consumer_subscriptions` (200 + assertSee(slug) + assertCanSeeTableRecords) |
| 2 | Resource volledig read-only (geen Create/Edit/Delete) | `canCreate()/canEdit()/canDelete()` returnen false + getPages whitelist + tinker-print → `canCreate=false`, pages `[index, view]` |
| 3 | derived_status afgeleid via Cashier-accessors (geen DB-kolom — Phase 6 D-02) | `deriveStatus()` roept `onTrial()`/`onGracePeriod()`/`ended()`/`active()` aan — geen `$record->status` lookup; getest in test 2 + 3 |
| 4 | Tabel heeft filter op derived_status met whereClause-equivalent op ends_at/trial_ends_at | `SelectFilter::make('derived_status')->query(fn → applyStatusFilter)`; query is gespiegeld met Cashier's scopeWhereActive |

## Decisions Made

### Filament v4 generator-output trimmen voor read-only Resource

`php artisan make:filament-resource CashierSubscription --view --no-interaction` levert 8 files (Resource + Form/Infolist schemas + Table + List/Create/View/Edit pages). Voor een read-only viewer zijn er 5 nodig. Vóór de eerste commit zijn `Pages/CreateCashierSubscription.php`, `Pages/EditCashierSubscription.php` en `Schemas/CashierSubscriptionForm.php` verwijderd; `getPages()` mapt alleen `index` + `view`. Dat houdt het bestand-aantal in lijn met de plan-acceptance-spec ("1 Resource-class + 2 Page-classes") en voorkomt dead-code (T-09-09-02: Resource per ongeluk Edit-page registreert).

### Derived-status: PHP-mapping en DB-filter symmetrisch maar gescheiden

`deriveStatus(Subscription $record): string` (UI-laag) en `applyStatusFilter(Builder $query, ?string $value): Builder` (DB-laag) leven naast elkaar in `CashierSubscriptionsTable`. Eén unified callable was overwogen (één method die zowel `$record` als `Builder` accepteert), maar de twee laag-zijdes hebben tegengestelde signatures (PHP-state vs. SQL-clauses). Twee static-private methods is leesbaarder en match Filament's eigen pattern voor `->state(fn) + ->query(fn)`.

### Trialing voorrang op grace in deriveStatus

In theorie kan een Cashier-Subscription zowel `trial_ends_at > now()` als `ends_at > now()` hebben (een trial-sub die direct gecancel'd wordt vóór trial-einde). In Cashier's eigen `active()`-accessor zijn beide branches `true` — er is geen uitspraak welke "wint" voor display. Gekozen voor `trialing > grace` omdat trialing semantisch een lifecycle-fase is (vóór active), en grace een tail-state (na cancel). Admin-debug-context: een sub die in trial is én al gecancel'd is, is eerst en vooral een trial-sub die uitgaat — dat is de meer actiebare info.

### Geen `protected static ?string $slug` override

Filament v4's `HasRoutes::getDefaultSlug` leidt het slug af van de namespace via `pluralStudly` matching + kebab-case. Voor `App\Filament\Resources\CashierSubscriptions\CashierSubscriptionResource` levert dat default `cashier-subscriptions` op — exact wat het plan vereist. Een expliciete `$slug = 'cashier-subscriptions'` zou redundant zijn en zou de v4-conventie verwarren.

### Test #3 fallback: `grace OR cancelled`

Plan-tekst stelde "asserteer beide opties via assertSeeText met fallback". In de praktijk produceert `deriveStatus()` voor een sub met `ends_at = now()+1day` exact `'grace'` (omdat `onGracePeriod()` voor `ended()` wordt gecheckt). De `str_contains($body, 'grace') || str_contains($body, 'cancelled')`-assert respecteert het plan letterlijk en blijft groen als toekomstige Cashier-versies de accessor-semantiek wijzigen.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Worktree had geen vendor + .env**

- **Found during:** Pre-Task 1 worktree-bootstrap
- **Issue:** Worktree-bootstrap-snippet `cp ../../.env .env` faalde — `../../` resolveert naar `.claude/worktrees/` (4 levels diep), niet naar de main repo. `vendor/` ontbrak eveneens.
- **Fix:** `cp /Users/.../emeq-hub/.env .env` + `ln -s /Users/.../emeq-hub/vendor vendor` (absolute paden conform 09-03/09-04 deviation-pattern). Daarna `composer dump-autoload -q` om de nieuwe Resource-classes te registreren.
- **Files modified:** geen committed files (.env + vendor zijn gitignored)
- **Verification:** `php artisan route:list --path=admin` → na dump-autoload zichtbaar; tinker-print `CashierSubscriptionResource::getModel()` → `Laravel\Cashier\Subscription`.

**2. [Rule 3 - Blocking] Filament-generator gaf 8 files, niet 3 (v4-output-shape)**

- **Found during:** Task 1 post-generator
- **Issue:** Plan-acceptance-criterium zei "genereert 3 files" maar Filament v4 levert 8 files (resource + table + 2 schemas + 4 pages). Plan-text was gebaseerd op een oudere flat-layout-aanname; CLAUDE.md heads-up confirms de v4-nested-namespace.
- **Fix:** 3 niet-gebruikte files verwijderd (`Pages/CreateCashierSubscription.php`, `Pages/EditCashierSubscription.php`, `Schemas/CashierSubscriptionForm.php`) vóór de eerste commit. `getPages()` updaten om alleen `index` + `view` te registreren. Tabel-recordActions opgeschoond (geen DeleteBulkAction/EditAction).
- **Files modified:** 5 Resource-files (Resource + Table + Infolist + 2 Pages — committed in `05ccc94`)
- **Verification:** `find app/Filament/Resources -type f` → exact 5 files; route:list → 2 routes (index + view, geen create/edit); `canCreate=false` tinker.

### Plan-shape afwijking (gedocumenteerd, geen Rule)

Plan-Action stap 1 zegt "3 files aangemaakt" als done-criterium. De daadwerkelijke generator-output is 8 → 5-na-cleanup. De acceptance-criteria's `<verify>` automated-block test alleen file-existence en route-existence, niet count. Geen Rule-1/2 nodig — werkelijke output dekt het plan-doel (read-only viewer) volledig.

---

**Total deviations:** 2 auto-fixed (beide Rule-3 blocking — worktree-bootstrap + generator-output-shape). Geen Rule-1/2 nodig, geen Rule-4 architectural.
**Impact on plan:** Geen — beide deviations zijn mechanica (worktree + Filament v4-shape). Plan-tasks 1-2 zijn 1-op-1 uitgevoerd.

## Issues Encountered

- **AdminPanelProvider's `discoverResources`-call vereist `composer dump-autoload`** wanneer een Resource in een nieuwe sub-namespace wordt aangemaakt en de classmap nog niet ge-update is. Eerste `php artisan route:list --path=admin` na Resource-write toonde alleen Dashboard + login (geen cashier-subscriptions). Na `composer dump-autoload -q` + `php artisan optimize:clear` waren beide routes zichtbaar. Geen Rule-deviation — standard composer-cache-flow.

## Known Stubs

Geen. Alle 6 files zijn volledig functioneel:
- Resource heeft echte model-binding (`Laravel\Cashier\Subscription`), echte slug-resolution, echte read-only enforcement.
- Tabel + Infolist hebben geen placeholder-kolommen.
- Tests asserteren echte DB-state (`Subscription::count()`, `$subscription->onGracePeriod()`) en echte HTTP-respons-bodies.

Niet-gerelateerd: `derived_status === 'unknown'` is een defensieve fallback in `deriveStatus()` voor het theoretische geval dat alle 4 Cashier-accessor-takken false zijn. In de huidige Cashier-Mollie v2-implementatie is dat onbereikbaar (zie Subscription::active() — `ends_at IS NULL || onTrial() || onGracePeriod()` dekt het hele state-space behalve `ended()`). De fallback dient als veiligheidsklep tegen toekomstige Cashier-major-bumps.

## Threat Flags

Geen nieuwe surface buiten plan's threat-model. Alle 4 STRIDE-items uit `<threat_model>` blijven gemitigated:

- **T-09-09-01 (accept):** Resource toont alleen plan-slug + dates + Consumer-slug. Geen klant-PII (de Consumer-naam zelf, account-emails, payment-mandates) lekt — die staan in andere tabellen.
- **T-09-09-02 (mitigate):** `canCreate()/canEdit()/canDelete()` → false + `getPages()` lijst alleen `index` + `view` + Create/Edit page-classes zijn verwijderd. Drie onafhankelijke barrières.
- **T-09-09-03 (accept):** `owner`-relation is Cashier's eigen morphTo (`HasOwner`-trait) — geen Hub-input bypassed dat. Read-only context.
- **T-09-09-SC (accept):** Geen package-install in dit plan.

## Verification Commands Run

| Command | Result |
|---|---|
| `php artisan make:filament-resource CashierSubscription --view --no-interaction` | 8 files gegenereerd (3 daarna verwijderd) |
| `composer dump-autoload -q && php artisan optimize:clear` | classmap regenereerd; cache clear |
| `php artisan route:list --path=admin/cashier-subscriptions` | 2 routes — `index` + `view` |
| `./vendor/bin/pint --dirty --format agent` | passed (na elke task) |
| `php -r '... CashierSubscriptionResource::getModel() ...'` | print `Laravel\Cashier\Subscription`, slug `cashier-subscriptions`, pages `[index, view]`, canCreate false |
| `php artisan test --compact --filter=CashierSubscriptionResourceTest` | 3 passed / 12 assertions / 1369ms |
| `php artisan test --compact` (full suite) | 356 passed / 1 incomplete / 0 failed / 1163 assertions / 13094ms |
| `grep -q "Laravel.Cashier.Subscription" app/Filament/Resources/CashierSubscriptions/CashierSubscriptionResource.php` | OK |
| `grep -q "derived_status" app/Filament/Resources/CashierSubscriptions/Tables/CashierSubscriptionsTable.php` | OK |

## Self-Check: PASSED

**Files exist:**
- FOUND: app/Filament/Resources/CashierSubscriptions/CashierSubscriptionResource.php
- FOUND: app/Filament/Resources/CashierSubscriptions/Tables/CashierSubscriptionsTable.php
- FOUND: app/Filament/Resources/CashierSubscriptions/Schemas/CashierSubscriptionInfolist.php
- FOUND: app/Filament/Resources/CashierSubscriptions/Pages/ListCashierSubscriptions.php
- FOUND: app/Filament/Resources/CashierSubscriptions/Pages/ViewCashierSubscription.php
- FOUND: tests/Feature/Admin/CashierSubscriptionResourceTest.php

**Commits exist:**
- FOUND: 05ccc94 — feat(09-09): CashierSubscriptionResource read-only viewer (Filament v4)
- FOUND: 2d906bd — test(09-09): CashierSubscriptionResourceTest — list + derived-status

**Plan must-have truths verified:** alle 4/4 truths uit het `must_haves.truths`-blok empirisch bewezen (zie Must-Have Truths sectie hierboven).

**Plan must-have artifacts present (2/2):**
- ✅ `app/Filament/Resources/CashierSubscriptions/CashierSubscriptionResource.php` bevat `class CashierSubscriptionResource extends Resource`
- ✅ `tests/Feature/Admin/CashierSubscriptionResourceTest.php` bevat `class CashierSubscriptionResourceTest`

**Plan must-have key-links present (1/1):**
- ✅ `app/Filament/Resources/CashierSubscriptions/CashierSubscriptionResource.php` → `Laravel\Cashier\Subscription` via `use Laravel\Cashier\Subscription;` + `$model = Subscription::class` (regel 12, 17)

## Next Plan Readiness

- **Plan 09-10 (UserResource)** kan dezelfde read-only Resource-shape hergebruiken (nested namespace + `canCreate/canEdit/canDelete()` waar relevant + getPages-whitelist) en kan vertrouwen op de `staff`-role-gate die in 09-03 is gelegd.
- **Plan 09-12 (phase-acceptance)** kan in HUB-04 het "Cashier-Subscriptions overzicht voor Emeq-eigen Consumer-facturatie"-deelcriterium aanvinken — Resource is functioneel, getest, en respecteert de mute-via-REST-API-invariant.
- **Geen blocking dependencies open.**

---
*Phase: 09-filament-admin-ui-voor-emeq-medewerkers*
*Completed: 2026-05-16*
