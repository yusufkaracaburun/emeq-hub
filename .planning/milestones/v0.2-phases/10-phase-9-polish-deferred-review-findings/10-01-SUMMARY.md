---
phase: "10"
plan: "01"
subsystem: webhook-routing
tags: [webhook, eloquent, consumer-relation, phase-9-polish, CR-02, IN-01, TDD]
dependency-graph:
  requires:
    - "app/Models/Consumer.php (Consumer-model met factory)"
    - "database/migrations/2026_05_19_000001_add_audit_columns_to_webhook_calls_table.php (consumer_id-kolom)"
    - "vendor/spatie/laravel-webhook-client v3.x (parent WebhookCall-model)"
  provides:
    - "App\\Models\\WebhookCall (Hub-eigen subclass met consumer() belongs-to)"
    - "Consumer::webhookCalls() (symmetrische HasMany)"
    - "config/webhook-client.php webhook_model → App\\Models\\WebhookCall::class"
  affects:
    - "Plan 10-04 (WebhookCallsTable + WebhookCallInfolist eager-load) — unlocked"
tech-stack:
  added: []
  patterns:
    - "Spatie webhook_model config-binding override (Hub-subclass)"
    - "TDD (RED commit → GREEN commit) volgens plan tdd=\"true\""
key-files:
  created:
    - "app/Models/WebhookCall.php"
    - "tests/Feature/Models/WebhookCallConsumerRelationTest.php"
  modified:
    - "app/Models/Consumer.php"
    - "config/webhook-client.php"
decisions:
  - "D-2 (10-CONTEXT.md): Hub-eigen WebhookCall subclass i.p.v. Spatie-class direct gebruiken — vereist voor Eloquent-relatie + eager-load"
  - "consumer() is nullable BelongsTo (geen ->required()) — bestaande Spatie-rijen met consumer_id NULL blijven valide"
  - "final class per Hub-conventie (parallel met App\\Support\\ProviderCredentialDescriptor)"
  - "Geen $table override — parent Spatie-class wijst al naar webhook_calls"
metrics:
  duration: ~15 min
  completed: 2026-05-16
---

# Phase 10 Plan 01: Hub-eigen WebhookCall-model + consumer()-relatie Summary

Wave 1 fundament voor de WebhookCallResource-polish: nieuwe `App\Models\WebhookCall` subclass met `consumer()` belongs-to + config-binding, zodat Plan 10-04 eager-load (`->with('consumer')`) kan doen i.p.v. per-row `Consumer::find()`. Sluit deel-2 van CR-02 en lost IN-01 (N+1) op zodra 10-04 land.

## What was built

### Code

- **`app/Models/WebhookCall.php`** (nieuw, 27 regels) — `final class WebhookCall extends \Spatie\WebhookClient\Models\WebhookCall` met één publieke `consumer(): BelongsTo` methode (`belongsTo(Consumer::class)`). `declare(strict_types=1)` + Hub-conventie-conform.
- **`app/Models/Consumer.php`** (+5 regels) — toegevoegde `webhookCalls(): HasMany` methode parallel met bestaande `accounts()` / `connections()`.
- **`config/webhook-client.php`** (1-line use-statement swap) — `Spatie\WebhookClient\Models\WebhookCall` import vervangen door `App\Models\WebhookCall`. `'webhook_model' => WebhookCall::class` (regel 49) resolveert nu naar Hub-subclass.

### Tests

- **`tests/Feature/Models/WebhookCallConsumerRelationTest.php`** (nieuw, 97 regels, 5 testmethodes):
  1. `test_webhook_call_extends_spatie_class` — bewijst Spatie-subclassing
  2. `test_consumer_relation_returns_null_when_consumer_id_is_null` — nullable-relation
  3. `test_consumer_relation_returns_consumer_when_consumer_id_is_set` — hydrate-correct
  4. `test_consumer_has_many_webhook_calls_relation` — symmetrische HasMany op Consumer (incl. NULL-exclusion bewijs)
  5. `test_webhook_model_config_resolves_to_hub_subclass` — config-binding-assertion

## TDD flow

Plan markeerde beide tasks als `tdd="true"`. Uitgevoerd in correcte RED→GREEN volgorde (i.t.t. de plan-listing van Task 1 vóór Task 2 — de tests bewijzen de productie-API, dus die horen eerst):

| Phase | Commit | Result |
|---|---|---|
| RED | `14eb661` — test(10-01): voeg failing test toe | 5 tests / 0 passed / 5 failures + errors |
| GREEN | `5bfe22c` — feat(10-01): Hub-eigen WebhookCall + consumer() belongs-to | 5/5 groen, full suite 407/407 groen |

REFACTOR-phase niet nodig — implementatie is al minimaal (3 LOC productie-code in de nieuwe class).

## Test counts

| Run | Tests | Passed | Assertions |
|---|---|---|---|
| Baseline (vóór dit plan) | 402 | 402 | 1376 |
| Na Task 1 + 2 (full suite) | **407** | **407** | **1385** |
| `--filter=WebhookCallConsumerRelationTest` | 5 | 5 | 9 |

Geen Phase-9-test gebroken — Spatie's `WebhookCall::storeWebhook()`, `headersToStore()`, `saveException()`, `prunable()` etc. werken ongewijzigd via parent-class. De bestaande `WebhookCallResourceTest` + `WebhookCallAuditColumnsTest` blijven groen (gebruiken DB-level inserts of de Spatie-parent direct, beide compat met de Hub-subclass).

## Pint

`./vendor/bin/pint --dirty --format agent` → clean run, geen fixes nodig.

## Done criteria

- [x] `app/Models/WebhookCall.php` bestaat met `class WebhookCall extends \Spatie\WebhookClient\Models\WebhookCall` + `public function consumer(): BelongsTo`
- [x] `app/Models/Consumer.php` heeft `public function webhookCalls(): HasMany`
- [x] `config/webhook-client.php` regel 49 luidt `'webhook_model' => WebhookCall::class` (geïmporteerd als `App\Models\WebhookCall`)
- [x] `grep -c 'class WebhookCall extends' app/Models/WebhookCall.php` returnt 1
- [x] Volledige Phase-9 suite (407 tests, was 402 + 5 nieuwe) groen
- [x] `php artisan test --compact --filter=WebhookCallConsumerRelationTest` → 5/5 groen
- [x] HUB-04 SC-7 wordt straks gesloten via Plan 10-03 (D-3) — eager-load-fundament staat klaar via dit plan

## Deviations from Plan

**None — plan executed exactly as written.** Twee opmerkingen:

1. **TDD-commit-volgorde:** plan listte Task 1 (productie) vóór Task 2 (test); uitgevoerd RED-first (test → productie) zoals `tdd="true"` voorschrijft. Geen scope-afwijking.
2. **Hook docs-drift-trigger:** PostToolUse-hook flagde `app/Models/WebhookCall.php` + `app/Models/Consumer.php` als domeinmodel-mutaties die docs-sync-aandacht verdienen. Doorgespeeld naar Wave-4 polish-plan (waar de Hub-eigen WebhookCall + relatie het meest natuurlijk landt in `.docs/`/`CLAUDE.md`-pointers, naast de andere Wave-2/3-resultaten). Geen separate docs-update in dit wave-1-plan.

## Threat Flags

Geen nieuwe security-surface. Hub-subclass erft Spatie's `webhook_model` interface zonder gedragswijziging; geen nieuwe routes, geen nieuwe auth-paths, geen schema-mutatie.

## Self-Check: PASSED

- `[ -f app/Models/WebhookCall.php ]` → FOUND
- `[ -f tests/Feature/Models/WebhookCallConsumerRelationTest.php ]` → FOUND
- Commit `14eb661` (RED) → FOUND in `git log`
- Commit `5bfe22c` (GREEN) → FOUND in `git log`
- Volledige suite groen (407/407)
- Pint clean

## Unlocks

- **Plan 10-04 (Wave 3):** WebhookCallsTable kan nu `TextColumn::make('consumer.slug')` doen + Resource `->with('consumer')` eager-load (lost IN-01 N+1 op).
- **Plan 10-03 (Wave 2):** WebhookCallResourceTest cross-Consumer-isolation test (sluit HUB-04 SC-7) kan op de Hub-subclass bouwen.
