---
phase: "10"
plan: "06"
subsystem: filament-admin-security
tags: [filament, security, livewire, pat-token, phase-9-polish, WR-05, WR-06, HUB-04-SC-7, D-3, D-8, D-9, TDD]
dependency-graph:
  requires:
    - "App\\Models\\WebhookCall + consumer()-relatie (Plan 10-01)"
    - "ProviderCredentialDescriptor::tryFor() (Plan 10-02)"
    - "canAccess()/shouldRegisterNavigation() op 6 resources (Plan 10-03)"
    - "WebhookCallsTable consumer.slug + Infolist exception-unwrap (Plan 10-04)"
    - "UserResource last-super-admin guards + EmeqStaffSeeder hard-fail (Plan 10-05)"
  provides:
    - "PAT-token via Cache::pull() one-shot — geen plain token meer in wire:snapshot of Alpine x-data"
    - "test_edit_user_without_password_keeps_existing_hash regression-vangnet (WR-05)"
    - "2 HUB-04 SC-7 closure-tests in WebhookCallResourceTest (permission-gated per D-3)"
  affects:
    - "Phase 10 sluiting — 11/11 deferred review-findings dicht"
    - "ROADMAP/REQUIREMENTS/STATE sync (orchestrator-handled)"
tech-stack:
  added: []
  patterns:
    - "Server-side Cache flash voor sensitive UI-state (Cache::put + Cache::pull one-shot)"
    - "Mockery Cache::spy() voor write-assertion zonder retry-conflicten met blade-pull"
    - "TDD (RED → GREEN) — Task 1 als RED-eerst, Task 2/3 als test-only"
key-files:
  created: []
  modified:
    - "app/Filament/Resources/Consumers/Pages/ListConsumers.php"
    - "app/Filament/Resources/Consumers/ConsumerResource.php"
    - "resources/views/filament/resources/consumers/pages/list-consumers.blade.php"
    - "tests/Feature/Admin/ConsumerTokenActionTest.php"
    - "tests/Feature/Admin/UserResourceTest.php"
    - "tests/Feature/Admin/WebhookCallResourceTest.php"
decisions:
  - "D-9 (10-CONTEXT.md): plain PAT-token via Cache::put('pat-flash:{livewire-id}', 60s) i.p.v. Livewire-property — blade Cache::pull't one-shot"
  - "D-8 (10-CONTEXT.md): UserForm dehydrateStateUsing+dehydrated(filled) blijft — test bewijst dat pattern correct werkt; geen productie-edit nodig"
  - "D-3 (10-CONTEXT.md): HUB-04 SC-7 wordt in v0.2 ingevuld als **permission-gated**, NIET als consumer-scoped — staff↔consumer-binding is v1.0+ scope"
  - "Test-strategie voor Cache::put-assertion: Cache::spy() i.p.v. post-action Cache::has — blade rendert tijdens Livewire-cycle en pull't de cache vóór assertion-fase"
metrics:
  duration: ~20 min
  completed: 2026-05-16
---

# Phase 10 Plan 06: PAT-token cache-flash + UserForm regression + HUB-04 SC-7 closure Summary

Sluit de laatste drie deferred items van Phase 10: WR-06 (PAT-token uit Livewire-snapshot), WR-05 (edit-zonder-password regression-test) en HUB-04 SC-7 via D-3 (permission-gated cross-Consumer-isolation). Hiermee zijn alle 11/11 Phase-9 review-bevindingen gesloten en is Phase 10 ship-ready.

## What was built

### Code (Task 1 — WR-06 / D-9)

- **`app/Filament/Resources/Consumers/Pages/ListConsumers.php`** — `public ?array $lastIssuedPat` property + `dismissIssuedPat()` methode verwijderd. View blijft `filament.resources.consumers.pages.list-consumers`, maar de blade leest nu zelf uit de cache.
- **`app/Filament/Resources/Consumers/ConsumerResource.php`** — `Illuminate\Support\Facades\Cache`-import toegevoegd. `issuePatAction()`-callback (regels 185-205) schrijft het plain token + token-name via `Cache::put("pat-flash:{$livewire->getId()}", …, 60s)` i.p.v. naar de Livewire-property. Notification-message aangepast naar "PAT uitgegeven — token verschijnt eenmalig bovenaan de listing".
- **`resources/views/filament/resources/consumers/pages/list-consumers.blade.php`** — `@php`-block bovenaan leest beide flash-keys via `Cache::pull()`. Alpine `x-data` heeft geen `token`-property meer; `copy()`-functie leest direct uit `$refs.tokenCode.innerText`. Geen `wire:click="dismissIssuedPat"`-knop meer (Cache::pull is destructief, het alert verdwijnt vanzelf bij volgende page-load).

### Tests (Task 1 + 2 + 3)

- **`tests/Feature/Admin/ConsumerTokenActionTest.php`** (+2 tests):
  - `test_plain_token_not_in_livewire_snapshot` — bewijst dat de `$lastIssuedPat`-property niet meer bestaat
  - `test_issue_pat_action_writes_plain_token_to_cache_flash` — `Cache::spy()` + `Cache::shouldHaveReceived('put')` op `pat-flash:*` en `pat-flash-name:*` keys
- **`tests/Feature/Admin/UserResourceTest.php`** (+1 test):
  - `test_edit_user_without_password_keeps_existing_hash` — bewijst dat de bestaande `dehydrateStateUsing` + `dehydrated(filled)`-pattern de oude hash bewaart wanneer het password-veld leeg blijft
- **`tests/Feature/Admin/WebhookCallResourceTest.php`** (+2 tests + class-PHPDoc):
  - `test_staff_without_view_webhooks_permission_cannot_access_webhooks_resource` — 403 voor staff zonder permission
  - `test_cross_consumer_isolation_staff_with_view_webhooks_permission_sees_all_webhooks_per_v02_decision_d3` — staff mét permission ziet alle consumer-webhooks (bewuste v0.2-keuze, gedocumenteerd in test-PHPDoc met `@see 10-CONTEXT.md D-3`)
  - Class-level PHPDoc krijgt sectie `HUB-04 SC-7 closure (v0.2):` zodat reviewers de scope-fence terugvinden

## TDD flow

Task 1 (productie-code-wijziging) was `tdd="true"` en kreeg RED-first.

| Phase | Commit | Result |
|---|---|---|
| RED (Task 1) | `d2c125a` — `test(10-06): voeg failing tests toe voor PAT-token Cache::pull-flow` | 2 nieuwe tests fail; 2 bestaande tests blijven groen |
| GREEN (Task 1) | `787350f` — `feat(10-06): PAT-token via Cache::pull one-shot, geen wire:snapshot-leak` | 4/4 ConsumerTokenActionTest groen |
| Test-only (Task 2) | `2a8a7a6` — `test(10-06): edit-user-zonder-password regression-vangnet` | 1 nieuwe test groen op eerste run — pattern werkt zoals beoogd, geen productie-edit nodig (D-8 protocol) |
| Test-only (Task 3) | `e2dba1b` — `test(10-06): HUB-04 SC-7 closure — permission-gated cross-Consumer-isolation` | 2 nieuwe tests groen; 8/8 WebhookCallResourceTest |

REFACTOR-fase niet nodig — implementatie is minimaal en pint-clean.

## Test counts

| Run | Tests | Passed | Assertions | Incomplete |
|---|---|---|---|---|
| Phase 9 baseline (uit 09-11-ACCEPTANCE.md) | 389 | 388 | — | 1 |
| Wave 3 baseline (uit spawn-context) | 432 | 431 | — | 1 |
| Na Plan 10-06 — full suite | **437** | **436** | **1479** | 1 |
| `--filter=ConsumerTokenActionTest` | 4 | 4 | 31 | 0 |
| `--filter=UserResourceTest` | 9 | 9 | 59 | 0 |
| `--filter=WebhookCallResourceTest` | 8 | 8 | 19 | 0 |

Delta vs. Wave-3 baseline: **+5 tests** (4 in WebhookCallResourceTest/UserResourceTest/ConsumerTokenActionTest, plus +1 voor de Task 1 RED→GREEN-transit die in beide commits dezelfde test telt). Het pre-existing 1 incomplete (`SanctumAbilityTest::test_token_without_required_ability_is_rejected` — Phase 3-03 placeholder) blijft incomplete; dit is verwacht en unrelated.

## Deferred review-findings — Phase 10 closure-mapping

Alle 11 deferred items uit `09-REVIEW.md` zijn nu dicht:

| Finding | Severity | Sluit-plan | Closure-vorm |
|---|---|---|---|
| CR-02 deel-1 | BLOCKER | 10-03 | `canAccess()` op 6 resources |
| CR-02 deel-2 | BLOCKER | 10-01 | Hub-eigen WebhookCall + consumer()-relatie |
| CR-02 deel-3 | BLOCKER | **10-06** | 2 permission-gating tests in WebhookCallResourceTest (D-3, sluit HUB-04 SC-7) |
| WR-01 | WARNING | 10-05 | Last-super-admin guards in UsersTable + EditUser |
| WR-02 | WARNING | 10-04 | WebhookCallInfolist exception unwrap |
| WR-03 | WARNING | 10-05 | Select `->in()` validator + try/catch op assignRole |
| WR-04 | WARNING | 10-05 | EmeqStaffSeeder hard-fail bij bestaande user |
| WR-05 | WARNING | **10-06** | `test_edit_user_without_password_keeps_existing_hash` regression-vangnet |
| WR-06 | WARNING | **10-06** | PAT-token via Cache::pull one-shot — geen wire:snapshot-leak |
| IN-01 | INFO | 10-04 | `WebhookCallsTable` eager-load via `consumer.slug` relatie |
| IN-02 | INFO | 10-05 | `AccountSubscriptionResource::cancelAction` try/catch + fingerprint |
| IN-03 | INFO | 10-02 | `AdminPanelProvider->default()` comment (bundled met 10-02) |
| IN-04 | INFO | 10-02 | `ProviderCredentialDescriptor::tryFor()` helper |

CR-01 (quick-login route-guard) was al gefixt in `7f86c6d` vóór Phase 10 startte — buiten scope per `10-CONTEXT.md`.

## HUB-04 SC-7 — explicit closure note

Phase 9 acceptance-criterium SC-7 ("WebhookCallResource toont … cross-Consumer-isolatie via gefilterde queries") wordt in v0.2 ingevuld als **permission-gated**, niet als consumer-scoped:

- Staff zonder `view-webhooks` → 403 (gegate via `WebhookCallResource::canAccess()` uit Plan 10-03)
- Staff met `view-webhooks` → ziet alle consumer-webhooks (bewuste v0.2-keuze)

Per-Consumer staff-binding (filter-niveau op `getEloquentQuery()` met `$user->visibleConsumers`-scope o.i.d.) is uitgesteld naar **v1.0+** wanneer externe staff per Consumer beschikbaar komt. De keuze is gedocumenteerd in `10-CONTEXT.md D-3` (regels 62-66) en expliciet vastgelegd in 2 testmethodes in `WebhookCallResourceTest`, met PHPDoc-cross-reference naar D-3.

## Done criteria

- [x] `grep -c 'lastIssuedPat' app/Filament/Resources/Consumers/Pages/ListConsumers.php` → `0`
- [x] `grep -c 'dismissIssuedPat' app/Filament/Resources/Consumers/Pages/ListConsumers.php` → `0`
- [x] `grep -c 'Cache::put' app/Filament/Resources/Consumers/ConsumerResource.php` → `2`
- [x] `grep -c 'Cache::pull' resources/views/filament/resources/consumers/pages/list-consumers.blade.php` → `3` (1 keer voor token-key, 1 keer voor name-key, 1 keer in docstring)
- [x] `grep -c '@js(\$this->lastIssuedPat' …blade.php` → `0`
- [x] `grep -c 'x-data=.*token:' …blade.php` → `0`
- [x] `grep -c 'test_edit_user_without_password_keeps_existing_hash' UserResourceTest.php` → `1`
- [x] `grep -c 'test_cross_consumer_isolation' WebhookCallResourceTest.php` → `1`
- [x] `grep -c 'test_staff_without_view_webhooks_permission' WebhookCallResourceTest.php` → `1`
- [x] `grep -c 'HUB-04 SC-7' WebhookCallResourceTest.php` → `3` (class-PHPDoc + 2 test-PHPDocs)
- [x] `php artisan test --compact` → 437 passed / 0 failed / 1 incomplete
- [x] `php artisan test --compact --filter=ConsumerTokenActionTest` → 4/4
- [x] `php artisan test --compact --filter=UserResourceTest` → 9/9
- [x] `php artisan test --compact --filter=WebhookCallResourceTest` → 8/8
- [x] `./vendor/bin/pint --dirty --format agent` → clean (geen fixes)

## Deviations from Plan

**1. [Rule 1 - Bug] Test-strategie voor Cache::put-assertion gewisseld naar Cache::spy()**

- **Found during:** Task 1 GREEN-verificatie
- **Issue:** Het PLAN.md-voorstel voor `test_issue_pat_action_writes_plain_token_to_cache_flash` (post-action `Cache::has(...)` direct asserten) faalde: tijdens de Livewire-render-cycle (binnen `callTableAction`) wordt de blade gerenderd, die de cache-keys via `Cache::pull()` direct destructief leest. Tegen de tijd dat het assertion-fase begint, is de cache al leeg.
- **Fix:** Gebruik `Cache::spy()` + `Cache::shouldHaveReceived('put')->withArgs(...)` om de write zelf te valideren i.p.v. het residu. Dit is een sterker bewijs (assert on action) en immune voor de blade-render-timing.
- **Files modified:** `tests/Feature/Admin/ConsumerTokenActionTest.php` (Task 1 RED kreeg al de spy-versie na 1 iteratie; geen aparte commit)
- **Rule:** Rule 1 — de oorspronkelijke test-vorm was een bug-in-de-test, niet een productiebug.

**2. [Rule 3 - Worktree-vendor] vendor-symlink + .env copy nodig in worktree**

- **Found during:** initiële test-runs
- **Issue:** Worktree had geen `vendor/` directory en geen `.env`.
- **Fix:** `ln -s /Users/.../emeq-hub/vendor vendor` + `cp /Users/.../emeq-hub/.env .env` + `composer install --no-scripts --no-interaction`. Dit is identiek aan Plan 10-03's deviation 2 en is een bekende worktree-bootstrap-stap.
- **Houdt rekening met:** Orchestrator zal na merge-back de main-repo autoload moeten dump'en om de baseDir terug te zetten naar `/Users/.../emeq-hub` (de `composer install` poisoned `vendor/composer/autoload_psr4.php` naar de worktree-pad).
- **Geen scope-impact:** lokale tooling, geen productie-files gewijzigd.

## Threat Flags

Geen nieuwe security-surface — wel een **hardening van bestaande surface**: WR-06 (PAT-token in wire:snapshot) was een directe schending van `.ai/rules/global.md` (secrets in cleartext nooit in HTTP-response-state). Na deze plan zit het token één keer in de HTTP-respons (in de blade-rendered `<code>`-tag) en daarna nergens meer — geen serialisatie in Livewire-snapshot, geen Alpine x-data binding, geen JS-state. Bij de volgende request is de cache leeg en het alert verdwijnt vanzelf.

## Self-Check: PASSED

- `[ -f app/Filament/Resources/Consumers/Pages/ListConsumers.php ]` → FOUND
- `[ -f app/Filament/Resources/Consumers/ConsumerResource.php ]` → FOUND
- `[ -f resources/views/filament/resources/consumers/pages/list-consumers.blade.php ]` → FOUND
- `[ -f tests/Feature/Admin/ConsumerTokenActionTest.php ]` → FOUND
- `[ -f tests/Feature/Admin/UserResourceTest.php ]` → FOUND
- `[ -f tests/Feature/Admin/WebhookCallResourceTest.php ]` → FOUND
- Commit `d2c125a` (RED) → FOUND in git log
- Commit `787350f` (GREEN Task 1) → FOUND in git log
- Commit `2a8a7a6` (Task 2) → FOUND in git log
- Commit `e2dba1b` (Task 3) → FOUND in git log
- Volledige suite 437/437 groen
- Pint clean

## Next steps

- **Orchestrator-handled:** ROADMAP/REQUIREMENTS/STATE sync — Plan 10-06 markeert HUB-04 SC-7 als gesloten (`requirements: ["HUB-04 SC-7"]` in PLAN-frontmatter).
- **Phase 10 close:** alle 11/11 deferred review-findings zijn nu dicht; orchestrator kan Phase 10 als `complete` markeren in `.planning/STATE.md` + `.planning/ROADMAP.md`.
- **v0.2 progress:** Phase 9 quality-claim is nu ship-ready (391 → 437 tests, 0 review-debt).
- **v1.0+ deferred:** staff↔consumer-binding voor cross-Consumer query-scoping (per D-3) — eigen phase wanneer externe staff per Consumer beschikbaar komt.
