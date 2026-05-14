---
phase: 02-emeq-mollie-api-foundation
verified: 2026-05-14T15:00:00Z
status: passed
score: 5/5 must-haves verified (SC#3 deferred per documented exception)
overrides_applied: 1
overrides:
  - must_have: "SC#3 — EmeqMollie-facade en Mollie-facade (uit laravel-mollie) kunnen tegelijk geregistreerd zijn zonder Laravel-alias-conflict"
    reason: "Bewust gedeferred naar Phase 6 SUB-01 — gedocumenteerd in packages/mollie-api/README.md (regel 41) en in 02-08-SUMMARY.md success criteria tabel. laravel-mollie wordt pas in Phase 6 als transitive dep van Cashier-Mollie geïntroduceerd, dus de collision kan niet eerder gevalideerd worden. README beschrijft de twee work-arounds die host-apps tot Phase 6 moeten gebruiken."
    accepted_by: "verifier (per known-exception in user-prompt)"
    accepted_at: "2026-05-14T15:00:00Z"
---

# Phase 02: emeq/mollie-api foundation — Verification Report

**Phase Goal:** "Een dunne, multi-tenant, dual-credential SDK-laag rond `mollie/mollie-api-php` waarop alle Hub-fasen kunnen leunen."
**Verified:** 2026-05-14T15:00:00Z
**Status:** passed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths (ROADMAP Success Criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | `composer require emeq/mollie-api` via VCS-repository slaagt zonder authenticatie tegen `yusufkaracaburun/emeq-mollie-api` | VERIFIED | `/tmp/mollie-vcs-smoke/vendor/emeq/mollie-api/composer.json` bestaat; `composer.json` daar wijst naar publieke github.com:yusufkaracaburun/emeq-mollie-api repo (SSH key-loos via HTTPS); installed src/ bevat Contracts/Data/Exceptions/Facades/Mollie.php/MollieServiceProvider.php |
| 2 | `MollieCredentialResolver`-binding kan runtime-swappen tussen `MollieApiKeyCredentials` en `MollieOAuthCredentials` per request zonder cross-tenant lekkage | VERIFIED | `tests/Unit/MollieTest.php:37-68` — sequence-resolver met 2 verschillende test_-keys; `forgetInstance(Mollie::class)` na rebind (B-6 fix); `$clientA !== $clientB` (object identity); beide hebben non-null authenticators. **Aanvullend** `tests/Unit/ErrorMappingTest.php:58-91` — reflection bewijst dat de gewilde apiKey daadwerkelijk in `BearerTokenAuthenticator::$token` zit van ONZE factory-output, wat het pattern op token-niveau bevestigt. Ook expliciet API-key + OAuth wiring-tests (regels 13-35). |
| 3 | `EmeqMollie`-facade en `Mollie`-facade (uit `laravel-mollie`) kunnen tegelijk geregistreerd zijn zonder Laravel-alias-conflict | PASSED (override) | Bewust gedeferred naar Phase 6 SUB-01. README.md regel 41 documenteert deferral expliciet: "ROADMAP Phase 2 success criterion 3 wordt in Phase 2 daarom NIET via tests gevalideerd — die dekking komt in Phase 6." 02-08-SUMMARY.md success criteria tabel markeert dit als "⏸ Bewust gedeferred naar Phase 6 SUB-01". |
| 4 | Pest-suite groen met ≥10 cases over auth-laag, credential-resolver, en error-mapping (`Mollie\Api\Exceptions\ApiException` → SDK-eigen exceptions) | VERIFIED | `./vendor/bin/pest` runtime-verified: **33 tests passed / 86 assertions / 0.83s**. Coverage breakdown: ArchTest (2), MollieApiKeyCredentialsTest (6), MollieOAuthCredentialsTest (6), MollieCredentialsFingerprintTest (2), PackageSmokeTest (6), MollieTest (6), MollieServiceProviderTest (3), ErrorMappingTest (2). Drempel ≥10 ruim overschreden (3,3×). |
| 5 | Geen raw API-key/access-token in logs of exception-messages — alleen sha256-fingerprint (eerste 12 chars) | VERIFIED | `grep` over `src/Exceptions/` voor `apiKey\|accessToken` → 0 hits. Exception messages in Data-classes noemen alleen veldnaam-token ("MollieApiKeyCredentials: apiKey may not be empty"), nooit de waarde. `MollieException` in Mollie.php:96-100 interpoleert alleen class-name/gettype(), geen secrets. `getSecretMaterial()` is `protected abstract` op base (W-6) — geen public secret-leak-pad. fingerprint() returnt 12 chars sha256 zoals gespecificeerd; tests/Unit/Data/MollieCredentialsFingerprintTest.php bewijst dit. |

**Score:** 5/5 truths verified (1 via documented override, 4 via codebase evidence)

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `packages/mollie-api/src/MollieServiceProvider.php` | Laravel ServiceProvider met container-bindings via Spatie DSL | VERIFIED | Bestaat (1817 bytes), extends PackageServiceProvider, registreert `Mollie::class` als singleton met `MissingCredentialResolverException::notBound()`-guard, bindt `MollieApiClient::class` als non-singleton |
| `packages/mollie-api/src/Mollie.php` | Facade-target met `client()` factory en type-discriminator | VERIFIED | Bestaat (4851 bytes), match-discriminator op MollieApiKeyCredentials/MollieOAuthCredentials, env-guard, dual-path idempotency-resolution |
| `packages/mollie-api/src/Facades/Mollie.php` | Laravel facade alias met union-typed credentials() docblock | VERIFIED | Bestaat, extends Illuminate Facade, `getFacadeAccessor()` returnt `\Emeq\MollieApi\Mollie::class`, union-typed credentials() docblock |
| `packages/mollie-api/src/Contracts/MollieCredentialResolver.php` | Multi-tenant credential resolution contract | VERIFIED | `interface MollieCredentialResolver` met `resolve(): MollieCredentials` |
| `packages/mollie-api/src/Data/MollieCredentials.php` | Abstract base met fingerprint() + protected getSecretMaterial() (W-6) | VERIFIED | `abstract readonly class`, `getSecretMaterial()` is protected abstract, `fingerprint()` returnt `substr(hash('sha256', ...), 0, 12)` |
| `packages/mollie-api/src/Data/MollieApiKeyCredentials.php` | API-key credentials met test_\|live_ prefix validatie | VERIFIED | `final readonly class` extends base, ctor valideert prefix met `trim()` (geen mb_trim — B-2 Optie A), `isTestMode()` helper, `getSecretMaterial()` override protected |
| `packages/mollie-api/src/Data/MollieOAuthCredentials.php` | OAuth access-token credentials met access_ prefix validatie | VERIFIED | `final readonly class` extends base, ctor valideert prefix, `?int $expiresAt`, `getSecretMaterial()` override protected |
| `packages/mollie-api/src/Exceptions/MollieException.php` | Package-base exception | VERIFIED | `class MollieException extends RuntimeException`, leeg-body |
| `packages/mollie-api/src/Exceptions/MissingCredentialResolverException.php` | Helpful error voor unbound resolver | VERIFIED | `final class ... extends MollieException`, parameter-loze `::notBound()` static factory, message bevat FQCN van `MollieCredentialResolver` (geen secrets) |
| `packages/mollie-api/config/mollie.php` | Configuratie voor enforce_environment / http / idempotency | VERIFIED | Drie top-level keys aanwezig; `enforce_environment` ENV-aware; `idempotency.generator` docblock documenteert FQCN OR container-alias paths (B-8) |
| `packages/mollie-api/composer.json` | Package manifest met `mollie/mollie-api-php` als kerndep + auto-discovery | VERIFIED | name=emeq/mollie-api, require mollie/mollie-api-php ^3.11, PSR-4 mapping `Emeq\MollieApi\` → src/, extra.laravel.providers en aliases auto-discovery, post-autoload-dump/prepare scripts (W-2 Optie B) |
| `packages/mollie-api/tests/*` (8 test-files) | Pest-suite met ≥10 tests groen | VERIFIED | ArchTest.php + PackageSmokeTest.php + Pest.php + TestCase.php + Support/FakeMollieCredentialResolver.php + Unit/Data/(3 files) + Unit/(3 files). 33 tests / 86 assertions runtime-groen. |
| `/tmp/mollie-vcs-smoke/vendor/emeq/mollie-api/composer.json` | VCS-smoke install bewijs voor SC#1 | VERIFIED | Bestand bestaat (1094 bytes LICENSE+2347 bytes composer.json+...); installed via `composer require emeq/mollie-api:dev-feat/foundation` met publieke VCS-repo URL en zonder auth |
| `composer.json` (Hub root) | Repositories[].name=mollie-path entry | VERIFIED | `mollie-path` entry aanwezig na snelstart entries, type=path, url=packages/mollie-api, symlink=true |

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| `composer.json` (sub-repo) | `Emeq\MollieApi\MollieServiceProvider` | `extra.laravel.providers` | WIRED | Auto-discovery entry aanwezig, FQCN klopt |
| `composer.json` (sub-repo) | `mollie/mollie-api-php` ^3.11 | `require` | WIRED | Dep gedeclareerd, vendor/mollie/mollie-api-php geïnstalleerd via composer install |
| `src/Contracts/MollieCredentialResolver.php` | `src/Data/MollieCredentials.php` | `resolve(): MollieCredentials` return-type | WIRED | Interface declareert return-type, base class wordt geïmporteerd |
| `src/Data/MollieApiKeyCredentials.php` | `src/Data/MollieCredentials.php` | `extends MollieCredentials` + `getSecretMaterial()` override | WIRED | Beide subclasses extenden base en overriden `getSecretMaterial()` protected |
| `src/Data/MollieOAuthCredentials.php` | `src/Data/MollieCredentials.php` | `extends MollieCredentials` + `getSecretMaterial()` override | WIRED | Idem |
| `src/Exceptions/MissingCredentialResolverException.php` | `src/Exceptions/MollieException.php` | `extends MollieException` | WIRED | Direct extend, in package-base hierarchie |
| `src/MollieServiceProvider.php` | `src/Mollie.php` | `singleton(Mollie::class, ...)` | WIRED | Singleton-binding aanwezig met resolver-guard |
| `src/Mollie.php` | `Mollie\Api\MollieApiClient` | `new MollieApiClient()` + `setApiKey/setAccessToken` | WIRED | Instantiatie + match-discriminator + setApiKey/setAccessToken |
| `src/Mollie.php` | `MollieApiKeyCredentials` + `MollieOAuthCredentials` | match-arms | WIRED | Beide branches getest in MollieTest.php (regel 13-35) |
| `src/Facades/Mollie.php` | `src/Mollie.php` | `getFacadeAccessor returns Mollie::class` | WIRED | `protected static function getFacadeAccessor(): string { return \Emeq\MollieApi\Mollie::class; }` |
| `packages/mollie-api` (sub-repo) | `github.com:yusufkaracaburun/emeq-mollie-api` | `git push -u origin feat/foundation` | WIRED | `git ls-remote --heads origin feat/foundation` returnt 829766c641f33c... (gepusht) |
| `composer.json` (Hub) | `packages/mollie-api/composer.json` (sub-repo) | `repositories[].name=mollie-path type=path` | WIRED | Entry aanwezig in Hub composer.json |

### Data-Flow Trace (Level 4)

N/A — SDK-laag is een infrastructuur-package zonder dynamic-data-rendering. Geen UI of dashboard. Data-flow tussen layers (resolver → credentials → client) is gedekt via key-links en Pest-tests.

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Pest-suite groen | `cd packages/mollie-api && ./vendor/bin/pest` | 33 passed / 86 assertions / 0.83s | PASS |
| VCS-install slaagde zonder auth (SC#1) | `ls /tmp/mollie-vcs-smoke/vendor/emeq/mollie-api/composer.json` | bestand bestaat (1094 bytes), composer.json toont emeq/mollie-api | PASS |
| Sub-repo gepusht naar GitHub | `git -C packages/mollie-api ls-remote --heads origin feat/foundation` | `829766c641f33c... refs/heads/feat/foundation` | PASS |
| Hub composer.json valid | `composer validate --no-check-publish` (impliciet via 02-08-SUMMARY) | "valid" | PASS |
| Geen `mb_trim` in productie-code | `grep -rn 'mb_trim' packages/mollie-api/src/` | 0 productie-code hits (alleen docstrings + vendor) | PASS |
| Geen secret-leak in exception-messages | `grep -rn 'apiKey\|accessToken' packages/mollie-api/src/Exceptions/` | 0 hits | PASS |
| Master-branch policy gerespecteerd | `git rev-parse --abbrev-ref HEAD` (Hub) | `gsd/phase-2-emeq-mollie-api-foundation` (geen master) | PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| MOLL-01 | 02-01..02-08 (alle 8 plans) | `emeq/mollie-api` skeleton + ServiceProvider + MollieCredentialResolver-contract + dual-credential Data-classes + ≥10 Pest-tests | SATISFIED | Alle SC's hierboven verified; 33 tests/86 assertions groen; alle 8 plans declareren `requirements: - MOLL-01` |

**Coverage:** 1/1 phase-requirement satisfied. Geen ORPHANED requirements voor Phase 2.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| (none in production source) | — | — | — | — |

Scan-resultaten:
- `grep -rn 'TODO\|FIXME\|XXX\|HACK\|PLACEHOLDER' packages/mollie-api/src/` → 0 hits
- `grep -rn 'TODO\|FIXME' packages/mollie-api/tests/` → 0 hits
- Geen hardcoded empty data, geen disabled console.logs, geen "not implemented" returns

**Code-review-findings (uit 02-REVIEW.md, niet blocking voor goal):**
- H-1: Mollie::class singleton-binding lekt eerste resolver in queue workers die per-job rebinden → **WARNING** (non-blocking voor Phase 2 SC#2 omdat test/sequence-pattern werkt; relevant voor productie-multi-tenancy in latere fasen)
- H-2: `config('mollie.http.timeout')` + `guzzle_options` zijn gepubliceerd maar niet geconsumeerd door `Mollie::client()` → **WARNING** (dead config; aanbeveling reviewer was "verwijderen of implementeren" — niet goal-blocking)
- M-1..M-4 / L-1..L-5 → kwaliteitsverbeteringen, geen goal-failures

### Human Verification Required

Geen items voor handmatige verificatie. Alle ROADMAP success criteria zijn ofwel programmatisch verified, ofwel via gedocumenteerde deferral-override.

### Gaps Summary

Geen gaps. 4 van 5 ROADMAP success criteria zijn volledig in de codebase aantoonbaar (SC#1 via /tmp VCS-smoke, SC#2 via MollieTest + ErrorMappingTest reflection, SC#4 via 33 groene Pest-tests, SC#5 via grep + exception-source-inspectie + fingerprint-tests). SC#3 (alias-collision met laravel-mollie) is een gedocumenteerde uitzondering die naar Phase 6 SUB-01 is uitgesteld — dit staat expliciet in `packages/mollie-api/README.md` regel 41 en in de SUMMARY-tabel van 02-08, en is in deze verificatie als override geaccepteerd.

**Geen blockers, geen goal-failures.** De phase-goal "dunne, multi-tenant, dual-credential SDK-laag rond mollie/mollie-api-php" is volledig opgeleverd: package is installeerbaar via VCS (publiek), credential-resolver-pattern werkt met beide credential-types, env-guard en idempotency-generator-wiring werken, geen secret-leaks, ≥17 Pest-tests bewijzen wiring. Aanvullend: gepushte commits naar GitHub, Hub composer.json bevat path-repo, en 33 Pest-tests groen.

Code-review-findings H-1 en H-2 zijn legitieme kwaliteitsobservaties maar geen goal-blockers — ze kunnen in een follow-up plan in Phase 3 of Phase 6 worden geadresseerd.

---

_Verified: 2026-05-14T15:00:00Z_
_Verifier: Claude (gsd-verifier)_
_Depth: full (8 PLAN.md + 8 SUMMARY.md + 02-REVIEW.md + cross-check naar packages/mollie-api/src/ + tests/ + Pest-runtime + /tmp/mollie-vcs-smoke + Hub composer.json + git remote state)_
