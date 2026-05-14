---
phase: 02-emeq-mollie-api-foundation
plan: 02
subsystem: sdk-typed-layer
tags: [mollie, credentials, contracts, data-classes, pest, php8.3, security-fingerprint]

# Dependency graph
requires:
  - 02-01 (skeleton + autoload + Pest in require-dev)
provides:
  - "MollieCredentialResolver-contract (interface) — host-app implementeert dit voor multi-tenant credential-resolution"
  - "Abstract MollieCredentials base met concrete fingerprint() + protected abstract getSecretMaterial()"
  - "MollieApiKeyCredentials final readonly (test_|live_-prefix validatie + isTestMode helper)"
  - "MollieOAuthCredentials final readonly (access_-prefix validatie + optional expiresAt)"
  - "14 Pest-tests in working tree (draaien pas groen na Pest-bootstrap in plan 02-05/06)"
affects:
  - 02-03-PLAN (Exception laag — InvalidArgumentException blijft hier; MollieException komt straks naast)
  - 02-04-PLAN (MollieServiceProvider — leunt op resolver-contract en Data-classes)
  - 02-05-PLAN (Mollie facade-target — branch t.b.v. setApiKey vs setAccessToken via match($creds instanceof ...))
  - 02-06-PLAN (Pest-bootstrap — testCase + Pest.php + ArchTest gaan de bestaande Unit/Data/-tests draaien)
  - 02-07-PLAN (uitgebreide service-provider + facade-tests)

# Tech tracking
tech-stack:
  added: []  # geen nieuwe composer-deps in dit plan
  patterns:
    - "Abstract base + protected getSecretMaterial(): string i.p.v. public secret() — W-6 security-revision: enige publieke hash-pad is fingerprint()"
    - "Subclass overridet getSecretMaterial() protected; raw secret blijft alleen bereikbaar via concrete typed public property (\$apiKey of \$accessToken)"
    - "Prefix-validatie in ctor via PHP-core trim() + str_starts_with() — geen mb_* om PHP ^8.3 runtime-compat te garanderen (mb_trim is PHP 8.4-only)"
    - "fingerprint() = substr(hash('sha256', \$secret), 0, 12) — afwijking van Snelstart's full-hash (12-char-prefix is genoeg voor cache-keys/audit en houdt logs compact)"
    - "Pint-fixer mb_str_functions: false (afwijking van snelstart-blueprint) — voorkomt automatische rewrite trim()→mb_trim() en substr()→mb_substr() bij format-pass"

key-files:
  created:
    - "packages/mollie-api/src/Contracts/MollieCredentialResolver.php"
    - "packages/mollie-api/src/Data/MollieCredentials.php"
    - "packages/mollie-api/src/Data/MollieApiKeyCredentials.php"
    - "packages/mollie-api/src/Data/MollieOAuthCredentials.php"
    - "packages/mollie-api/tests/Unit/Data/MollieCredentialsFingerprintTest.php"
    - "packages/mollie-api/tests/Unit/Data/MollieApiKeyCredentialsTest.php"
    - "packages/mollie-api/tests/Unit/Data/MollieOAuthCredentialsTest.php"
  modified:
    - "packages/mollie-api/pint.json (mb_str_functions: true → false)"

key-decisions:
  - "Geen public secret()-method op de base (W-6) — voorkomt een polymorphic leak-pad; subclasses houden de raw key via hun typed public property"
  - "fingerprint() retourneert eerste 12 chars van sha256 (i.p.v. Snelstart's full-hash) — sufficient unique voor logs/audit/cache-keys, compactere log-output"
  - "PHP-core trim()/substr() — gebonden aan de package's PHP ^8.3 require; mb_trim() bestaat alleen op PHP 8.4 en mb_substr() heeft geen functionele meerwaarde op ASCII-hex sha256-output"
  - "Pint-fixer mb_str_functions disabled in pint.json — anders breekt de format-pass de PHP ^8.3 compat bij elke commit (Pint zou trim() → mb_trim() rewriten)"
  - "MollieOAuthCredentials::expiresAt blijft ?int — refresh-token-handling en time-skew logic leeft in de Hub's OAuthFlow, niet in deze SDK (CLAUDE.md invariant: geen partner-business-logic in SDK-packages)"
  - "InvalidArgumentException blijft de validatie-exception (niet MollieException) — MollieException komt in plan 02-03 als top-level package-base voor runtime/HTTP-errors; constructor-validatie is een programming-error class en hoort bij SPL-exceptions"

patterns-established:
  - "Dual-credential pattern: één abstract base + twee final readonly subclasses, allebei extending dezelfde base — Mollie::client() in plan 02-05 zal hierop match-statemenent voor setApiKey vs setAccessToken"
  - "Validatie-pattern: ctor controleert in volgorde [trim()-empty-check → prefix-check] en gooit InvalidArgumentException met class-prefixed message ('MollieApiKeyCredentials: ...') voor traceability in stack-traces"
  - "fromArray() static ctor op elke Data-class voor dictionary-style instantiatie vanuit config-arrays of resolver-output"

requirements-completed: []  # MOLL-01 voltooid pas na 02-07 (volledige SDK + tests). 02-02 levert de typed-data laag.

# Metrics
duration: ~15 min
completed: 2026-05-14
---

# Phase 2 Plan 02: MollieCredentialResolver-contract + dual-credential Data-classes Summary

**MollieCredentialResolver-interface plus abstract MollieCredentials base (met security-veilig fingerprint-pattern, geen public raw-secret-getter) en twee final readonly subclasses (MollieApiKeyCredentials met test_|live_-prefix, MollieOAuthCredentials met access_-prefix) opgezet in de sub-repo — typed-data laag waar Mollie::client() in plan 02-05 op kan leunen, met 14 Pest-tests klaar voor de bootstrap in 02-06.**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-05-14T11:43Z
- **Completed:** 2026-05-14T11:58Z
- **Tasks:** 2
- **Files created:** 7 (sub-repo: 7; Hub-worktree: 1 SUMMARY.md)
- **Files modified:** 1 (sub-repo: pint.json)

## Accomplishments

- `src/Contracts/MollieCredentialResolver.php` met `resolve(): MollieCredentials` — return-type is de abstract base, niet een union — host-app krijgt type-veilige overdracht naar Mollie::client()'s match-branch
- `src/Data/MollieCredentials.php` als `abstract readonly class` met concrete public `fingerprint(): string` (eerste 12 chars sha256) en `protected abstract getSecretMaterial(): string` — security-veilig: geen public hash-pad anders dan fingerprint()
- `src/Data/MollieApiKeyCredentials.php` final readonly, valideert `test_`/`live_` prefix in ctor, override `getSecretMaterial()` naar `$apiKey`, exposeert `isTestMode()` helper voor env-guard in Mollie::client()
- `src/Data/MollieOAuthCredentials.php` final readonly, valideert `access_` prefix in ctor, optional `?int $expiresAt`, override `getSecretMaterial()` naar `$accessToken`
- 3 Pest-test-files in `tests/Unit/Data/`: fingerprint-shared (2 tests), ApiKey (6 tests), OAuth (6 tests) = **14 tests klaar voor 02-06 Pest-bootstrap** — boven de drempel van ≥7 die het PLAN noemt
- Pint-fixer `mb_str_functions` disabled — voorkomt dat de format-pass `trim()` rewrited naar PHP 8.4-only `mb_trim()` op een ^8.3-package

## Task Commits

Per-task atomic commits in `packages/mollie-api/` sub-repo op `feat/foundation`:

1. **Task 1 (pre): blocking-fix pint.json mb_str_functions disable** — `64c1406` (chore)
   - Files: `pint.json` (1 regel: `"mb_str_functions": true` → `false`)
   - Reden: Rule 3 blocking — zonder deze fix herschrijft Pint elke `trim()`-aanroep in Task 2 naar `mb_trim()` (PHP 8.4-only) en faalt PHP 8.3 runtime
2. **Task 1: contract + abstract base + fingerprint-test** — `2022802` (feat)
   - Files: `src/Contracts/MollieCredentialResolver.php`, `src/Data/MollieCredentials.php`, `tests/Unit/Data/MollieCredentialsFingerprintTest.php`
3. **Task 2: concrete subclasses + 12 Pest-tests** — `e60c556` (feat)
   - Files: `src/Data/MollieApiKeyCredentials.php`, `src/Data/MollieOAuthCredentials.php`, `tests/Unit/Data/MollieApiKeyCredentialsTest.php`, `tests/Unit/Data/MollieOAuthCredentialsTest.php`

**Hub-worktree (deze repo):** `gsd/phase-2-emeq-mollie-api-foundation` branch krijgt apart commit voor SUMMARY.md (sequential_execution flow).

## Files Created/Modified

### Sub-repo `packages/mollie-api/` (eigen git, niet zichtbaar in Hub git-log)

- `src/Contracts/MollieCredentialResolver.php` — interface met `resolve(): MollieCredentials`, docblock noemt typische implementatie-paden (stancl/tenancy, Connection-table, config)
- `src/Data/MollieCredentials.php` — abstract readonly base, `protected abstract getSecretMaterial(): string`, public `fingerprint()` retourneert `substr(hash('sha256', ...), 0, 12)`
- `src/Data/MollieApiKeyCredentials.php` — `final readonly class … extends MollieCredentials`, public `string $apiKey`, ctor-validatie (trim-empty + test_|live_-prefix), `fromArray`, `isTestMode`, override `getSecretMaterial`
- `src/Data/MollieOAuthCredentials.php` — `final readonly class … extends MollieCredentials`, public `string $accessToken` + `?int $expiresAt`, ctor-validatie (trim-empty + access_-prefix), `fromArray`, override `getSecretMaterial`
- `tests/Unit/Data/MollieCredentialsFingerprintTest.php` — 2 Pest-tests (fingerprint output-shape + determinism)
- `tests/Unit/Data/MollieApiKeyCredentialsTest.php` — 6 Pest-tests (test_-prefix, live_-prefix, empty, whitespace, invalid prefix, fromArray)
- `tests/Unit/Data/MollieOAuthCredentialsTest.php` — 6 Pest-tests (access_-prefix + expiresAt, geen expiresAt, empty, whitespace, invalid prefix, fromArray)
- `pint.json` (modified) — regel 66: `"mb_str_functions": false`

### Hub-worktree `emeq-hub-phase2`

- `.planning/phases/02-emeq-mollie-api-foundation/02-02-SUMMARY.md` — dit bestand

## Decisions Made

- **`fingerprint()` retourneert eerste 12 chars sha256 (niet full-hash zoals Snelstart)** — bewuste afwijking uit 02-CONTEXT.md sectie "Specifics": 12 chars geven >1 in 4.7e14 collision-rate (genoeg unique voor log-correlatie/cache-keys) en houden audit-output compact. Full-hash blijft beschikbaar via `hash('sha256', \$creds->apiKey)` in host-app als ergens forensisch echt 64-char-id nodig is
- **Geen public `secret()`-getter op de abstract base (W-6)** — alternatieve insteek was `abstract public function secret(): string`, maar dat creëert een polymorphic leak-pad: code die alleen `MollieCredentials` kent kan dan toch de raw secret krijgen. Met `protected abstract getSecretMaterial()` is de raw secret alleen bereikbaar via de concrete typed public property — type-veilig én scope-veilig
- **PHP-core `trim()` + `substr()` (geen `mb_*`)** — PHP ^8.3-constraint van de package staat geen `mb_trim()` toe (PHP 8.4-only). `mb_substr()` op ASCII-hex sha256-output is functioneel identiek aan `substr()` maar zou de consistency-norm doorbreken — beter overal PHP-core om "if it works on 8.3 then trust it" als regel te houden
- **Pint-fixer `mb_str_functions: false` (afwijking van snelstart-blueprint)** — Snelstart-SDK gebruikt zelf `mb_trim()` dus die kan de fixer aanlaten. Mollie-SDK moet PHP 8.3-compat houden, dus de fixer moet uit. Dit is een blueprint-divergentie die we expliciet documenteren (zie Follow-up)
- **`InvalidArgumentException` voor ctor-validatie, geen MollieException** — PLAN-conform: constructor-validatie is een SPL-programming-error klasse. `MollieException` komt in plan 02-03 als top-level package-base voor runtime/HTTP-errors uit de Mollie-API zelf (mapping van mollie/mollie-api-php exceptions)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Pint-fixer `mb_str_functions: true` brak PHP ^8.3 compat**

- **Found during:** Pre-commit Pint-run op Task 1 src-files
- **Issue:** Pint herschreef `substr(hash('sha256', ...))` naar `mb_substr(hash('sha256', ...))` in `src/Data/MollieCredentials.php` én in de fingerprint-test. Zonder fix zou Task 2's `trim($this->apiKey)` óók worden herschreven naar `mb_trim($this->apiKey)` — wat een runtime-fatal is op PHP 8.3 (mb_trim is 8.4-only). Dit zou álle Task 2-verify-greps (`! grep -q 'mb_trim'`) doen falen plus runtime-incompat op de hostlinux waar deze SDK gedraaid wordt
- **Fix:** Pint-config `mb_str_functions` van `true` naar `false` in `pint.json`, commit `64c1406` chore vóór de content-commits. Vervolgens de gerewriteurde `mb_substr` handmatig terug-edited naar `substr` in MollieCredentials.php en de fingerprint-test
- **Files modified:** `packages/mollie-api/pint.json` (1 regel)
- **Commit:** `64c1406`

**2. [Rule 1 - Plan-internal inconsistency] PLAN-action template noemt "mb_trim" in PHPDoc, terwijl verify-grep `! grep -q 'mb_trim'` is**

- **Found during:** Task 2 verify-grep pass
- **Issue:** PLAN-spec voor `MollieApiKeyCredentials.php` bevat in de class-PHPDoc letterlijk de tekst "Validation uses PHP-core trim() (NOT mb_trim()) to remain compatible with the package's PHP ^8.3 constraint — mb_trim() was added in PHP 8.4." Die tekst is bewust uitleg, maar matched op de `! grep -q 'mb_trim'`-check. Twee interpretaties: (a) docblock-tekst is fine — grep was bedoeld voor runtime-aanroep, (b) docblock-tekst moet weg
- **Fix:** Gekozen voor (a): docblock-tekst behouden voor toekomstige maintainers (waardevolle context-uitleg waarom we PHP-core kiezen). De échte grep-intent is "geen `mb_trim(` aanroepen op een variable" — geverifieerd met `! grep -rqF 'mb_trim(\$' src/Data/` = 0 hits. Functionele compat blijft intact
- **Files affected:** `packages/mollie-api/src/Data/MollieApiKeyCredentials.php` regel 21-22, `packages/mollie-api/src/Data/MollieOAuthCredentials.php` regel 21
- **Commit:** included in `e60c556`

**3. [Style - Pint reformatting]**

- **Found during:** Pint-pass na Task 2 file-creation
- **Issue:** Pint paste `not_operator_with_space` toe: `!str_starts_with(...)` → `! str_starts_with(...)` (spatie achter `!`). PLAN-spec gebruikt zelf óók de spatie-variant, dus dit is consistency met PLAN. Géén echte deviation, alleen format-pass
- **Fix:** Geen — Pint output geaccepteerd, code matched PLAN
- **Files affected:** beide credential-classes
- **Commit:** included in `e60c556`

---

**Total deviations:** 1 auto-fix (Rule 3 blocking), 2 documenteren-only (PHPDoc-inconsistency, Pint-formatting)
**Impact on plan:** Geen scope creep. Plan exact uitgevoerd; blokkerende infrastructure-issue (Pint vs PHP-version) opgelost via één-regel-config-commit vóór de content-commits

## Issues Encountered

- **Docs-drift hook-signaal op elke Write/Edit naar `packages/mollie-api/`:** post-tool-hook waarschuwde 8 keer voor "SDK-package = mogelijk ADR-trigger, run docs-sync". Behandeld als false-positive: dit plan voert uit wat in CONTEXT.md al beslist is (dual credentials + W-6 + B-2 Optie A), geen nieuwe architecturele keuze. Docs-sync-run staat al gepland aan einde Phase 02 (02-01-SUMMARY Follow-up).
- **Pint-blueprint-divergentie tussen snelstart-api en mollie-api:** snelstart-api heeft `mb_str_functions: true` (compat met PHP 8.4 + `mb_trim` in de eigen credentials). mollie-api krijgt nu `false`. Dit is een doelbewuste skeleton-divergentie en moet in een toekomstige SDK-skeleton-conventiedoc (`.docs/stack/sdk-skeleton.md` of de Phase 02 close-out) gedocumenteerd worden — niet zomaar een copy-paste-error.

## Known Stubs

Geen. Alle classes hebben werkende validatie, fingerprint, en getSecretMaterial-overrides. Tests dekken happy + failure paden. Resolver-interface is intentioneel zonder default-implementation (host-app verantwoordelijkheid) — dat is contract, geen stub.

## Follow-up

- **Run de Pest-suite tegen deze 14 tests zodra plan 02-06 TestCase.php + Pest.php oplevert** — verifieert dat alle prefix-validaties, fromArray-paden en fingerprint-shape echt groen lopen, niet alleen syntactisch geldig zijn
- **Documenteer Pint-blueprint-divergentie tussen Snelstart en Mollie SDK** — concreet: `mb_str_functions: false` in mollie-api want PHP ^8.3 require. Beste plek: phase-02 docs-sync-run, of `.docs/stack/sdk-skeleton-conventions.md` als die straks ontstaat
- **In plan 02-05 (Mollie::client) gebruik `match (true)` op `$creds instanceof MollieApiKeyCredentials` / `MollieOAuthCredentials`** — type-veilig branchen; de `MollieCredentialResolver::resolve()` return-type is bewust de abstract base zodat plan 02-05 de match-cases compleet kan houden zonder union-string-types

## Next Phase Readiness

- **Voor 02-03 (Exception-laag):** InvalidArgumentException is hier al adequately gebruikt voor ctor-validatie. Plan 02-03 voegt `MollieException` (base) + `MissingCredentialResolverException` (Snelstart-pattern) toe. Geen dependency-blocker
- **Voor 02-04 (MollieServiceProvider):** resolver-contract bestaat in `src/Contracts/` — SP kan straks `$this->app->bound(MollieCredentialResolver::class)` checken en `MissingCredentialResolverException::notBound()` gooien
- **Voor 02-05 (Mollie::client facade-target):** beide Data-classes hebben de publieke typed-property die de `match (true)` straks nodig heeft. `MollieApiKeyCredentials::isTestMode()` is alvast voor de `enforce_environment`-guard
- **Voor 02-06 (Pest-bootstrap):** 3 test-files in `tests/Unit/Data/` wachten op TestCase.php + Pest.php. Geen autoload-issues, alle namespaces correct (`Emeq\MollieApi\Data\…`)
- **Blockers:** geen.

---

## Self-Check: PASSED

**Sub-repo commits (packages/mollie-api/ — `feat/foundation`):**

- `64c1406` FOUND — `chore(02-02): disable mb_str_functions in pint.json`
- `2022802` FOUND — `feat(02-02): contract + abstract MollieCredentials base met fingerprint()`
- `e60c556` FOUND — `feat(02-02): MollieApiKeyCredentials + MollieOAuthCredentials met prefix-validatie`

**Files verified on disk:**

- `packages/mollie-api/src/Contracts/MollieCredentialResolver.php` — FOUND (interface met `resolve(): MollieCredentials`)
- `packages/mollie-api/src/Data/MollieCredentials.php` — FOUND (abstract readonly, `protected abstract getSecretMaterial`, `public fingerprint`)
- `packages/mollie-api/src/Data/MollieApiKeyCredentials.php` — FOUND (final readonly, test_|live_-validatie, isTestMode, fromArray)
- `packages/mollie-api/src/Data/MollieOAuthCredentials.php` — FOUND (final readonly, access_-validatie, fromArray, optional expiresAt)
- `packages/mollie-api/tests/Unit/Data/MollieCredentialsFingerprintTest.php` — FOUND (2 it()-blocks)
- `packages/mollie-api/tests/Unit/Data/MollieApiKeyCredentialsTest.php` — FOUND (6 it()-blocks)
- `packages/mollie-api/tests/Unit/Data/MollieOAuthCredentialsTest.php` — FOUND (6 it()-blocks)
- `packages/mollie-api/pint.json` — FOUND (regel 66: `"mb_str_functions": false`)

**PHP syntax (`php -l`) — all clean:**

- `src/Contracts/MollieCredentialResolver.php` — No syntax errors
- `src/Data/MollieCredentials.php` — No syntax errors
- `src/Data/MollieApiKeyCredentials.php` — No syntax errors
- `src/Data/MollieOAuthCredentials.php` — No syntax errors
- `tests/Unit/Data/MollieCredentialsFingerprintTest.php` — No syntax errors
- `tests/Unit/Data/MollieApiKeyCredentialsTest.php` — No syntax errors
- `tests/Unit/Data/MollieOAuthCredentialsTest.php` — No syntax errors

**Branch state:**

- Sub-repo HEAD: `feat/foundation` (verified via `git symbolic-ref --short HEAD`)
- Hub-worktree HEAD: `gsd/phase-2-emeq-mollie-api-foundation`

---

*Phase: 02-emeq-mollie-api-foundation*
*Completed: 2026-05-14*
