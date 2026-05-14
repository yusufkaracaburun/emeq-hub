---
phase: 02-emeq-mollie-api-foundation
plan: 06
subsystem: sdk-pest-core-coverage
tags: [mollie, pest, multi-tenant, env-guard, idempotency, singleton, container-binding, php8.3]

# Dependency graph
requires:
  - 02-01 (vendor/orchestra/testbench + vendor/mollie/mollie-api-php)
  - 02-04 (Mollie facade-target + MollieServiceProvider bindings — SUT)
  - 02-05 (TestCase + Pest bootstrap + FakeMollieCredentialResolver — base + sequence-factory)
provides:
  - "tests/Unit/MollieTest.php — 6 Pest-tests die Mollie::client() volledig dekken: API-key wiring, OAuth wiring, multi-tenant key-wissel (B-6), env-guard fires + skip (B-7, drie sub-cases), custom idempotency-generator via container-alias (B-8)"
  - "tests/Unit/MollieServiceProviderTest.php — 3 Pest-tests die de SP container-binding-shape valideren: Mollie::class singleton-identity, MollieApiClient::class non-singleton (3-resolve check, strenger dan PackageSmokeTest), MollieCredentialResolver::class niet pre-bound"
  - "Multi-tenant key-swap test bewijst ROADMAP success criterion 2: één sequence-resolver + forgetInstance(Mollie::class) levert per ->client() call een fresh MollieApiClient met de juiste tenant-credentials — geen cross-tenant lekkage via een gecachte singleton"
  - "Env-guard test bewijst dat de B-7 detectEnvironment-override correct doorpropageert naar Mollie::guardEnvironment() via container->make('app')->environment() — getest in production+test_ (throw) + production+live_ (ok) + non-prod+test_ (ok) + production+test_+enforce=false (ok)"
  - "Idempotency-generator dual-path test bewijst dat config('mollie.idempotency.generator') werkt zowel met een FQCN als met een container-alias — alias-pad via app()->instance('mollie.idempotency-stub', $stub) + config('mollie.idempotency.generator', 'mollie.idempotency-stub')"
affects:
  - 02-07-PLAN (error-mapping tests — Pest-baseline staat nu op 31 tests; 02-07 voegt de laatste 2 ApiException-mapping cases toe)
  - 02-08-PLAN (README + Hub-integration step — kan referen aan de multi-tenant + dual-credential test-coverage als bewijs dat het SDK-pattern production-grade is)

# Tech tracking
tech-stack:
  added: []  # geen nieuwe composer-deps; alleen extra test-coverage op bestaande wiring
  patterns:
    - "Singleton-cache-invalidation discipline in Pest-tests: $this->app->forgetInstance(Mollie::class) na elke rebind van MollieCredentialResolver of detectEnvironment-call. Zonder forgetInstance hangt de Mollie-singleton aan de oude resolver/config/env en kun je niet meerdere config-permutaties in één test draaien (B-6)"
    - "Laravel's gedocumenteerde runtime-env override $this->app->detectEnvironment(Closure) gebruikt voor env-guard testing — drie sub-cases (production/testing) in één it()-block met sanity-asserts vóór de exception-expect (B-7)"
    - "Container-alias test-double pattern: app()->instance('mollie.idempotency-stub', new class implements ...) + config('mollie.idempotency.generator', 'mollie.idempotency-stub') — bewijst dat de SDK's $container->make($value) string-path zowel FQCN als alias accepteert zonder branching"
    - "Strikte non-singleton check: 3-resolve assertion-chain in MollieServiceProviderTest ($a !== $b, $b !== $c, $a !== $c) — sluit een edge case uit waarbij de 2e en 3e resolve hetzelfde cached object zouden delen"
    - "Test-fixture key-lengte discipline (≥30 chars): elke API-key-string in dit plan is verlengd met padding (e.g. 'test_alphaAAAAAAAAAAAAAAAAAAAAAAAAA') zodat de Mollie-lib's TokenValidator::isApiKey() geen InvalidAuthenticationException gooit bij setApiKey() in Mollie::client() — pattern van 02-05 doorgetrokken"

key-files:
  created:
    - "packages/mollie-api/tests/Unit/MollieTest.php"
    - "packages/mollie-api/tests/Unit/MollieServiceProviderTest.php"
  modified:
    - "packages/mollie-api/src/Mollie.php (use-statement IdempotencyKeyGeneratorContract gefixt — Rule 1 deviation, zie hieronder)"

key-decisions:
  - "Anonymous-class als IdempotencyKeyGeneratorContract-stub ipv een aparte test/Support/StubIdempotencyKeyGenerator.php — houdt de test self-contained, geen extra file voor één test-only implementatie. De class is gedeclareerd binnen het it()-block en wordt nergens anders gebruikt"
  - "B-7 env-guard test: drie sub-cases (a/b/c) in één it()-block ipv drie aparte it()'s — bespaart 2× Testbench-bootcost en houdt de assertion-context bij elkaar. forgetInstance + detectEnvironment + config()->set wisselen tussen de sub-cases zodat elke een schone slate heeft"
  - "MollieServiceProviderTest dekt 3 binding-shape contracten die NIET overlappen met PackageSmokeTest: (a) singleton-identity (PackageSmokeTest test 4 dekt alleen instanceof+credentials, niet identity), (b) 3-resolve non-singleton (PackageSmokeTest test 6 doet alleen 2-resolve), (c) niet-pre-bound resolver met state-transition assert (bound() = false → true). Geen duplicaten"
  - "Plan-actionblock specificeerde fixture-keys zonder padding (`test_alpha`, `test_beta`, etc.). Tijdens execute deze verlengd naar ≥30 chars zodat de keys door de Mollie-lib's TokenValidator passen — zelfde fix als plan 02-05 al moest doen. Niet als deviation gedocumenteerd want het is een doorgetrokken decision uit 02-05, geen nieuwe ontdekking"

patterns-established:
  - "SDK-test-coverage-template voor outer-layer-pattern: één MollieTest die het facade-target dekt (resolver-wiring + multi-tenant key-swap + env-guard + config-driven extensions) + één MollieServiceProviderTest die de binding-shape valideert (singleton vs non-singleton + niet-pre-bound interfaces). Toekomstige Emeq-SDKs (Moneybird/Ibanity/Exact) kunnen deze 2-file template kopiëren door alleen namespaces en credential-types te swappen"
  - "Multi-tenant key-swap test als first-class success criterion: één test in MollieTest bewijst dat het volledige binding+resolver+client-pad een fresh client per resolve levert ook als de credentials cyclen. Zonder deze test was de bind() vs singleton() asymmetrie in MollieServiceProvider niet runtime-getest, alleen runtime-aangenomen"
  - "Container-alias resolution path als first-class test-target: door config-value als string te accepteren ipv afdwingen dat het een FQCN moet zijn, krijgen host-apps één extensiepunt voor twee gebruiksstijlen (declaratief FQCN of dependency-injection-bind). De test bevestigt dat het SDK-contract dit niet per ongeluk gaat verbreken"

requirements-completed: []  # MOLL-01 staat in 02-04's frontmatter — dit plan is coverage-validatie, geen requirement-completion

# Metrics
duration: 11min
completed: 2026-05-14
---

# Phase 02 Plan 06: Pest Core-Coverage Summary

**Coverage-laag voor Mollie::client() + MollieServiceProvider: 9 nieuwe Pest-tests die het volledige type-discriminator + env-guard + idempotency dual-path + binding-shape contract afdwingen. Pest-suite staat op 31 tests / 80 assertions in <1s — drempel ≥10 ruim gehaald. Een Rule-1 bugfix in src/Mollie.php (verkeerde IdempotencyKeyGeneratorContract import-namespace uit 02-04) is bij deze coverage-uitbreiding ontdekt en meegenomen.**

## Performance

- **Duration:** ~11 min (incl. één Rule-1 deviation cycle voor de IdempotencyKeyGeneratorContract namespace-typo)
- **Started:** 2026-05-14T (tijdens execute-phase orchestrator run)
- **Completed:** 2026-05-14
- **Tasks:** 2 atomair gecommit
- **Files created:** 2
- **Files modified:** 1 (Rule-1 bugfix)

## Accomplishments

- **MollieTest dekt 6 facade-target-paden:** API-key resolver-wiring (`getAuthenticator()` non-null), OAuth resolver-wiring (`getAuthenticator()` non-null), multi-tenant key-wissel via `sequence([MollieApiKeyCredentials::class => ['test_a','test_b']])` met `forgetInstance(Mollie::class)` om de singleton-cache te invalideren (B-6), env-guard fires bij production + test_ + enforce_environment=true via `detectEnvironment(fn () => 'production')` override (B-7), env-guard skips voor de drie negatieve permutaties (live_/disabled/non-prod), idempotency-generator via container-alias-pad (B-8 — `app()->instance('mollie.idempotency-stub', $stub)` + config string).
- **MollieServiceProviderTest dekt 3 binding-shape contracten:** `Mollie::class` is singleton (twee `app(Mollie::class)` calls returnen identieke instance — `->toBe()` identity-check, niet `->toEqual()`), `MollieApiClient::class` is non-singleton (3-resolve check: `$a !== $b !== $c`, strenger dan PackageSmokeTest's 2-resolve), `MollieCredentialResolver::class` is niet pre-bound (state-transition assert: `bound()` is `false` direct na SP-boot, `true` pas na host-bind).
- **ROADMAP success criterion 2 afgedekt:** "multi-tenant runtime swap zonder cross-tenant lekkage" is bewezen door de sequence-resolver test met correcte singleton-cache-invalidation. De test demonstreert dat één en dezelfde `Mollie`-instance via twee opeenvolgende `client()`-calls twee fresh `MollieApiClient`-instances levert met aparte authenticators — geen state-leak.
- **ROADMAP success criterion 4 overschreden:** drempel ≥10 Pest-tests groen op auth + resolver. Suite staat nu op 31 tests / 80 assertions in 0.58s. Voor 02-07 (de laatste 2 error-mapping tests) is er nog ruimte zonder bootstrap-werk.
- **Rule-1 bugfix gevangen via coverage:** `src/Mollie.php`'s `use Mollie\Api\Idempotency\IdempotencyKeyGeneratorContract;` was een typo uit 02-04 — de echte interface zit onder `Mollie\Api\Contracts\IdempotencyKeyGeneratorContract`. Geen test in 02-05 had de idempotency-pad daadwerkelijk getriggerd (PackageSmokeTest ging niet door `applyIdempotencyGenerator()` heen), dus de typo bleef hangen tot dit plan's B-8 test 'm onverwijderbaar oppervlakkig maakte. Fix is in dezelfde commit als de testfile gelandt (logisch atomair: zonder fix kan de test niet groen draaien).

## Task Commits

Elke task atomair gecommit in de mollie-api sub-repo op branch `feat/foundation`:

1. **Task 1: MollieTest + Rule-1 fix in src/Mollie.php** — `a51ebae` (test) — 144 insertions, 1 deletion across 2 files
2. **Task 2: MollieServiceProviderTest** — `1c9687e` (test) — 49 insertions across 1 file

Geen Hub-worktree-commit van plan-artefacten in deze run — orchestrator commit SUMMARY/STATE/ROADMAP.

## Files Created/Modified

- `packages/mollie-api/tests/Unit/MollieTest.php` — 6 `it()`-blocks. Imports `IdempotencyKeyGeneratorContract` uit de gecorrigeerde `Mollie\Api\Contracts\` namespace. Gebruikt `FakeMollieCredentialResolver::withApiKey/::withOAuth/::sequence` (uit 02-05). Anonymous-class stub voor idempotency-test. Alle API-key-fixtures ≥30 chars.
- `packages/mollie-api/tests/Unit/MollieServiceProviderTest.php` — 3 `it()`-blocks. Pure container-binding-shape contracts: singleton-identity via `->toBe()`, non-singleton via 3-resolve `not->toBe()` keten, pre-bound-state via `bound()` state-transition.
- `packages/mollie-api/src/Mollie.php` — Rule-1 fix: `use Mollie\Api\Idempotency\IdempotencyKeyGeneratorContract;` → `use Mollie\Api\Contracts\IdempotencyKeyGeneratorContract;`. Eén regelwijziging; alle downstream references (`instanceof`, `::class`) blijven werken via de aliased use-statement.

## Decisions Made

- **Test 6 (idempotency) gebruikt anonymous-class stub** — ipv een aparte `tests/Support/StubIdempotencyKeyGenerator.php`. Een dedicated file zou alleen door deze ene test gebruikt worden; inlining houdt de scope strak en sluit aan bij Pest's idiom van zelfdragende it()-blocks.
- **B-7 env-guard drie sub-cases in één it()-block** — `production+live_` (ok), `production+test_+enforce=false` (ok), `testing+test_+enforce=true` (ok). Per sub-case `forgetInstance + detectEnvironment + config()->set + bind` om een schone slate te krijgen. Alternatief (drie aparte it()'s) zou de Testbench-bootcost ~3× verdubbelen zonder extra coverage.
- **MollieServiceProviderTest blijft op 3 contracten** — singleton-identity, non-singleton (3-resolve), niet-pre-bound. Bewust géén "Mollie::class instanceof Mollie" assert toegevoegd want dat is al gedekt in PackageSmokeTest test 4. Vermijdt duplicaten.
- **Rule-1 fix als onderdeel van Task 1 commit** — `src/Mollie.php`'s typo zou anders een aparte fix-commit op `feat/foundation` worden. Aangezien zonder de fix de B-8 test niet groen kan, is het logisch één atomaire commit ("test toegevoegd + bug die de test oppervlakkig maakt gefixed"). De commit-body documenteert beide expliciet.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Verkeerde IdempotencyKeyGeneratorContract namespace import in src/Mollie.php**

- **Found during:** Task 1 eerste Pest-run (test 6 'applies a custom IdempotencyKeyGenerator…' faalde met `Interface "Mollie\Api\Idempotency\IdempotencyKeyGeneratorContract" not found`)
- **Issue:** `src/Mollie.php` (uit plan 02-04) importeerde `use Mollie\Api\Idempotency\IdempotencyKeyGeneratorContract;`. De echte interface in `mollie/mollie-api-php` v3.11 zit onder `Mollie\Api\Contracts\IdempotencyKeyGeneratorContract`. Geen `class_alias` in de vendor-tree als fallback. PHP heeft de typo bij compile-time niet opgemerkt omdat use-statements lazy zijn — `IdempotencyKeyGeneratorContract::class` resolveert pas bij eerste access. Plan 02-05's PackageSmokeTest test 6 ging niet door `applyIdempotencyGenerator()` heen (config-value is default null), dus de path bleef onaangeraakt tot dit plan's B-8 test.
- **Fix:** `use Mollie\Api\Idempotency\IdempotencyKeyGeneratorContract;` → `use Mollie\Api\Contracts\IdempotencyKeyGeneratorContract;` in `src/Mollie.php`. Zelfde correctie ook in `tests/Unit/MollieTest.php` (mijn test had de plan-actionblock-namespace overgenomen). Beide files in één commit (Task 1).
- **Files modified:** `packages/mollie-api/src/Mollie.php` + `packages/mollie-api/tests/Unit/MollieTest.php`
- **Commit:** `a51ebae`
- **Plan-impact:** Geen — dit is een 02-04 bug die door 02-06's coverage-uitbreiding boven water kwam. Bevestigt het patroon dat plan 02-06 als doel heeft: contract-tests die zwijgende-bug-paden afdekken.

### Pint cosmetic rewrites

- Pint paste `new_with_parentheses` + `class_definition` toe op het anonymous-class-statement in `tests/Unit/MollieTest.php` (test 6) — voegt `()` toe achter `new class` en past de brace-positie aan
- Pint paste `binary_operator_spaces` toe op `tests/Unit/MollieServiceProviderTest.php` — aligneert de `$first` / `$second` `=` assignments
- Beide cosmetic-only, geen semantische impact

## Issues Encountered

- **ugrep-alias interpreteerde `->secret()` als optie** — lokale shell heeft `grep` aliased naar `ugrep` die de `-` in `->` als optie-prefix parsed. Omzeild door `grep -F -- 'patroon'` (fixed-string + double-dash) of door de check te baseren op grep's exit-code in een chain. Geen impact op verificatie-resultaat.
- **Plan-actionblock fixture-keys waren korter dan 30 chars** — `test_alpha`, `test_beta`, etc. Zelfde discovery als plan 02-05 deed; verlengd naar ≥30 chars padding zodat de Mollie-lib's `TokenValidator::isApiKey()` ze accepteert. Niet als deviation gedocumenteerd want het is een doorgetrokken pattern uit 02-05's `key-decisions`, geen nieuwe ontdekking.

## Verification Summary

Alle plan-`<verification>`-clausules + `<success_criteria>`:

- `Mollie::class` hits in `tests/Unit/MollieTest.php` → PASS (14 hits, ≥ "meerdere")
- `Mollie::class` hits in `tests/Unit/MollieServiceProviderTest.php` → PASS (3 hits, ≥ "meerdere")
- `detectEnvironment(fn () => 'production')` in `tests/Unit/MollieTest.php` → PASS (2 hits, B-7)
- `forgetInstance(Mollie::class)` in `tests/Unit/MollieTest.php` → PASS (7 hits, B-6)
- `instance('mollie.idempotency-stub'` in `tests/Unit/MollieTest.php` → PASS (1 hit, B-8)
- Geen `app()['env']` anti-pattern (B-7) → PASS
- Geen `->secret()`-aanroepen (W-6) → PASS
- 6 + 3 = 9 nieuwe `it(...)` blocks → PASS
- `./vendor/bin/pest --filter='Mollie(Test|ServiceProviderTest)'` exit 0 → PASS (9 tests / 25 assertions)
- Volledige Pest-suite ≥17 tests → PASS (31 tests / 80 assertions)
- `php -l` op beide test-files + gewijzigde `src/Mollie.php` → PASS

ROADMAP success criteria:

- SC #2 ("multi-tenant runtime swap zonder cross-tenant lekkage") → PASS via `it returns a fresh MollieApiClient per call so multi-tenant key-swaps do not leak across calls`
- SC #4 (≥10 Pest-tests groen op auth + resolver) → OVERSCHREDEN (31 tests groen)
- SC #5 ("geen raw tokens in logs/exceptions — alleen fingerprint") → impliciet gedekt via fingerprint-tests uit 02-02 + exception-class-structuur uit 02-03; geen nieuwe assertion in 02-06

## Next Phase Readiness

- **02-07 ready:** error-mapping tests (≥2 cases, MollieApiClient::fake + ValidationException::getField + 422 response shape) leunen op dezelfde TestCase + FakeMollieCredentialResolver basis. Geen extra bootstrap nodig; Pest-baseline staat op 31 tests, eindtotaal ≥33 na 02-07.
- **02-08 ready:** README + Hub-integration step kan refereren aan deze coverage-laag als evidence dat het SDK-pattern productie-grade is. Concrete data-points voor de README: 31 tests / 80 assertions / <1s suite-tijd / dual-credential + multi-tenant + env-guard + idempotency-dual-path coverage.
- **Geen blockers** voor wave 6+ (plans 07+).

## Self-Check: PASSED

Files exist (verified via Pest --list-tests output earlier in session):
- `/Users/yusufkaracaburun/Sites/localhost/emeq-hub-phase2/packages/mollie-api/tests/Unit/MollieTest.php` → FOUND
- `/Users/yusufkaracaburun/Sites/localhost/emeq-hub-phase2/packages/mollie-api/tests/Unit/MollieServiceProviderTest.php` → FOUND
- `/Users/yusufkaracaburun/Sites/localhost/emeq-hub-phase2/packages/mollie-api/src/Mollie.php` (modified) → FOUND

Sub-repo commits exist (via `git log --oneline` in mollie-api):
- `a51ebae test(02-06): MollieTest — multi-tenant key-wissel + env-guard + idempotency-alias` → FOUND
- `1c9687e test(02-06): MollieServiceProviderTest — SP binding-shape contracts` → FOUND

Pest suite confirmed green:
- Combined filter (`Mollie(Test|ServiceProviderTest)`) → 9 passed, 25 assertions
- Full suite → 31 passed, 80 assertions, 0.58s

---
*Phase: 02-emeq-mollie-api-foundation*
*Completed: 2026-05-14*
