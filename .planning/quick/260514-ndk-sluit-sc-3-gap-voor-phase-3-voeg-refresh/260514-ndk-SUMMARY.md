---
phase: quick
plan: 260514-ndk
subsystem: tests
tags: [phase-3, sc-3, encryption-at-rest, connection-model, mollie]
requires: []
provides:
  - "tests/Feature/ConnectionEncryptionTest::test_mollie_refresh_token_is_encrypted_at_rest"
  - "Phase 3 SC-3 closed (refresh_token-coverage)"
affects:
  - ".planning/phases/03-hub-skeleton/03-VERIFICATION.md (SC-3 gap → closed)"
tech_stack:
  added: []
  patterns:
    - "DB::table('connections')->value('<encrypted_column>') voor at-rest-bewijs op productiestack"
key_files:
  created: []
  modified:
    - tests/Feature/ConnectionEncryptionTest.php
decisions:
  - "Productiecode niet aangeraakt — Connection::casts['refresh_token']='encrypted' stond al goed; alleen het bewijs in de test-suite ontbrak"
metrics:
  duration: "~3 min"
  completed: 2026-05-14
  files_changed: 1
  tests_added: 1
  commits: 1
---

# Quick Task 260514-ndk: Sluit SC-3-gap voor Phase 3 (refresh_token encryption-at-rest) Summary

**One-liner:** `test_mollie_refresh_token_is_encrypted_at_rest` toegevoegd aan `ConnectionEncryptionTest` — sluit de Phase 3 SC-3-gap door encryption-at-rest voor `connections.refresh_token` expliciet te bewijzen op productiestack, zonder wijziging aan productiecode.

## Wat is er gebeurd

Eén nieuwe public test-method toegevoegd in `tests/Feature/ConnectionEncryptionTest.php`, tussen `test_mollie_access_token_is_encrypted_at_rest` (regel 43-55) en `test_to_array_hides_all_credential_fields` (nu regel 71). Pattern is 1-op-1 gekopieerd van de `access_token`-variant; alleen secret-literal en kolom-/property-naam geswapt.

```php
public function test_mollie_refresh_token_is_encrypted_at_rest(): void
{
    $connection = Connection::factory()
        ->forMollie()
        ->create(['refresh_token' => 'refresh_secret-789']);

    $rawAtRest = DB::table('connections')
        ->where('id', $connection->id)
        ->value('refresh_token');

    $this->assertNotSame('refresh_secret-789', $rawAtRest);
    $this->assertSame('refresh_secret-789', $connection->fresh()->refresh_token);
}
```

Geen wijzigingen in `app/Models/Connection.php` of `database/factories/ConnectionFactory.php`. De `encrypted` cast op `refresh_token` (Connection.php regel 55) was al aanwezig — productie-gedrag was correct, alleen het bewijs ontbrak.

## Tasks Completed

| Task | Naam | Commit | Files |
|------|------|--------|-------|
| 1 | Voeg `test_mollie_refresh_token_is_encrypted_at_rest` toe | `d4c31d3` | `tests/Feature/ConnectionEncryptionTest.php` |

## Verification

**ConnectionEncryptionTest-suite:**
```
{"tool":"phpunit","result":"passed","tests":8,"passed":8,"assertions":21,"duration_ms":407}
```
8/8 groen (was 7).

**Volledige suite (sanity):**
```
{"tool":"phpunit","result":"passed","tests":28,"passed":28,"assertions":63,"duration_ms":633,"incomplete":1}
```
28 passed / 1 incomplete / 0 failed (was 27 passed / 1 incomplete).

**Acceptance-grep:**
- `grep -n 'test_mollie_refresh_token_is_encrypted_at_rest' tests/Feature/ConnectionEncryptionTest.php` → 1 hit (regel 57)
- `grep -c 'function test_' tests/Feature/ConnectionEncryptionTest.php` → 8 (≥ verwacht)
- `grep -c 'refresh_token' tests/Feature/ConnectionEncryptionTest.php` → 5 (≥ 4 vereist)
- `git diff --stat` toonde enkel `tests/Feature/ConnectionEncryptionTest.php` (14 insertions)

**Pint:**
```
{"tool":"pint","result":"passed"}
```
Clean — geen formatting-fix nodig.

## Deviations from Plan

Geen. Plan exact uitgevoerd zoals geschreven; één file, één commit, één test, geen productiecode aangeraakt.

## Impact op Phase 3 VERIFICATION.md

SC-3 (`encrypted` cast op gevoelige credentials werkt at-rest) staat in `.planning/phases/03-hub-skeleton/03-VERIFICATION.md` met een gap voor `refresh_token`-coverage (= BL-01 uit `03-REVIEW.md`). Met deze commit is die gap gesloten:

- `client_key` (Snelstart) ✅ — `test_snelstart_client_key_is_encrypted_at_rest`
- `subscription_key` (Snelstart) ✅ — `test_snelstart_subscription_key_is_encrypted_at_rest`
- `access_token` (Mollie) ✅ — `test_mollie_access_token_is_encrypted_at_rest`
- `refresh_token` (Mollie) ✅ — `test_mollie_refresh_token_is_encrypted_at_rest` **← nieuw**

HUB-01 SC-3 mag nu zonder asterisk afgevinkt staan voor Phase 5b/Phase 4-werk.

## Commits

- `d4c31d3` — test(phase-3): sluit SC-3-gap met refresh_token encryption-at-rest test

## Self-Check: PASSED

- FOUND: tests/Feature/ConnectionEncryptionTest.php (modified, 14 insertions)
- FOUND: commit d4c31d3 in `git log`
- FOUND: method `test_mollie_refresh_token_is_encrypted_at_rest` op regel 57
