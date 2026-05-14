---
phase: 03-hub-skeleton
plan: 04
subsystem: hub-security-tests
tags:
  - laravel
  - phpunit
  - security
  - encryption
  - multi-tenant
requirements:
  - HUB-01
dependency-graph:
  requires:
    - "03-01: Connection-model met encrypted casts + #[Hidden] + fingerprint()"
    - "03-01: Consumer/Account-models met HasMany / unique(consumer_id, external_id)"
  provides:
    - "tests/Feature/ConnectionEncryptionTest.php (7 tests — at-rest + Hidden + fingerprint geverifieerd via DB-bypass)"
    - "tests/Feature/ConsumerAccountScopingTest.php (4 tests — cross-Consumer query-isolation + DB-unique-constraint)"
  affects:
    - "Phase 5b (pass-through-API mag bouwen op `Account::where('consumer_id', $consumer->id)` als veilige scope-mechanisme)"
    - "Phase 5b (audit-logging mag `$connection->fingerprint()` gebruiken zonder lek-risico)"
    - "Phase 3 SC-3 (geen raw credentials in toArray) bewezen via machine-checks"
    - "Phase 3 SC-4 query-laag bewezen (route-laag-bewijs volgt in Phase 5b)"
tech-stack:
  added: []
  patterns:
    - "Eloquent encrypted-cast verificatie via `DB::table()->value()` om raw ciphertext te lezen (Laravel-standard DB-bypass-pattern)"
    - "`$model->fresh()` om model uit DB te herladen i.p.v. in-memory cache te raken"
    - "PHPUnit `expectException(QueryException::class)` als bewijs voor DB-level unique-constraints"
    - "Query-laag-isolation bewijs zonder routes — puur Eloquent-pattern voor latere Sanctum-middleware-fundering"
key-files:
  created:
    - "tests/Feature/ConnectionEncryptionTest.php"
    - "tests/Feature/ConsumerAccountScopingTest.php"
  modified: []
decisions:
  - "Tests onder `Tests\\Feature`-root (geen `Api`-sub-namespace) — encryption + scoping zijn model-laag-bewijs, geen HTTP-tests"
  - "Geen MockEncrypter / config-tweaks — gebruik echte `APP_KEY` uit phpunit.xml zodat het cast-gedrag identiek is aan productie"
metrics:
  duration_minutes: 6
  tasks_completed: 2
  tasks_total: 2
  files_created: 2
  files_modified: 0
  commits: 2
  completed_at: "2026-05-14"
---

# Phase 3 Plan 04: Encryption + Scoping Tests Summary

Twee security-vangnetten voor HUB-01 als machine-checks: encryption-at-rest van credential-kolommen bewezen via raw DB-reads, en cross-Consumer Account-isolation bewezen via standaard Eloquent-query-patterns. Beide tests bouwen alleen op artifacts uit plan 03-01 — geen code-changes aan models, migrations of factories nodig.

## What Was Built

### Task 1 — ConnectionEncryptionTest (commit `7f3d1d2`)

`tests/Feature/ConnectionEncryptionTest.php` met 7 tests:

| # | Test | Bewijst |
|---|------|---------|
| 1 | `test_snelstart_client_key_is_encrypted_at_rest` | `DB::table('connections')->value('client_key')` retourneert ciphertext (niet `'CK-secret-123'`); `$conn->fresh()->client_key` retourneert plain string |
| 2 | `test_snelstart_subscription_key_is_encrypted_at_rest` | Idem voor `subscription_key` op Snelstart-shape |
| 3 | `test_mollie_access_token_is_encrypted_at_rest` | Idem voor `access_token` op Mollie-shape |
| 4 | `test_to_array_hides_all_credential_fields` | `#[Hidden]` werkt voor alle 4 velden, op zowel Snelstart- als Mollie-Connection |
| 5 | `test_fingerprint_returns_truncated_sha256_for_snelstart` | `fingerprint()` retourneert exact `substr(hash('sha256', $client_key), 0, 12)` op Snelstart-shape; lengte == 12 |
| 6 | `test_fingerprint_returns_truncated_sha256_for_mollie` | Idem voor `access_token` op Mollie-shape |
| 7 | `test_fingerprint_returns_null_for_unknown_provider` | Fallback-pad in `match($this->provider)` retourneert `null` voor provider buiten `'snelstart'`/`'mollie'` |

DB-bypass via `Illuminate\Support\Facades\DB` omzeilt de Eloquent encrypted-cast en bewijst dat de rauwe kolom-waarde niet de plain credential is.

### Task 2 — ConsumerAccountScopingTest (commit `1e3fab6`)

`tests/Feature/ConsumerAccountScopingTest.php` met 4 tests:

| # | Test | Bewijst |
|---|------|---------|
| 1 | `test_two_consumers_can_have_same_external_id` | Twee Consumers (`naschool` + `planny`) mogen elk een Account `external_id=school1` hebben — uniqueness is per-Consumer-tuple, niet globaal |
| 2 | `test_consumer_a_cannot_query_consumer_b_account_via_scope` | `Account::query()->where('consumer_id', $a)->where('external_id', $b_external)` retourneert `null` (assertNull) — query-laag-leak structureel onmogelijk |
| 3 | `test_consumer_relation_query_only_returns_own_accounts` | `$consumerA->accounts()->get()` retourneert exact 1 rij (alleen A's `a-school`, niet B's `b-school`) |
| 4 | `test_duplicate_external_id_within_same_consumer_fails` | DB-level `unique(consumer_id, external_id)` index uit migration 03-01 gooit `QueryException` bij duplicate insert binnen dezelfde Consumer |

Daarmee is Phase 5b's pass-through-scope-pattern (`Account::where('consumer_id', $request->user()->id)`) op DB-niveau veilig bewezen.

## Verification Results

| Acceptance criterion | Status |
|---|---|
| `tests/Feature/ConnectionEncryptionTest.php` bestaat | OK |
| `grep -c "public function test_" ConnectionEncryptionTest.php` == 7 | OK (7) |
| `grep -c "DB::table('connections')" ConnectionEncryptionTest.php` >= 3 | OK (3) |
| `grep -c "assertNotSame" ConnectionEncryptionTest.php` >= 3 | OK (3) |
| `php artisan test --compact --filter=ConnectionEncryptionTest` exit 0 | OK (7 passed, 19 assertions, 405ms) |
| Geen Pest-syntax in ConnectionEncryptionTest.php | OK (0 `it(` matches) |
| `tests/Feature/ConsumerAccountScopingTest.php` bestaat | OK |
| `grep -c "public function test_" ConsumerAccountScopingTest.php` == 4 | OK (4) |
| `grep -c "QueryException" ConsumerAccountScopingTest.php` == 1 | DEVIATION → 2 (Pint hoisted import; semantisch nog steeds "1 test gebruikt QueryException") — zie Deviations |
| `grep -c "assertNull" ConsumerAccountScopingTest.php` >= 1 | OK (1) |
| `php artisan test --compact --filter=ConsumerAccountScopingTest` exit 0 | OK (4 passed, 7 assertions, 350ms) |
| Pint clean op beide files | OK |
| Volledige testsuite groen (regressie-check) | OK (16/16, 37 assertions, 484ms) |

## Threat-mitigaties bewezen (uit plan-frontmatter threat_model)

- **T-03-14** (DB-dump exposes plain credentials) — Tests 1/2/3 bewijzen dat de rauwe DB-kolom-waarde niet gelijk is aan de plain credential. Encryption-at-rest is op cast-niveau actief én verifieerbaar.
- **T-03-15** (`toArray()` lekt credentials in JSON-responses) — Test 4 ondervangt alle vier credential-keys voor zowel Snelstart- als Mollie-shape. Phase 5b's `/v1/connections`-listings mogen `toArray()` veilig gebruiken.
- **T-03-16** (Log-leakage van credentials) — Tests 5/6/7 bewijzen dat `fingerprint()` per provider de juiste 12-char sha256-prefix retourneert (en `null` voor onbekende providers). Phase 5b audit-logging heeft een veilig publiek identifier-mechanisme.
- **T-03-17** (Cross-Consumer-Account-spoofing) — Tests 2/3 bewijzen dat de standaard Eloquent-query-pattern (`Account::where('consumer_id', $a->id)` of `$a->accounts()`) een Account van Consumer B niet teruggeeft, ook als de `external_id` identiek is.
- **T-03-18** (Regressie-bescherming op encryption-cast) — Encryptie-tests in deze plan-laag gaan stuk als iemand in een latere plan-uitvoering een `'encrypted'`-cast verwijdert of `#[Hidden]` aanpast. CI-gate is hiermee geïnstalleerd.

## Welke HUB-01-claims zijn nu wel/niet bewezen

**Bewezen door dit plan (machine-geverifieerd):**

- SC-3 (volledig) — Connection met test-credentials toont nooit raw waardes in `->toArray()` zonder expliciete decrypt-call: gedekt door test 4. Bovendien is encryption-at-rest geverifieerd via DB-bypass (sterker dan SC-3 strict vereist) door tests 1-3.
- SC-4 (query-laag, niet route-laag) — Cross-Consumer query-poging retourneert geen rij via standaard Eloquent-patterns: tests 2 + 3.

**Nog NIET bewezen (volgt in latere plans):**

- SC-1 (`php artisan migrate:fresh --seed` levert demo-data) — wacht op plan 03-05 (DatabaseSeeder + acceptance-run).
- SC-2 (Consumer kan Sanctum-PAT verkrijgen en `/v1/ping` aanroepen) — wacht op plan 03-03 (PingController + PingTest).
- SC-4 (route-laag, 403/404-response) — wacht op Phase 5b's `/v1/snelstart/{path}`-route + `X-Account-Id`-header-flow waar middleware-niveau-rejection getest kan worden. Dit plan levert het fundament: de query-laag is veilig, dus de route-laag mag erop bouwen.
- SC-5 (Connection-shape validatie voor Snelstart-only en Mollie-only velden) — wacht op Phase 5b's `POST /v1/connections` FormRequest. In dit plan zijn beide shapes wel impliciet bewezen via de factory-state-methodes `forSnelstart()`/`forMollie()`.

## Deviations from Plan

Eén minor formatting-deviatie:

1. **[Rule 1 - cosmetic] Pint hoistte `Illuminate\Database\QueryException` naar `use`-statement.** Plan-action toonde `$this->expectException(\Illuminate\Database\QueryException::class)` inline, wat na `vendor/bin/pint --dirty` werd genormaliseerd naar een top-level `use`-import + `$this->expectException(QueryException::class)`. Effect: `grep -c "QueryException" tests/Feature/ConsumerAccountScopingTest.php` retourneert 2 in plaats van 1, maar de plan-intentie ("exact één test gebruikt QueryException") blijft identiek. Geen functioneel verschil; conform repo-stijl en `fully_qualified_strict_types`/`ordered_imports`-rules van Pint.

Geen verdere afwijkingen. Plan exact volgens specificatie uitgevoerd; geen Rule 2/3/4-acties getriggerd. Beide tests verifiëren bestaande 03-01-artifacts zonder model/migration/factory-aanpassingen.

## Auth Gates

Geen. Plan vereist geen externe authenticatie.

## Deferred Issues

Geen nieuwe out-of-scope items. Pre-existing `deferred-items.md`-entry (Pint formatting-drift op vendor-published `webhook_calls`-migrations) blijft staan en is niet door dit plan veroorzaakt.

## Known Stubs

Geen. Beide test-files zijn volledig functioneel; geen `markTestIncomplete`, geen TODO-fixtures.

## Continuation

Vervolg-werk in deze fase:

- **Plan 03-03** — `routes/api.php` `/v1/ping` + `PingController` + PingTest + SanctumAbilityTest (verifieert SC-2; orthogonaal aan dit plan, kan in elke volgorde).
- **Plan 03-05** — `hub:consumer:create` artisan-command + `DatabaseSeeder` demo-data + Phase 3 acceptance-run (verifieert SC-1).

## Self-Check: PASSED

**Files exist:**
- FOUND: tests/Feature/ConnectionEncryptionTest.php
- FOUND: tests/Feature/ConsumerAccountScopingTest.php

**Commits exist:**
- FOUND: 7f3d1d2 (Task 1: ConnectionEncryptionTest)
- FOUND: 1e3fab6 (Task 2: ConsumerAccountScopingTest)
