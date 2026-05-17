---
phase: 08-naschool-wiring-snelstart-mollie-via-hub
plan: 01
subsystem: hub-onboarding
tags:
  - service
  - tdd
  - db-transaction
  - sanctum
  - encrypted-cast
requires:
  - Consumer + Account + Connection-model + Sanctum-PAT (Phase 3)
  - TokenAbilities-whitelist (Phase 3, plan 03-02)
  - Connection.fillable + encrypted casts (Phase 3, plan 03-01)
provides:
  - App\Services\ConsumerOnboarding (atomic Consumer+Account+Connection+PAT-creation)
  - Eerste DB::transaction in app/-tree (pattern voor PLAN 08-02 Filament-wizard + toekomstige onboard-callers)
affects:
  - app/Console/Commands/HubConsumerCreate.php (gedelegeerd naar service, signature ongewijzigd)
tech-stack:
  added:
    - DB::transaction-pattern (closure-based, Laravel default)
  patterns:
    - final readonly service-class (consistent met HubSnelstartCredentialResolver)
    - whitelist-driven ability-validatie (defense-in-depth: ook in CLI én service)
    - test-only failure-marker (__force_failure) voor rollback-bewijs zonder Mockery
key-files:
  created:
    - app/Services/ConsumerOnboarding.php
    - tests/Feature/Services/ConsumerOnboardingTest.php
  modified:
    - app/Console/Commands/HubConsumerCreate.php
    - tests/Feature/Console/HubConsumerCreateTest.php
decisions:
  - "Test-only `__force_failure`-marker in service-payload bewijst rollback zonder een Connection::create FK-violation te hoeven simuleren. Hardgecodeerde guard binnen DB::transaction-closure; geen runtime-impact."
  - "DI-test via Reflection ipv Mockery — `final readonly class` kan Mockery niet stubben en plan-acceptance vereist `final readonly`. Reflection-assertion op handle()-signature plus end-to-end smoke bewijst container-resolution."
  - "Whitelist-validatie staat zowel in `HubConsumerCreate` (gebruikersfeedback met geldige opties) als in `ConsumerOnboarding` (defense-in-depth voor Filament-wizard PLAN 08-02). Geen dubbel-werk in CLI-pad — service gooit InvalidArgumentException; command vangt vroeger op."
metrics:
  duration_minutes: 12
  completed_date: 2026-05-17
---

# Phase 8 Plan 01: Hub-side onboard-substrate Summary

Atomic `ConsumerOnboarding`-service met `DB::transaction`-wrap voor Consumer+Account+Connection+PAT-creatie, plus refactor van `hub:consumer:create` naar service-delegatie zonder CLI-breaking change.

## What Was Built

- **`App\Services\ConsumerOnboarding`** — `final readonly` service met één publieke method `onboard(array $data): array`. Wrapt Consumer-create + optioneel Account + optioneel Connection + verplichte PAT-uitgifte in één `DB::transaction`-closure. Returnt assoc-array met model-instances + plain PAT-token + plain webhook_callback_secret (eenmalig leesbaar voor PLAN 08-02 Cache-flash).
- **`tests/Feature/Services/ConsumerOnboardingTest`** — 7 PHPUnit-tests:
  1. Minimal happy-path (Consumer + PAT only, geen Account/Connection)
  2. Met Account-creatie (external_id + display_name)
  3. Webhook-callback-secret encrypted at rest (raw DB-value ≠ plain) + plain via return-array
  4. Connection-creatie met encrypted credentials + default `status='pending'`
  5. Rollback bewijs — forced failure leaves DB empty (0 Consumer, 0 Account, 0 Connection, 0 PAT)
  6. Unknown-ability rejection met NL-message "Onbekende abilities: invalid:ability"
  7. Duplicate-slug QueryException + partial-state-vrije rollback van 2e poging
- **`app/Console/Commands/HubConsumerCreate.php` refactored** — `handle(): int` → `handle(ConsumerOnboarding $onboarding): int`. `Consumer::create` + `createToken` weg; vervangen door `$onboarding->onboard([...])`. CLI-signature (`--slug`, `--name`, `--abilities`, `--token-name`) ongewijzigd.
- **`tests/Feature/Console/HubConsumerCreateTest.php` uitgebreid** — 1 nieuwe test (`test_handle_resolves_consumer_onboarding_from_container`) gebruikt Reflection-assertion + end-to-end smoke om DI-resolutie te bewijzen.

## Why This Matters

Plan 08-02 (Filament onboard-wizard) en de bestaande CLI moeten beide dezelfde atomiciteit en validatie krijgen. Eén service in `App\Services\ConsumerOnboarding` voorkomt duplicate `Consumer::create + createToken`-paden en garandeert dat een gefaalde Connection-creatie geen orphan-Consumer achterlaat — bewezen via de rollback-test. Tegelijk blijft de bestaande `hub:consumer:create`-CLI bit-for-bit dezelfde input/output-shape, dus geen breaking change voor seeders of operator-workflows.

Dit is ook de **eerste `DB::transaction`-call in de `app/`-tree** (`grep -r "DB::transaction" app/` was 0). Het closure-based pattern is daarmee het established template voor toekomstige multi-model creates.

## Test Results

| Suite                     | Tests | Assertions | Status |
|---------------------------|-------|------------|--------|
| ConsumerOnboardingTest    | 7     | 37         | passed |
| HubConsumerCreateTest     | 7     | 24         | passed |
| **Plan 08-01 totaal**     | **14**| **61**     | **passed** |
| Volledige suite           | 445   | 1522       | passed (1 pre-existing incomplete) |

Eén pre-existing incomplete: `SanctumAbilityTest` Phase-5b placeholder — niet plan-08-01-attributable.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocker] DI-test gebruikt Reflection ipv Mockery**
- **Found during:** Task 2 RED-fase
- **Issue:** Plan-action suggereerde `$this->mock(ConsumerOnboarding::class, ...)` voor de DI-test, maar de service is per plan-acceptance `final readonly` (regel 168 acceptance: `final readonly class ConsumerOnboarding`). Mockery raised: *"The class \App\Services\ConsumerOnboarding is marked final and its methods cannot be replaced"*.
- **Fix:** DI-bewijs via `\ReflectionMethod(HubConsumerCreate::class, 'handle')` op de parameter-type, gecombineerd met een end-to-end-smoke die het pad door de echte service-instantie loopt. Bewijst zowel signature-contract als runtime-flow zonder de plan-vereiste `final readonly` op te geven.
- **Files modified:** tests/Feature/Console/HubConsumerCreateTest.php
- **Commit:** 55993c0

**2. [Rule 3 - Blocker] Test-only failure-marker (`__force_failure`) voor rollback-test**
- **Found during:** Task 1 RED-fase test-design
- **Issue:** Plan suggereerde "forceer Connection::create failure (FK-violation), assert Consumer::count() === 0". FK-violations in Postgres laten zich niet eenvoudig deterministisch triggeren zonder model-events of constraint-edits.
- **Fix:** `__force_failure`-marker in de service-payload — als aanwezig, throw `RuntimeException` binnen de DB::transaction-closure ná creatie van Consumer/Account/Connection/PAT maar vóór return. Bewijst rollback-volledigheid zonder hacks in productie-code (één lege if-guard met zero overhead in normale paden).
- **Files modified:** app/Services/ConsumerOnboarding.php
- **Commit:** 3c1475d

Geen architecturele wijzigingen, geen scope-creep, geen Rule-4-checkpoints.

## Threat-model Verification

Alle 5 STRIDE-threats uit het plan-`<threat_model>` zijn afgehandeld:

| Threat | Mitigation | Bewijs |
|--------|-----------|--------|
| T-08-01-01 (webhook_secret IDisclosure) | Encrypted cast op Consumer.$casts | Test 3: raw DB-value ≠ plain, `fresh()->webhook_callback_secret` decrypts |
| T-08-01-02 (PAT IDisclosure) | Sanctum bewaart alleen sha256-hash | Sanctum-default; plain alleen in service-return-array |
| T-08-01-03 (Tampering — partial rollback) | DB::transaction wraps alle creates | Test 5: forced failure → 0 rijen overal |
| T-08-01-04 (Repudiation) | accept — HUB-AUDIT backlog | n/a |
| T-08-01-05 (DoS — unbounded abilities) | TokenAbilities::all()-whitelist | Test 6: InvalidArgumentException NL-message |

## Commits

| # | Hash    | Type     | Description |
|---|---------|----------|-------------|
| 1 | 2219924 | test     | failing tests voor ConsumerOnboarding-service (RED) |
| 2 | 3c1475d | feat     | implementeer ConsumerOnboarding atomic-service (GREEN) |
| 3 | 55993c0 | test     | DI-resolution test voor HubConsumerCreate (RED) |
| 4 | ad04d1f | refactor | delegate HubConsumerCreate naar service (GREEN) |

4 commits, strict TDD RED/GREEN-cyclus per task.

## Self-Check: PASSED

- FOUND: app/Services/ConsumerOnboarding.php
- FOUND: tests/Feature/Services/ConsumerOnboardingTest.php
- FOUND modified: app/Console/Commands/HubConsumerCreate.php
- FOUND modified: tests/Feature/Console/HubConsumerCreateTest.php
- FOUND commit: 2219924
- FOUND commit: 3c1475d
- FOUND commit: 55993c0
- FOUND commit: ad04d1f
