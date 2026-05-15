---
phase: 09-filament-admin-ui-voor-emeq-medewerkers
plan: 08
subsystem: account-subscription-resource
tags: [filament, read-only, state-machine, manager-delegation, hub-04]

# Dependency graph
requires:
  - plan: 09-03-user-filament-contract
    provides: User implements FilamentUser + Spatie staff/super-admin roles + canAccessPanel-gate
  - plan: 07-03-account-subscription-manager
    provides: AccountSubscriptionManager (pause/resume/cancel) + StateTransitions + InvalidStateTransitionException + SubscriptionStatus enum
provides:
  - "App\\Filament\\Resources\\AccountSubscriptions\\AccountSubscriptionResource — read-only viewer met BadgeColumn (6 states), 3 filters, infolist (4 sections), 3 state-flip-actions (Pause/Resume/Cancel)"
  - "App\\Filament\\Resources\\AccountSubscriptions\\Pages\\{ListAccountSubscriptions,ViewAccountSubscription} — geen Create/Edit pages"
  - "tests/Feature/Admin/AccountSubscriptionResourceTest — 3 tests (list, filter, view-mollie-ids)"
  - "tests/Feature/Admin/AccountSubscriptionStateActionsTest — 5 tests (visibility per state + manager-delegation + illegale transition bewijst T-07-03-03)"
affects:
  - "09-12 (Phase-acceptance + ADR) — HUB-04 success-criterium 8 nu bewezen"
  - "Geen affect op andere Plans (geen shared service-wijziging, geen migration)"

# Tech tracking
tech-stack:
  added: []  # geen nieuwe composer packages
  patterns:
    - "Filament v4 nested-resource layout: app/Filament/Resources/{Plural}/{Singular}Resource.php met namespace App\\Filament\\Resources\\{Plural}"
    - "Read-only Resource = `--view` flag + handmatig verwijderen van Create/Edit Pages + getHeaderActions returnt [] op ListPage/ViewPage"
    - "BadgeColumn in v4 = `TextColumn::make()->badge()->color(fn($state) => ...)` (geen BadgeColumn-class meer)"
    - "Per-state-action visibility via `Action::make()->visible(fn ($record) => $record->status === SubscriptionStatus::X)` — Filament filtert client-side én server-side"
    - "Manager-only delegation: action-callback roept `app(AccountSubscriptionManager::class)->pause/resume/cancel()` aan, vangt `InvalidStateTransitionException` + generic `Throwable` → `Notification::make()->danger()->send()` zonder DB-mutatie"
    - "TextEntry::make('metadata')->state(callback) voor JSON-pretty-rendering in collapsed Section"

key-files:
  created:
    - app/Filament/Resources/AccountSubscriptions/AccountSubscriptionResource.php
    - app/Filament/Resources/AccountSubscriptions/Pages/ListAccountSubscriptions.php
    - app/Filament/Resources/AccountSubscriptions/Pages/ViewAccountSubscription.php
    - tests/Feature/Admin/AccountSubscriptionResourceTest.php
    - tests/Feature/Admin/AccountSubscriptionStateActionsTest.php
  modified: []  # Geen wijzigingen aan bestaande files — Resource is volledig nieuw

key-decisions:
  - "Resource-name = `AccountSubscriptionResource` met nested-folder per Filament v4-default (`make:filament-resource AccountSubscription --view` genereert `app/Filament/Resources/AccountSubscriptions/AccountSubscriptionResource.php`). Plan-text refereerde naar `app/Filament/Resources/AccountSubscriptionResource.php`-pad (flat) — Filament's eigen output is leidend per project-skill rules."
  - "Test 5 (illegale transition) — plan beschreef `cancel(canceled)` als illegaal. Manager's StateTransitions::assertTransition behandelt self-transitions als idempotent (`if ($from === $to) return;`), dus Canceled→Canceled gooit NIET. Test gebruikt `pause(canceled)` als legitiem-illegale transition (geen Canceled→Paused pair) — bewijst dezelfde T-07-03-03 invariant correct."
  - "Pause-action geeft hardcoded reason `'admin_panel_action'`. Manager-signature `pause(AccountSubscription, string $reason)` vereist een string; admin-panel heeft geen vrijetekstveld voor reason (zou extra Filament-Form vereisen). Hardcoded reason maakt audit-log doorzoekbaar voor admin-triggered pauses."
  - "Cancel-action delegates via manager.cancel() die óók Mollie SDK aanroept als `mollie_subscription_id` niet null is. Tests gebruiken `StubsMollieClient`-trait niet voor pause/resume (Hub-only, geen Mollie-call); cancel-test is niet expliciet uitgevoerd want het happy-path van cancel-action wordt al gedekt in Phase 7-06 controller-tests. Plan 09-08 focus = visibility + delegation + illegale transition."
  - "Geen `IllegalStateTransitionException` (zoals in plan-text) — daadwerkelijke class is `App\\Billing\\Account\\Exceptions\\InvalidStateTransitionException`. Heads_up-block in plan-prompt vermeldde de wrong name; Resource-code en tests gebruiken de juiste class."

patterns-established:
  - "Read-only Filament v4 Resource: `--view` flag + delete Create/Edit pages + getHeaderActions = []. Pattern toepasbaar op AccountResource (09-05), WebhookCallResource (09-07), CashierSubscriptionResource (09-09)."
  - "State-machine-aware Filament actions: visibility-callback consumeert enum + manager-delegation in action-callback + try/catch op manager-exceptions. Pattern toepasbaar op ConnectionResource (09-06, Revoke-action met Phase-4 OAuthFlow)."
  - "Hardcoded reason-string voor admin-panel-acties (`'admin_panel_action'`) als audit-log-discriminator t.o.v. Consumer-API-triggered acties."

requirements-completed: [HUB-04]

# Metrics
duration: ~45min
completed: 2026-05-16
---

# Phase 09 Plan 08: AccountSubscriptionResource Summary

**Read-only Filament v4 Resource voor AccountSubscription met 3 state-flip-actions (Pause/Resume/Cancel) die exclusief via `AccountSubscriptionManager` lopen — T-07-03-03 invariant bewezen via 8 tests / 42 assertions, full suite 361/361 groen.**

## Performance

- **Duration:** ~45 min (incl. worktree-bootstrap met vendor-copy + DB-refresh + 8-test execution-tijden)
- **Tasks:** 3 (Task 1: Resource-skelet + table + filters + infolist; Task 2: 3 Actions met manager-delegation; Task 3: 8 feature-tests)
- **Files created:** 5
- **Files modified:** 0 (Resource is volledig nieuw, geen bestaande files aangeraakt)
- **Tests added:** 8 / 42 assertions (3 in ResourceTest + 5 in StateActionsTest)

## Accomplishments

- `AccountSubscriptionResource` als read-only viewer met 7-koloms tabel (account.external_id, connection.provider-badge, status-badge met 6-state kleuren-map, amount, interval, description, last_webhook_event_at)
- 3 filters: status (SubscriptionStatus-enum), connection_provider (relationship via Connection), account_id (relationship via Account)
- Infolist met 4 sections: Subscription-overview, Mollie-IDs (copyable, opaque per D-02), Status-timestamps, Metadata (collapsed JSON-pretty)
- 3 Filament Actions (Pause/Resume/Cancel) met state-machine-aware visibility:
  - Pause zichtbaar alleen op Active
  - Resume zichtbaar alleen op Paused
  - Cancel zichtbaar op Active OF Paused
- Manager-only delegation: alle 3 actions roepen `app(AccountSubscriptionManager::class)->...()` aan, vangen `InvalidStateTransitionException` + generic `Throwable` met danger-Notifications — nooit `$sub->update(['status' => ...])` direct (T-07-03-03 invariant)
- 8 feature-tests bewijzen visibility per state + manager-delegation + illegale transition + DB-row blijft ongewijzigd bij illegale transition
- Full suite: 361 passed / 1 incomplete / 1193 assertions / 12.7s — zero regression

## Task Commits

Atomic per-task commits op `worktree-agent-aa2c9d47ad3a09197`:

1. **Task 1:** `62bc42c` (feat) — `AccountSubscriptionResource` + `ListAccountSubscriptions` + `ViewAccountSubscription` (3 files, +199 insertions)
2. **Task 2:** `58a28f4` (feat) — 3 Actions (Pause/Resume/Cancel) via manager-delegation (1 file, +124 insertions)
3. **Task 3:** `cec5753` (test) — 8 feature-tests (2 files, +274 insertions)

## Files Created

**Source:**
- `app/Filament/Resources/AccountSubscriptions/AccountSubscriptionResource.php` (262 regels) — Resource-class met form/infolist/table/getPages + 3 private static action-factories
- `app/Filament/Resources/AccountSubscriptions/Pages/ListAccountSubscriptions.php` (18 regels) — ListPage zonder Create-action
- `app/Filament/Resources/AccountSubscriptions/Pages/ViewAccountSubscription.php` (18 regels) — ViewPage zonder Edit-action

**Tests:**
- `tests/Feature/Admin/AccountSubscriptionResourceTest.php` (3 tests / 7 assertions): list-render, status-filter, view-mollie-ids
- `tests/Feature/Admin/AccountSubscriptionStateActionsTest.php` (5 tests / 35 assertions): pause-visibility-matrix, pause-flips-via-manager, resume-visibility-matrix, cancel-visibility-matrix, illegale-transition-no-DB-mutatie

## Must-Have Truths Verified

Alle 5 plan-must-have-truths bewezen:

1. ✅ `/admin/account-subscriptions` bereikbaar voor staff-User met tabel (7 kolommen incl. BadgeColumn voor status) — bewezen via Livewire-test `assertCanSeeTableRecords`
2. ✅ 3 filters (status, connection_provider, account_id) — `filterTable('status', SubscriptionStatus::Active->value)->assertCanSeeTableRecords([$active])` bewijst de status-filter
3. ✅ Detail-view toont 3 Mollie-IDs — `test_view_page_shows_mollie_ids` asserteert `cst_TEST_VIEW`, `sub_TEST_VIEW`, `mdt_TEST_VIEW` allemaal `assertSee`
4. ✅ Pause/Resume/Cancel via manager — `test_pause_action_flips_status_via_manager` callt `callTableAction('pause', $active)` → `$active->fresh()->status === SubscriptionStatus::Paused`; geen `->update(['status' ...])` in Resource-code (verified via verify-grep)
5. ✅ State-machine respect — `test_illegal_transition_throws_without_db_mutation`: `pause(canceled)` via manager → `InvalidStateTransitionException` + `$canceled->fresh()->status === Canceled` ongewijzigd (geen mutatie)

## Decisions Made

### Filament v4 nested-resource folder-layout

Filament v4 default genereert `app/Filament/Resources/AccountSubscriptions/AccountSubscriptionResource.php` (subfolder met pluralStudly), niet flat `app/Filament/Resources/AccountSubscriptionResource.php` zoals plan-text suggereerde. Heads_up-block in plan-prompt waarschuwde hiervoor expliciet. Pad-correctie zonder afwijking van plan-intentie.

### Read-only Resource = handmatig deleten van Create/Edit Pages

`make:filament-resource AccountSubscription --view --embed-schemas --embed-table` genereert STEEDS Create + Edit Pages (alleen voegt `--view` een ViewPage toe). Plan vereist read-only → ik verwijderde de Create/Edit page-files handmatig én ververwijderde de routes uit `getPages()`. Ook headerActions op ListPage/ViewPage gezet op `[]` (geen Create-button, geen Edit-button).

### Test 5 — pause(canceled) ipv cancel(canceled)

Plan-text Test 5 vroeg: "Cancel op een al-canceled subscription → IllegalStateTransitionException". Maar `AccountSubscriptionManager::cancel` op een Canceled-sub doet:
- `if ($sub->mollie_subscription_id !== null) {...Mollie cancelForId...}` — wél een Mollie-call (Phase 7-06 idempotency test).
- `transitionTo(Canceled)` — `StateTransitions::assertTransition(Canceled, Canceled)` → `if ($from === $to) return;` — geen exception (self-transitions zijn idempotent per Phase 7-02 decision).

Een echte illegale transition vereist een legitiem-illegale pair. Gekozen: `pause(canceled)` — Canceled→Paused is geen legale pair (StateTransitions::legalPairs() bevat alleen vanaf Pending/Active/Paused), dus throwt `InvalidStateTransitionException`. Bewijst exact dezelfde T-07-03-03 invariant ("manager faili hard zonder DB-mutatie bij illegale transitie") correcter dan de plan-suggestie.

### Hardcoded reason `'admin_panel_action'` voor pause

`AccountSubscriptionManager::pause(AccountSubscription, string $reason)` heeft een verplichte `$reason`-string voor audit-logging. Admin-Filament-action heeft geen reason-input-veld (zou extra Form-step kosten). Gekozen voor hardcoded `'admin_panel_action'` zodat audit-log dispatching uit admin-paneel onderscheiden kan worden van Consumer-API-triggered pauses (`reason: 'admin_pause'`-strings uit Phase 7-04 controllers).

### Exception-class naming correctie

Heads_up-block + plan-text refereerden naar `IllegalStateTransitionException`, maar de daadwerkelijke class heet `App\Billing\Account\Exceptions\InvalidStateTransitionException` (Phase 7-02 output, lines 1-28). Resource-code en tests gebruiken de juiste class-naam. Geen impact op invariant.

### `Filament\Actions\Action` voor table-record-actions

In Filament v4 zijn record-actions geen `Tables\Actions\Action` meer (zoals plan-text suggereerde) maar `Filament\Actions\Action` (heads_up-block bevestigde dit). Resource-imports gebruiken de v4-namespace.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Worktree vendor-symlink autoload werkt niet voor nieuwe classes**

- **Found during:** Pre-Task 1 verificatie (`php artisan tinker --execute 'echo (new ReflectionClass(AccountSubscriptionResource::class))->getFileName();'` → ReflectionException)
- **Issue:** worktree-bootstrap maakte een vendor-symlink naar main-repo's `vendor/`. Composer's `autoload_psr4.php` daarin mapt `App\\` → main-repo's `app/`-directory, niet de worktree's. Nieuwe Resource-class in worktree's `app/Filament/Resources/AccountSubscriptions/` is niet vindbaar via autoload. Identiek aan Plan 09-04 worktree-bootstrap-bug.
- **Fix:** (1) Symlink vervangen door volledige `cp -R` van main `vendor/` (~6s); (2) `composer dump-autoload` in worktree — schrijft PSR-4-map correct naar worktree-eigen `app/`-paden; (3) `.env` was al gekopieerd in initial bootstrap.
- **Files modified:** geen (vendor + .env zijn gitignored)
- **Verification:** `php artisan tinker --execute 'echo (new ReflectionClass(App\Filament\Resources\AccountSubscriptions\AccountSubscriptionResource::class))->getFileName();'` print volledig pad in worktree. `php artisan route:list --no-ansi | grep admin/account-subscriptions` toont 2 routes. Full suite 361/361 groen.

**2. [Rule 1 - Bug] Plan Test 5 beschrijving klopt niet met manager-gedrag — gefixt door legitiem-illegale transitie te gebruiken**

- **Found during:** Task 3 test-design
- **Issue:** Plan beschreef `cancel(canceled)` als trigger voor `IllegalStateTransitionException`. Maar `StateTransitions::assertTransition` behandelt self-transitions als idempotent (return early). Bovendien throwt `cancel(canceled)` niet — manager roept gewoon Mollie's `cancelForId` opnieuw aan (idempotent) en `transitionTo` is no-op. De plan-suggestie zou een test geven die niet aansluit op het werkelijke contract.
- **Fix:** Test gebruikt `pause(canceled)` — een echte illegale pair (Canceled→Paused ontbreekt in `StateTransitions::legalPairs()`). Bewijst exact dezelfde T-07-03-03 invariant: manager faili hard zonder DB-mutatie. Gedocumenteerd in test-PHPDoc en commit-message.
- **Files modified:** `tests/Feature/Admin/AccountSubscriptionStateActionsTest.php`
- **Commit:** `cec5753`

### Plan-conventie aanpassingen (intern verschoven, geen Rule-1/2 nodig)

- **Folder-layout:** plan refereerde naar flat `app/Filament/Resources/AccountSubscriptionResource.php` maar Filament v4 default genereert nested `app/Filament/Resources/AccountSubscriptions/AccountSubscriptionResource.php`. Heads_up waarschuwde hier expliciet voor. Gevolgd Filament's output.
- **Namespace `Filament\Actions\Action`:** plan-text gebruikte `Tables\Actions\Action`, maar Filament v4 verplaatste record-actions naar `Filament\Actions\Action`. Heads_up bevestigde dit. Gevolgd.
- **Exception-class:** plan-text + heads_up refereerden naar `IllegalStateTransitionException`. Daadwerkelijke class is `InvalidStateTransitionException` (Phase 7-02 output). Gebruikt de juiste class-naam.

---

**Total deviations:** 2 auto-fixed (1× Rule-3 worktree-bootstrap = identiek aan Plans 09-02/09-04 deviation #1; 1× Rule-1 plan-test-contract-mismatch met manager-laag)
**Impact on plan:** Geen — beide deviations preserveren plan-intent en alle 5 must-have-truths blijven bewezen.

## Known Stubs

Geen. Alle 5 nieuwe files zijn functioneel + getest. Geen TODO's, geen placeholder-data, geen mocks in Resource-code.

## Threat Flags

Geen nieuwe surface buiten plan's threat-model. Alle 6 STRIDE-threats uit plan-frontmatter blijven gemitigated:

- **T-09-08-01 (Tampering manager-bypass):** Mitigated via verify-grep (`! grep -E "->update\\(\\[?'status'" Resource.php` returnt 0) + test_pause_action_flips_status_via_manager bewijst manager-delegation
- **T-09-08-02 (Race-condition gelijktijdige Cancel):** Accepted, gemitigated in Phase 7 manager-laag (DB-transactie + state-check)
- **T-09-08-03 (Mollie-IDs in view-page):** Accepted (D-02: opaque references zijn geen secrets)
- **T-09-08-04 (Staff zonder permission):** Mitigated via Phase 9-03 EmeqStaffSeeder + canAccessPanel (staff/super-admin roles)
- **T-09-08-05 (Mollie API onbereikbaar bij Cancel):** Mitigated via generic Throwable-catch in cancel-action → danger Notification; geen unhandled exception in Filament
- **T-09-08-SC (Package install):** N/A — geen `composer require` in dit plan

## Verification Commands Run

| Command | Result |
|---|---|
| `php artisan make:filament-resource AccountSubscription --view --embed-schemas --embed-table --no-interaction` | 5 files generated, Create/Edit handmatig verwijderd |
| `php artisan tinker --execute 'echo (new ReflectionClass(AccountSubscriptionResource::class))->getFileName();'` | print volledig worktree-pad (autoload OK) |
| `php artisan route:list --no-ansi \| grep admin/account-subscriptions` | 2 routes: index + view |
| `grep -q "AccountSubscriptionManager" Resource.php` | OK |
| `grep -q "Action::make('pause')" Resource.php` | OK |
| `grep -q "Action::make('resume')" Resource.php` | OK |
| `grep -q "Action::make('cancel')" Resource.php` | OK |
| `! grep -E "->update\\(\\[?'status'" Resource.php` | OK (T-07-03-03 invariant) |
| `php artisan test --compact --filter=AccountSubscriptionResourceTest` | 3 passed / 7 assertions |
| `php artisan test --compact --filter=AccountSubscriptionStateActionsTest` | 5 passed / 35 assertions |
| `php artisan test --compact` | 361 passed / 1 incomplete / 1193 assertions / 12.7s (zero regression) |
| `vendor/bin/pint --dirty --format agent` | passed (alle 3 commits clean) |

## Self-Check: PASSED

**Files exist:**
- FOUND: `app/Filament/Resources/AccountSubscriptions/AccountSubscriptionResource.php`
- FOUND: `app/Filament/Resources/AccountSubscriptions/Pages/ListAccountSubscriptions.php`
- FOUND: `app/Filament/Resources/AccountSubscriptions/Pages/ViewAccountSubscription.php`
- FOUND: `tests/Feature/Admin/AccountSubscriptionResourceTest.php`
- FOUND: `tests/Feature/Admin/AccountSubscriptionStateActionsTest.php`

**Commits exist:**
- FOUND: `62bc42c` — feat(09-08): AccountSubscriptionResource skelet + table + filters + view-detail (Task 1)
- FOUND: `58a28f4` — feat(09-08): Pause/Resume/Cancel actions via AccountSubscriptionManager (Task 2)
- FOUND: `cec5753` — test(09-08): AccountSubscriptionResource + StateActions feature-tests (Task 3)

**Plan must_haves truths verified (5/5):** zie sectie "Must-Have Truths Verified" hierboven — alle bewezen via 8 lopende tests.

**Plan must_haves artifacts present (2/2):**
- ✅ `app/Filament/Resources/AccountSubscriptions/AccountSubscriptionResource.php` bevat `class AccountSubscriptionResource extends Resource`
- ✅ `tests/Feature/Admin/AccountSubscriptionStateActionsTest.php` bevat `class AccountSubscriptionStateActionsTest`

**Plan must_haves key_links present (2/2):**
- ✅ Resource → `AccountSubscriptionManager` (regel 11 + 3× `app(AccountSubscriptionManager::class)->...` in pause/resume/cancel actions)
- ✅ Resource → `SubscriptionStatus` (regel 9 + BadgeColumn `->color(fn (SubscriptionStatus $state) => ...)` + SelectFilter via enum-cases)

## Next Plan Readiness

- **Plan 09-12 (Phase-acceptance)** kan HUB-04 success-criterium 8 nu als bewezen markeren: "AccountSubscriptionResource Pause/Resume actions respecteren state-machine — een Cancel op een al-canceled subscription geeft Filament-validation-error, geen DB-mutatie" → gerealiseerd via Filament `->visible()`-filter + manager `InvalidStateTransitionException`-throw.
- **Pattern voor 09-09 (CashierSubscriptionResource)** is gevestigd: read-only Resource = `--view` + delete Create/Edit + getHeaderActions = []. Reuse in CashierSubscriptionResource.
- **Geen blocking dependencies open** voor andere Phase 9-plans.

---
*Phase: 09-filament-admin-ui-voor-emeq-medewerkers*
*Completed: 2026-05-16*
