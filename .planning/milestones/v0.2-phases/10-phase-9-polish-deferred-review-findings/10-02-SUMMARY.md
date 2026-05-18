---
phase: "10"
plan: "02"
subsystem: provider-credential-descriptor
tags: [refactor, tests, in-04, d-11]
dependency_graph:
  requires: []
  provides:
    - "ProviderCredentialDescriptor::tryFor(string): ?self"
  affects:
    - "App\\Models\\Connection::fingerprint"
tech_stack:
  added: []
  patterns:
    - "try-as-? helper pattern op static factory (zelfde stijl als Eloquent::firstOrFail vs firstOr)"
key_files:
  created: []
  modified:
    - app/Support/ProviderCredentialDescriptor.php
    - app/Models/Connection.php
    - tests/Feature/Admin/ProviderDescriptorTest.php
decisions:
  - "tryFor() vangt alleen InvalidArgumentException — geen Throwable (per D-11)"
metrics:
  duration: "~5 min"
  completed: 2026-05-16
  tasks: 2
  files_changed: 3
  tests_added: 2
---

# Phase 10 Plan 02: ProviderCredentialDescriptor::tryFor() — Summary

Sluit D-11 / IN-04 uit `09-REVIEW.md`: vervangt het inline `try { ProviderCredentialDescriptor::for() } catch (InvalidArgumentException)`-pattern in `Connection::fingerprint()` door een dedicated static helper `ProviderCredentialDescriptor::tryFor(string): ?self`. Pure refactor met gedragsbehoud — alle Phase-9 admin-tests (58) blijven groen.

## Doel & resultaat

- Expressievere callsite: `$descriptor = ProviderCredentialDescriptor::tryFor($this->provider); if ($descriptor === null) { return null; }` versus de eerdere inline-try/catch + losse `use InvalidArgumentException`.
- Consistent met het bestaande discovery-contract op de descriptor (`::for()`, `::all()`) — `::tryFor()` is de derde leden van die family en matched de Eloquent-conventie (`firstOrFail` vs `find`).
- Geen gedragswijziging: `Connection::fingerprint()` returnt nog steeds een 12-char-hex-string voor bekende providers met een primary-credential gezet, en `null` in alle andere paden (unknown provider, geen primary-field, lege secret).

## Tasks executed

| # | Task | Files | Commit |
|---|------|-------|--------|
| 1 | Add `ProviderCredentialDescriptor::tryFor()` + refactor `Connection::fingerprint()` | `app/Support/ProviderCredentialDescriptor.php`, `app/Models/Connection.php` | `4d2a614` |
| 2 | Extend `ProviderDescriptorTest` met 2 nieuwe tests voor `tryFor()` happy + null path | `tests/Feature/Admin/ProviderDescriptorTest.php` | `d35b60d` |

## Wijzigingen detail

**`app/Support/ProviderCredentialDescriptor.php`** — Toegevoegd na `for()`, vóór `all()`:
```php
public static function tryFor(string $provider): ?self
{
    try {
        return self::for($provider);
    } catch (InvalidArgumentException) {
        return null;
    }
}
```
Per D-11: vangt specifiek `InvalidArgumentException` (niet `Throwable`) zodat échte bugs in `for()` blijven doorgooien.

**`app/Models/Connection.php`** — `fingerprint()` body vereenvoudigd, `use InvalidArgumentException;` verwijderd. Rest van de methode (primary-field + hash-resolver) ongewijzigd.

**`tests/Feature/Admin/ProviderDescriptorTest.php`** — 2 tests toegevoegd:
- `test_try_for_returns_descriptor_for_known_provider` — asserts instance + `key === 'mollie'` + `oauthFlowKey === 'mollie'`.
- `test_try_for_returns_null_for_unknown_provider` — strict `=== null` (geen exception).

## Verification

```bash
php artisan test --compact --filter='ProviderDescriptorTest|ConnectionFingerprintTest'
# 10 passed, 32 assertions

php artisan test --compact tests/Feature/Admin/
# 58 passed, 251 assertions — geen regressie

./vendor/bin/pint --dirty --format agent
# passed
```

## Done criteria

- [x] `grep -c 'public static function tryFor' app/Support/ProviderCredentialDescriptor.php` → `1`
- [x] `grep -c 'InvalidArgumentException' app/Models/Connection.php` → `0` (use-statement verwijderd)
- [x] `grep -c 'ProviderCredentialDescriptor::tryFor' app/Models/Connection.php` → `1`
- [x] `ConnectionFingerprintTest` 4 tests blijven groen (gedrag identiek)
- [x] `ProviderDescriptorTest` van 4 → 6 tests; alle groen
- [x] Pint zonder fixes; full admin-suite (58 tests) groen

## Deviations from Plan

None — plan executed exactly as written. Worktree had geen `vendor/` — symlink naar main-repo `vendor/` + `composer dump-autoload -o` vanuit de worktree zodat `$baseDir` naar worktree-paths resolved (anders zou de symlinked autoload nog steeds main-repo `app/` classes laden en zouden de nieuwe `tryFor()`-tests "undefined method" gooien). Dit is omgevings-setup, geen plan-deviation.

## Self-Check: PASSED

- `app/Support/ProviderCredentialDescriptor.php` — `tryFor()` toegevoegd: FOUND
- `app/Models/Connection.php` — inline try/catch verwijderd, `tryFor()` callsite aanwezig: FOUND
- `tests/Feature/Admin/ProviderDescriptorTest.php` — 6 test-methodes (was 4): FOUND
- Commit `4d2a614` (Task 1): FOUND
- Commit `d35b60d` (Task 2): FOUND
- Full Admin-test-suite 58/58 groen: PASSED

D-11 / IN-04 closed.
