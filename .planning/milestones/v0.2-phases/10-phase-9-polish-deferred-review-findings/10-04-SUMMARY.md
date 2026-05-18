---
phase: "10"
plan: "04"
subsystem: filament-admin-webhookcall
tags: [filament, webhook-call, infolist, table, phase-9-polish, WR-02, IN-01, TDD]
dependency-graph:
  requires:
    - "App\\Models\\WebhookCall + consumer() belongs-to (Plan 10-01)"
    - "WebhookCallResource::$model rebinding + getEloquentQuery()->with('consumer') (Plan 10-03)"
  provides:
    - "WebhookCallsTable consumer.slug relation-column (geen Consumer::find() meer)"
    - "WebhookCallInfolist consumer.slug + exception unwrap (WR-02 closed)"
    - "3 nieuwe WebhookCallResourceTest assertions — consumer-relatie + exception-render"
  affects:
    - "HUB-04 SC-7 — geen wijziging (locked v0.2-interpretatie via 10-03 permission-gating)"
tech-stack:
  added: []
  patterns:
    - "Filament v4 TextColumn::make('relation.field') eager-load pattern"
    - "TDD (RED commit → GREEN commits) volgens plan tdd=\"true\""
key-files:
  created: []
  modified:
    - "app/Filament/Resources/WebhookCalls/Tables/WebhookCallsTable.php"
    - "app/Filament/Resources/WebhookCalls/Schemas/WebhookCallInfolist.php"
    - "tests/Feature/Admin/WebhookCallResourceTest.php"
decisions:
  - "D-5 (10-CONTEXT.md): WR-02 fix gebruikt Filament default TextEntry-rendering; geen aparte ->markdown() of expliciete state-callback"
  - "WR-02 review-tekst dacht dat exception-kolom plain text was; in werkelijkheid is het een array-cast op Spatie's parent class. Filament's default TextEntry rendert de cast-decoded array zonder dubbel-encoding-bug"
  - "Test-fixture voor exception-assertion gebruikt JSON-encoded array (match Spatie saveException() patroon) i.p.v. plain string — anders breekt de array-cast op read"
metrics:
  duration: ~15 min
  completed: 2026-05-16
---

# Phase 10 Plan 04: WebhookCallsTable + WebhookCallInfolist consumer.slug + exception unwrap Summary

Wave 2 polish: sluit WR-02 (exception dubbel-encoded) + IN-01 deel-2 (per-row Consumer::find()-N+1 op Tables/Infolist). Consumeert 10-01's `App\Models\WebhookCall::consumer()` belongs-to en 10-03's eager-load fundament. File-disjoint van 10-03 — `WebhookCallResource.php` zelf is hier niet meer aangeraakt.

## What was built

### Code

- **`app/Filament/Resources/WebhookCalls/Tables/WebhookCallsTable.php`** (−4 / +2 regels) — Consumer-kolom: `TextColumn::make('consumer_id')->state(fn ... Consumer::find())` vervangen door `TextColumn::make('consumer.slug')->placeholder('—')`. Eager-loaded relatie uit 10-03's `getEloquentQuery()` wordt nu daadwerkelijk benut. Consumer-import behouden — nog gebruikt door SelectFilter-options-callback (één distinct-query op page-load, geen per-row impact).

- **`app/Filament/Resources/WebhookCalls/Schemas/WebhookCallInfolist.php`** (−8 / +3 regels):
  - consumer_id Entry vervangen door `TextEntry::make('consumer.slug')->placeholder('—')` (consistent met Tables).
  - exception Entry: state-callback met `json_encode($record->exception, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)` verwijderd. Filament's default TextEntry-rendering handelt Spatie's array-cast af; de dubbel-encoded string-letteral met escaped quotes en `\n`-letters is weg.
  - Ongebruikte `use App\Models\Consumer;` import verwijderd.
  - `TextEntry::make('payload')` blijft ongewijzigd — Spatie's `payload`-cast is óók array, maar daar is `json_encode($record->payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)` bewust gekozen voor admin-friendly pretty-print weergave van een JSON-payload (i.t.t. exception-array waar Filament's default-render volstaat).

### Tests

- **`tests/Feature/Admin/WebhookCallResourceTest.php`** (+48 → 6 testmethodes totaal):
  - `test_list_shows_consumer_slug_via_relation` — Livewire ListWebhookCalls + Consumer-factory met `slug = 'test-slug-xyz'` + `assertSee('test-slug-xyz')`. Bewijst dat de consumer-relatie-kolom render via de Eloquent-relatie (geen Consumer::find()-fallback).
  - `test_view_page_renders_exception_as_plain_text_not_json_encoded` — insert JSON-encoded array `['code' => 0, 'message' => 'Stack trace line 1', 'trace' => "line 1\nline 2"]`, GET `/admin/webhook-calls/{id}`, `assertSee('Stack trace line 1')` + `assertDontSee('Stack trace line 1\\n…')` (de oude dubbel-encoded vorm).
  - `test_view_page_shows_consumer_slug_via_relation` — analoog aan list-test, maar op view-page.
  - Behouden: 3 originele Plan 09-07 tests (audit-rows-list, direction-filter, payload-JSON-render).
  - Bestaande `actAsStaff()` helper (uit 10-03 permission-grant) hergebruikt — geen wijziging aan setup-pattern.

## TDD flow

Plan markeerde alle 3 tasks als `tdd="true"`. Uitgevoerd in correcte RED → GREEN volgorde — eerst nieuwe assertions toegevoegd (RED), daarna productie-code in Task 1 + 2, en tot slot een test-fixture-refinement voor de exception-assertion (Task 3 GREEN).

| Phase | Commit | Result |
|---|---|---|
| RED | `7cc3407` — test(10-04): voeg 3 nieuwe WebhookCallResourceTest assertions toe | 5/6 passed; exception-test failed (json_encode wrap nog actief) |
| GREEN Task 1 | `d9ecee4` — feat(10-04): WebhookCallsTable consumer-relatie kolom | 5/6 (Tables-kolom werkt; Infolist + exception nog niet) |
| GREEN Task 2 | `ed4b171` — feat(10-04): WebhookCallInfolist consumer.slug + exception unwrap | 5/6 (exception-test rendert nu array-cast wel, maar test-fixture-data was plain string → cast naar null) |
| GREEN Task 3 | `75392ad` — test(10-04): refine exception-render assertion met JSON-encoded array | 6/6 groen |

## Test counts

| Run | Tests | Passed | Assertions |
|---|---|---|---|
| Baseline (na 10-03 close) | 421 | 421 | 1401 |
| Na Task 1 + 2 + 3 (WebhookCallResourceTest) | 6 | 6 | 15 |
| Na Task 1 + 2 + 3 (admin suite) | 73 | 73 | 270 |
| Na Task 1 + 2 + 3 (full suite) | **424** | **424** | **1408** |

Suite-delta van 421 → 424 (+3) komt uit de 3 nieuwe assertions in WebhookCallResourceTest. Geen regressie — alle Phase 9-tests + 10-01 / 10-02 / 10-03-tests blijven groen.

## Pint

`./vendor/bin/pint --dirty --format agent` → clean run, geen fixes nodig op alle drie de wijzigingsstappen.

## Done criteria

- [x] `grep -c 'Consumer::find' app/Filament/Resources/WebhookCalls/Tables/WebhookCallsTable.php` → `0`
- [x] `grep -c "TextColumn::make('consumer.slug')" app/Filament/Resources/WebhookCalls/Tables/WebhookCallsTable.php` → `1`
- [x] `grep -c 'json_encode' app/Filament/Resources/WebhookCalls/Schemas/WebhookCallInfolist.php` → `1` (alleen payload, niet meer exception)
- [x] `grep -c "TextEntry::make('exception')" app/Filament/Resources/WebhookCalls/Schemas/WebhookCallInfolist.php` → `1`
- [x] `grep -c 'Consumer::find' app/Filament/Resources/WebhookCalls/Schemas/WebhookCallInfolist.php` → `0`
- [x] `grep -c 'consumer.slug' app/Filament/Resources/WebhookCalls/Schemas/WebhookCallInfolist.php` → `1`
- [x] `grep -c 'public function test_' tests/Feature/Admin/WebhookCallResourceTest.php` → `6` (was 3)
- [x] `grep -c 'view-webhooks' tests/Feature/Admin/WebhookCallResourceTest.php` → `2` (Permission::firstOrCreate + givePermissionTo)
- [x] `php artisan test --compact --filter=WebhookCallResourceTest` → `6 passed`
- [x] `php artisan test --compact tests/Feature/Admin/` → 73 passed (geen regressie)
- [x] Volledige test-suite 424/424 groen
- [x] Pint clean
- [x] `WebhookCallResource.php` zelf niet aangeraakt — file-disjoint van 10-03

## Deviations from Plan

**1. [Rule 1 - Bug in test-spec] Plan-test-fixture voor exception-veld gebruikte plain string, terwijl Spatie's parent class een array-cast heeft**

- **Found during:** Task 3 GREEN-verificatie — `test_view_page_renders_exception_as_plain_text_not_json_encoded` faalde ook nadat de Infolist `json_encode`-wrap was verwijderd. Render-HTML bevatte "Stack trace" helemaal niet.
- **Root cause:** `Spatie\WebhookClient\Models\WebhookCall::$casts` heeft `'exception' => 'array'`. Eloquent doet bij read een `json_decode` op de DB-text. Een plain string als `"Stack trace line 1\nStack trace line 2"` is geen valide JSON → cast-resultaat is `null`. Filament's TextEntry rendert niets bij `null` (behalve placeholder, maar zelfs die werd niet zichtbaar door columnSpanFull-detail-rendering). 09-REVIEW.md WR-02 vermeldt expliciet "kolom is `text` (geen JSON, geen array)" — dat is technisch waar voor de migratie, maar de cast forceert array-semantiek bij read/write.
- **Fix:** Test-fixture nu match production-realiteit — `saveException()` op de Spatie-parent schrijft altijd JSON-encoded `{code, message, trace}`-array. Insert in test gebruikt `json_encode(['code' => 0, 'message' => '…', 'trace' => "…\n…"])`. `assertDontSee` controleert dat de oude dubbel-encoded string-letteral (`"Stack trace line 1\\n…"` met escaped quotes + escaped `\n`) niet meer in de HTML zit.
- **Files modified:** `tests/Feature/Admin/WebhookCallResourceTest.php` (commit `75392ad`).
- **Rule:** Rule 1 (test-data bug — productie-code zelf is correct, alleen de assertion-input was inconsistent met de cast).

**Géén productie-code-wijziging buiten plan-scope.** De Infolist-edit zelf volgt de plan-spec exact (`TextEntry::make('exception')->label('Exception')->placeholder('—')->columnSpanFull()`). Filament v4's default TextEntry-rendering handelt de array-cast af zonder dubbel-encoding.

## Threat Flags

Geen nieuwe security-surface. Alleen interne admin-UI-rendering-verbetering — geen nieuwe routes, geen auth-flow-wijziging, geen schema-mutatie. WebhookCallResource zelf is permission-gated via 10-03 (`view-webhooks`).

## Self-Check: PASSED

- `[ -f app/Filament/Resources/WebhookCalls/Tables/WebhookCallsTable.php ]` → FOUND (wijziging in commit `d9ecee4`)
- `[ -f app/Filament/Resources/WebhookCalls/Schemas/WebhookCallInfolist.php ]` → FOUND (wijziging in commit `ed4b171`)
- `[ -f tests/Feature/Admin/WebhookCallResourceTest.php ]` → FOUND (2 commits: `7cc3407` RED + `75392ad` test-fixture)
- Commit `7cc3407` (RED) → FOUND in `git log`
- Commit `d9ecee4` (Task 1 GREEN) → FOUND in `git log`
- Commit `ed4b171` (Task 2 GREEN) → FOUND in `git log`
- Commit `75392ad` (Task 3 GREEN) → FOUND in `git log`
- Volledige suite groen (424/424)
- Pint clean

## Unlocks

- **WR-02 closed** — `09-REVIEW.md` finding adressed; exception-rendering is nu user-friendly.
- **IN-01 deel-2 closed** — laatste per-row `Consumer::find()`-callsites in WebhookCall-admin-resources zijn vervangen door eager-loaded relatie. De N+1-fix-cyclus (10-01 model + 10-03 eager-load + 10-04 column-rebind) is af.
- **Wave 2 complete** — geen blocker voor Wave 3 (D-4 last-super-admin guards + WR-03..06 + IN-02..04).
