---
phase: 09-filament-admin-ui-voor-emeq-medewerkers
plan: 04
subsystem: provider-credential-descriptor
tags: [descriptor, config-driven, fingerprint, refactor, d-04]

# Dependency graph
requires:
  - plan: 09-02-filament-spatie-install
    provides: `web`-guard + Filament + Spatie permission stack (niet direct gebruikt — descriptor-laag is provider-laag, niet UI-laag, maar Filament's ConnectionResource consumeert deze in plan 09-06)
provides:
  - "config/hub-providers.php — 2 entries (mollie, snelstart) met encrypted_fields/primary_label/oauth_flow_key"
  - "App\\Support\\ProviderCredentialDescriptor — final readonly value-object + static for()/all() discovery"
  - "Connection::fingerprint() — descriptor-aware refactor (gedragsbehoud Mollie+Snelstart)"
  - "tests/Feature/Admin/ProviderDescriptorTest — 4 tests / 13 assertions die D-04 invariant bewijzen"
affects:
  - "09-06 (ConnectionResource) — consumeert ::for() voor per-provider conditional form-sections + revoke-action visibility"
  - "Toekomstige providers (Moneybird, Exact, Ibanity) — toevoegen via config-rij zonder Filament-code-wijziging"

# Tech tracking
tech-stack:
  added: []  # geen nieuwe composer packages
  patterns:
    - "Config-driven discovery via `final class` + static `::for($key)` + `::all()` (analog TokenAbilities::all() + PlanResolver::find())"
    - "Defensive fallback bij refactor: try/catch op InvalidArgumentException om bestaande null-contract van fingerprint() te behouden zonder testfile-wijziging"
    - "Readonly value-object met constructor property promotion (PHP 8.4 conventie)"

key-files:
  created:
    - config/hub-providers.php
    - app/Support/ProviderCredentialDescriptor.php
    - tests/Feature/Admin/ProviderDescriptorTest.php
  modified:
    - app/Models/Connection.php

key-decisions:
  - "Conform PlanResolver-conventie (declare(strict_types=1) + final class + config()-lookup met guard-clause). Niet de TokenAbilities-conventie (geen declare) — Support/value-objects volgen de Billing-laag, niet de Sanctum-laag."
  - "config/hub-providers.php HEEFT `declare(strict_types=1)` — bestaande codebase-conventie in config/billing-plans.php is mét; plan-text suggereerde zonder maar dat botst met chirurgisch-wijzigen-regel."
  - "Fallback-strategie voor onbekende provider: try/catch in Connection::fingerprint() vangt InvalidArgumentException → return null. Behoudt exact gedrag van bestaande `test_fingerprint_returns_null_for_unknown_provider` zonder testfile-wijziging."
  - "encryptedFields[0] is de primary credential die fingerprint() hasht: voor mollie = access_token, voor snelstart = client_key — exact dezelfde fields als de oorspronkelijke match-arm."

patterns-established:
  - "Provider-agnostische descriptor-laag: nieuwe provider toevoegen = config-row, geen code-edit (D-04 invariant)"
  - "Refactor met gedragsbehoud-bewijs: bestaande feature-test (ConnectionEncryptionTest 8/8) is contract-test; refactor mag testfile NIET wijzigen"

requirements-completed: [HUB-04]

# Metrics
duration: ~30min
completed: 2026-05-15
---

# Phase 09 Plan 04: ProviderCredentialDescriptor (D-04) Summary

**Provider-agnostische credential-laag geland: `App\Support\ProviderCredentialDescriptor` + `config/hub-providers.php` + `Connection::fingerprint()`-refactor zonder gedragsverandering. 4 nieuwe tests bewijzen config-driven discovery; bestaande ConnectionEncryptionTest blijft groen zonder testfile-wijziging.**

## Performance

- **Duration:** ~30 min (incl. worktree-bootstrap met composer dump-autoload)
- **Tasks:** 3 (Task 1: config + value-object, Task 2: Connection refactor, Task 3: ProviderDescriptorTest)
- **Files created:** 3
- **Files modified:** 1
- **Tests added:** 4 / 13 assertions

## Accomplishments

- `config/hub-providers.php` met 2 entries (mollie + snelstart) — slug-keyed associative array (config/billing-plans.php-conventie)
- `App\Support\ProviderCredentialDescriptor` als `final` readonly value-object: 4 properties + 2 static factory methods (`for()` + `all()`)
- `Connection::fingerprint()` refactored — match-arm vervangen door descriptor-lookup, identiek output voor Mollie+Snelstart, null bij onbekende provider via gevangen `InvalidArgumentException`
- 4 nieuwe tests in `tests/Feature/Admin/ProviderDescriptorTest.php` die alle 5 plan-must-have-truths bewijzen
- Full suite 347/347 groen (1 pre-existing incomplete uit 09-01 baseline) — zero regression

## Task Commits

Atomic per-task commits op `worktree-agent-ae7766c45cbcd82c6`:

1. **Task 1:** `09d8607` (feat) — `config/hub-providers.php` + `app/Support/ProviderCredentialDescriptor.php` (2 files, +109 insertions)
2. **Task 2:** `a5c2171` (refactor) — `app/Models/Connection.php` (1 file, +16 / -6) — match-arm vervangen door descriptor-lookup met try/catch fallback
3. **Task 3:** `2f2c564` (test) — `tests/Feature/Admin/ProviderDescriptorTest.php` (1 file, +60 insertions) — 4 tests / 13 assertions

## Files Created/Modified

**Created:**
- `config/hub-providers.php` — provider-descriptor declarations (D-04, 33 regels incl. PHPDoc-comment)
- `app/Support/ProviderCredentialDescriptor.php` — final readonly value-object met for()+all() discovery (76 regels)
- `tests/Feature/Admin/ProviderDescriptorTest.php` — 4 tests die D-04 invariant bewijzen (60 regels)

**Modified:**
- `app/Models/Connection.php` — fingerprint()-method refactored, +2 use-statements (`ProviderCredentialDescriptor`, `InvalidArgumentException`)

## Must-Have Truths Verified

Alle 5 plan-must-have-truths bewezen:

1. ✅ `ProviderCredentialDescriptor::for('mollie')` → `encryptedFields=['access_token','refresh_token']`, `primaryFingerprintLabel='OAuth token'`, `oauthFlowKey='mollie'` — `test_mollie_descriptor_resolves_from_config`
2. ✅ `ProviderCredentialDescriptor::for('snelstart')` → `encryptedFields=['client_key','subscription_key']`, `primaryFingerprintLabel='Client key'`, `oauthFlowKey=null` — `test_snelstart_descriptor_has_null_oauth_flow_key`
3. ✅ `ProviderCredentialDescriptor::all()` returnt ALLE descriptors via config-discovery; theoretische 'moneybird'-rij verschijnt automatisch — `test_adding_theoretical_provider_appears_in_all` (config-runtime-override pattern)
4. ✅ `Connection::fingerprint()` retourneert identieke SHA256[0..12]-output voor Mollie + Snelstart — `ConnectionEncryptionTest::test_fingerprint_returns_truncated_sha256_for_mollie/snelstart` blijft groen zonder testfile-wijziging
5. ✅ `Connection::fingerprint()` op onbekende provider retourneert `null` (gevangen `InvalidArgumentException`) — `ConnectionEncryptionTest::test_fingerprint_returns_null_for_unknown_provider` blijft groen

## Decisions Made

### `declare(strict_types=1)` voor zowel config als value-object

Plan-text suggereerde "geen `declare` in config (volg billing-plans-conventie)" maar de eigenlijke `config/billing-plans.php` HEEFT wel `declare(strict_types=1)`. Conform engineering.md "chirurgisch wijzigen" (match bestaande style) is `declare(strict_types=1)` in beide nieuwe files toegevoegd. Het waarschijnlijke verschil: planner verwarde `config/billing-plans.php` met `config/billing.php` (die ik niet expliciet gecheckt heb). Gekozen: volg de billing-plans-conventie omdat dat de meest recente config-file in de codebase is.

### Fallback-strategie: try/catch ipv `tryFor()`-helper

Plan liet open of `Connection::fingerprint()` een `tryFor()`-variant of een try/catch zou gebruiken. Gekozen voor inline try/catch op `InvalidArgumentException` omdat:

1. Geen extra publieke API-oppervlakte (`tryFor()` zou alleen voor deze ene caller bestaan)
2. De caller heeft expliciete intentie ("ik wil null bij onbekend") die de catch documenteert
3. Toekomstige callers (Filament ConnectionResource) zullen waarschijnlijk `for()` direct gebruiken met provider-validatie elders

### `encryptedFields[0]` als primary credential

`fingerprint()` hasht `encryptedFields[0]`. Voor mollie = `access_token` (eerste in lijst), voor snelstart = `client_key`. Dit is exact wat de oude match-arm deed, dus gedragsbehoud is correct. Ordering in config is daardoor semantisch significant — gedocumenteerd in `config/hub-providers.php` PHPDoc-comment.

### Comment-style met decision-tag

`config/hub-providers.php` heeft `/* D-04: ... */`-block top-of-file conform `config/billing-plans.php`-conventie (decision-tag-comment-style — zie 09-PATTERNS.md regel 916-929).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Worktree had geen werkende vendor-symlink voor PSR-4-autoload**
- **Found during:** Pre-Task 1 (tinker-verificatie van Task 1)
- **Issue:** Pre-existing `.env` + `vendor`-symlink wezen naar `../../vendor` (2 niveaus omhoog) maar worktree leeft 4 niveaus diep (`.claude/worktrees/agent-XXX/`). Symlink wees naar non-existent path. Bovendien: main-vendor's `composer/autoload_psr4.php` mapt `App\\` → een ANDER worktree's app/, niet onze. PSR-4 autoload kon `App\Support\ProviderCredentialDescriptor` niet vinden.
- **Fix:** (1) vendor-symlink vervangen door volledige kopie van main `vendor/` (~325MB, ~6s); (2) `composer dump-autoload` in worktree — schrijft PSR-4-map correct naar deze worktree's `app/`-directory; (3) `.env` gekopieerd van main repo (`/Users/yusufkaracaburun/Sites/localhost/emeq-hub/.env`).
- **Files modified:** geen (vendor + .env zijn gitignored, geen impact op commit)
- **Verification:** `php artisan tinker --execute 'echo (App\Support\ProviderCredentialDescriptor::for("mollie"))->oauthFlowKey;'` print `mollie`. Full suite 347/347 groen.

### Plan-conventie discrepantie (intern verschoven, geen blocker)

Plan-text in Task 1 zei `config/hub-providers.php` moest **zonder** `declare(strict_types=1)` worden geschreven ("volg billing-plans-conventie"). Maar `config/billing-plans.php` HEEFT `declare(strict_types=1)`. Gekozen voor wat de codebase doet (mét declare), niet wat de plan-text suggereerde — conform engineering.md "match bestaande style". Geen Rule-1/2 nodig; expliciet als decision gelogd hierboven.

---

**Total deviations:** 1 auto-fixed (Rule-3 blocking, worktree-bootstrap-mechaniek, identiek aan Plan 09-02 deviation #1 — pattern is gevestigd)
**Impact on plan:** Geen — beide deviations zijn worktree-bootstrap (vendor-fix) en codebase-conformiteit (declare-style). Plan-tasks 1-3 1-op-1 uitgevoerd.

## Known Stubs

None — alle 3 nieuwe files zijn functioneel en getest. Geen placeholder-values, geen TODO's, geen mocks. Plan beschrijft `oauth_flow_key='mollie'` als verwijzing naar OAuthFlowRegistry — de daadwerkelijke OAuthFlow-binding bestaat al sinds Phase 4 (zie 09-PATTERNS.md regels 126-138).

## Threat Flags

Geen nieuwe surface buiten plan's threat-model. Alle 5 STRIDE-threats uit plan-frontmatter blijven gemitigated:

- **T-09-04-01 (Tampering config → wrong primary_field):** `ProviderDescriptorTest::test_mollie_descriptor_resolves_from_config` + `test_snelstart_descriptor_has_null_oauth_flow_key` asserteren exact `encrypted_fields` per provider. Wijziging triggert test-failure.
- **T-09-04-02 (Info disclosure via exception):** try/catch in `Connection::fingerprint()` vangt `InvalidArgumentException` → return null. `ConnectionEncryptionTest::test_fingerprint_returns_null_for_unknown_provider` blijft groen.
- **T-09-04-03 (Mutable descriptor):** `final class` + alle properties `readonly` — reflection-bypass uitgesloten via gewone codepaden.
- **T-09-04-04 (Label injectie):** Labels in config zijn statische strings, geen runtime-injection.
- **T-09-04-SC (Package install):** N/A — geen `composer require` in dit plan.

## Verification Commands Run

| Command | Result |
|---|---|
| `php artisan make:class Support/ProviderCredentialDescriptor --no-interaction` | Class scaffold gegenereerd |
| `php artisan make:test --phpunit Admin/ProviderDescriptorTest --no-interaction` | Test scaffold gegenereerd |
| `php -r '... ProviderCredentialDescriptor::for("mollie")->oauthFlowKey ...'` | print `mollie` |
| `php artisan test --compact --filter=ConnectionEncryptionTest` | 8 passed / 21 assertions / 771ms — gedragsbehoud bewezen |
| `php artisan test --compact --filter=ProviderDescriptorTest` | 4 passed / 13 assertions / 437ms |
| `php artisan test --compact` | 347 passed / 1 incomplete / 1135 assertions / 12606ms — zero regression |
| `vendor/bin/pint --dirty --format agent` | passed (alle 3 commits clean) |
| `grep -q "ProviderCredentialDescriptor::for" app/Models/Connection.php` | OK descriptor-usage |
| `! grep -E "match \(\\\$this->provider\)" app/Models/Connection.php` | OK match-arm gone |

## Self-Check: PASSED

**Files exist:**
- FOUND: config/hub-providers.php
- FOUND: app/Support/ProviderCredentialDescriptor.php
- FOUND: app/Models/Connection.php (modified)
- FOUND: tests/Feature/Admin/ProviderDescriptorTest.php

**Commits exist:**
- FOUND: 09d8607 — feat(09-04): ProviderCredentialDescriptor + config/hub-providers (D-04)
- FOUND: a5c2171 — refactor(09-04): Connection::fingerprint() descriptor-aware (gedragsbehoud)
- FOUND: 2f2c564 — test(09-04): ProviderDescriptorTest — D-04 config-driven discovery

**Plan must_haves truths verified (5/5):** zie sectie "Must-Have Truths Verified" hierboven — alle bewezen via lopende tests.

**Plan must_haves artifacts present (3/3):**
- ✅ `config/hub-providers.php` bevat `'mollie' => [`
- ✅ `app/Support/ProviderCredentialDescriptor.php` bevat `final class ProviderCredentialDescriptor`
- ✅ `tests/Feature/Admin/ProviderDescriptorTest.php` bevat `class ProviderDescriptorTest`

**Plan must_haves key_links present (2/2):**
- ✅ `app/Support/ProviderCredentialDescriptor.php` → `config('hub-providers...')` lookup (regel 39)
- ✅ `app/Models/Connection.php` → `ProviderCredentialDescriptor::for($this->provider)` (regel 51)

## Docs-Sync Note

PostToolUse-hook flagde `app/Models/Connection.php` als domein-model edit. Refactor is gedragsneutraal (zelfde input/output voor Mollie+Snelstart, null voor onbekend) — geen schema-drift, geen nieuwe credential-shape, geen nieuwe OAuth-flow. Wel een nieuwe support-class (`ProviderCredentialDescriptor`) en config-file (`config/hub-providers.php`). Docs-sync uitvoeren voor `.docs/decisions/`-mogelijke ADR rondom D-04 is een phase-09-close-taak (plan 09-12), niet per-plan — gedragsneutrale refactor verandert niets aan CLAUDE.md domain-section.

## Next Plan Readiness

- **Plan 09-06 (ConnectionResource)** kan nu bouwen op:
  - `ProviderCredentialDescriptor::for($connection->provider)` voor per-provider conditional form-sections
  - `descriptor->oauthFlowKey !== null` als guard voor revoke-action visibility (Snelstart heeft `null` → geen OAuth-revoke)
  - `descriptor->primaryFingerprintLabel` voor de fingerprint-kolom-header
  - `descriptor->encryptedFields` lijst voor explicit `->disabled()->dehydrated(false)` op deze fields in form
- **Toekomstige providers** (Moneybird, Exact, Ibanity) toevoegen via config-rij — geen Filament-code-wijziging
- **Geen blocking dependencies open** voor plan 09-05/09-06/09-07

---
*Phase: 09-filament-admin-ui-voor-emeq-medewerkers*
*Completed: 2026-05-15*
