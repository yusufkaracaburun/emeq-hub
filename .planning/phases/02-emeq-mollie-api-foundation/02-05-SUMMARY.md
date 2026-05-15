---
phase: 02-emeq-mollie-api-foundation
plan: 05
subsystem: sdk-test-infrastructure
tags: [mollie, pest, testbench, arch-tests, smoke-test, test-double, multi-tenant, php8.3]

# Dependency graph
requires:
  - 02-01 (vendor/orchestra/testbench + vendor/pestphp/pest + autoload Emeq\MollieApi\Tests\)
  - 02-02 (MollieCredentialResolver-contract + MollieApiKeyCredentials/MollieOAuthCredentials voor FakeMollieCredentialResolver)
  - 02-03 (MissingCredentialResolverException::notBound voor PackageSmokeTest)
  - 02-04 (MollieServiceProvider + Mollie facade-target + Facades\\Mollie alias — gehele SP-boot wordt gerookt)
provides:
  - "Testbench TestCase met MollieServiceProvider registratie — base voor alle Pest-tests"
  - "tests/Pest.php — uses(TestCase::class)->in(__DIR__) + Cache::flush() beforeEach hook (hermetic tests)"
  - "tests/ArchTest.php — 2 arch-rules: no debug-funcs + strict_types declared everywhere op Emeq\\MollieApi namespace"
  - "tests/PackageSmokeTest.php — 6 tests die SP-boot, config-defaults, missing-resolver-guard, facade-target resolution, facade-root, en non-singleton MollieApiClient binding asserten"
  - "tests/Support/FakeMollieCredentialResolver.php — test-double met 3 factories (withApiKey/withOAuth/sequence) en variadic ctor; sequence accepteert plain-list én FQCN-keyed shortcut-map vorm"
  - "Groene Pest-suite: 22 tests / 55 assertions in <1s (drempel ≥10 ruim gehaald)"
affects:
  - 02-06-PLAN (Pest-suite voor type-discriminator/env-guard/idempotency — leunt direct op TestCase + FakeMollieCredentialResolver)
  - 02-07-PLAN (PHPStan + extra arch-rules — analyseert nu een tests-tree met bekende patronen)
  - Phase 3+ (Hub-integratie — kan FakeMollieCredentialResolver lenen voor Hub-eigen tests, of als referentie voor een Hub-eigen DatabaseDrivenResolver-test-double)

# Tech tracking
tech-stack:
  added: []  # geen nieuwe composer-deps; alleen wiring van bestaande dev-deps
  patterns:
    - "Testbench-base Orchestra\\Testbench\\TestCase + getPackageProviders() returnt [MollieServiceProvider::class] — 1-op-1 mirror van SnelstartTestCase"
    - "Pest-bootstrap: uses(TestCase::class)->in(__DIR__) gevolgd door beforeEach(Cache::flush) — voorkomt cross-test cache-poisoning in singleton-array-cache van Testbench-process"
    - "ArchTest met pest-plugin-arch — 2 unit-style 'arch'-rules zonder helper-class, één voor debug-functies, één voor strict_types op de hele namespace"
    - "PackageSmokeTest gebruikt Pest higher-order proxy (->and->...) voor multi-property asserts in 1 expectation; geen ->secret()-calls (W-6) — credential-asserts via public typed property (->apiKey) of via ->fingerprint()"
    - "FakeMollieCredentialResolver variadic ctor + 3 static factories — withApiKey/withOAuth voor single-credential, sequence voor multi-tenant cycling. sequence detecteert plain-list vs FQCN-keyed shortcut-map dynamisch in één call"

key-files:
  created:
    - "packages/mollie-api/tests/TestCase.php"
    - "packages/mollie-api/tests/Pest.php"
    - "packages/mollie-api/tests/Support/FakeMollieCredentialResolver.php"
    - "packages/mollie-api/tests/ArchTest.php"
    - "packages/mollie-api/tests/PackageSmokeTest.php"
  modified: []

key-decisions:
  - "API-key-fixtures gebruiken ≥30-char keys (zoals 'test_smokeAAAAAAAAAAAAAAAAAAAAAAAAA') want mollie/mollie-api-php v3.11's TokenValidator::isApiKey() eist een minimum-lengte. Onze eigen MollieApiKeyCredentials valideert alleen op prefix — fail-fast bij client-instantiatie, niet bij Data-construction. Rule-1 deviation gedocumenteerd hieronder"
  - "FakeMollieCredentialResolver::sequence accepteert dual-vorm in één signature (plain-list óf FQCN-keyed shortcut-map) — bewuste DX-keuze voor plan 02-06's multi-tenant key-swap test. Detectie op is_string(\$key) && is_array(\$value)"
  - "Pest.php's Cache::flush() hook in beforeEach is preventief — er is nog geen Mollie-cache-laag in 02-05, maar het mirror-pattern uit Snelstart-SDK is goedkoop en sluit een hele klasse cross-test-pollutie uit zodra config-cache / IdempotencyKeyGenerator-cache in latere plans landt"
  - "PackageSmokeTest gebruikt expect(\$mollie->credentials())->toBe... via assertion-chains ipv aparte it()-blocks — houdt het aantal SP-boots minimaal (Testbench bootcost ~150ms/test); 6 tests in PackageSmokeTest dekken 11 distinct asserts"
  - "Geen Unit/MollieServiceProviderTest of Unit/MollieTest aangemaakt in 02-05 — die tests vallen in scope van plan 02-06 (type-discriminator + env-guard + idempotency-flows). 02-05 levert alleen de bootstrap + smoke-laag"

patterns-established:
  - "SDK-test-infrastructuur-template: TestCase + Pest.php (Cache-flush hook) + ArchTest + PackageSmokeTest + Support/Fake-resolver. Toekomstige Emeq-SDKs (Moneybird/Ibanity/Exact) kunnen deze 5-file template kopiëren door alleen namespaces en SP-class te swappen"
  - "Test-double met variadic ctor + cycling sequence — schaalt van single-tenant (withApiKey) naar multi-tenant (sequence) zonder dat consumer-tests een eigen resolver-implementatie hoeven schrijven"
  - "PackageSmokeTest dekt de 6 outer-layer-invariants: SP registers, config publishes, missing-resolver-guard, facade-target resolves, facade-root resolves, downstream client is non-singleton. Elke nieuwe SP-binding moet hier een it()-block toevoegen"

requirements-completed: []  # MOLL-01 staat in 02-04's frontmatter; 02-05 is enabler voor 02-06's coverage-validatie

# Metrics
duration: 13min
completed: 2026-05-14
---

# Phase 02 Plan 05: Pest-Testinfrastructuur + Smoke-Suite Summary

**Outer-layer test-foundation voor emeq/mollie-api: Testbench TestCase + Pest bootstrap + ArchTest + PackageSmokeTest + FakeMollieCredentialResolver. Pest draait 22 tests groen (16 PackageSmoke+Arch+Data-tests via filter, 22 totaal incluis 02-02's Data-tests) in <1s.**

## Performance

- **Duration:** ~13 min (incl. één Rule-1 deviation cycle voor TokenValidator-min-length)
- **Started:** 2026-05-14T12:02:00Z
- **Completed:** 2026-05-14T12:15:38Z
- **Tasks:** 2 atomair gecommit
- **Files created:** 5
- **Files modified:** 0

## Accomplishments

- **Testbench-bootstrap geland:** `tests/TestCase.php` extends `Orchestra\Testbench\TestCase`, registreert MollieServiceProvider via `getPackageProviders()` — alle downstream Pest-tests krijgen automatisch een gebooted Laravel-applicatie met de SDK-provider actief
- **Pest hermetic-cache hook:** `tests/Pest.php` flusht Cache voor elke test, voorkomt cross-test pollutie zodra latere plans cache-lagen (config-cache, IdempotencyKeyGenerator-cache) toevoegen
- **ArchTest dwingt invariants af:** debug-functies (`dd`, `dump`, `ray`, `var_dump`) niet toegestaan in productie-code; `strict_types=1` verplicht op de hele `Emeq\MollieApi` namespace — gemechaniseerde policy ipv code-review-discipline
- **PackageSmokeTest dekt 6 outer-layer-invariants:** (1) SP registratie, (2) config-defaults uit `config/mollie.php` (enforce_environment=false, http.timeout=30, idempotency.generator=null), (3) MissingCredentialResolverException-guard bij ontbrekende resolver-binding, (4) Mollie::class instantieert correct met gebonden resolver + credentials() returnt MollieApiKeyCredentials, (5) Mollie facade-root resolves via container, (6) MollieApiClient binding is non-singleton (twee opeenvolgende resolves geven distinct instances)
- **FakeMollieCredentialResolver levert 3 constructie-paden:** `withApiKey('test_xxx')` voor single-API-key, `withOAuth('access_xxx', expiresAt: …)` voor OAuth, en `sequence([…])` voor multi-tenant cycling — sequence accepteert plain-list én FQCN-keyed shortcut-map vorm, beide in dezelfde call
- **Volledige Pest-suite groen:** 22 tests / 55 assertions in 0.56s. Filter `--filter='PackageSmoke|Arch'` levert 8 tests / 19 assertions

## Task Commits

Elke task atomair gecommit in de mollie-api sub-repo op branch `feat/foundation`:

1. **Task 1: Testbench TestCase + Pest bootstrap + FakeMollieCredentialResolver** — `a86898a` (test)
2. **Task 2: ArchTest + PackageSmokeTest + groene Pest-suite** — `4b11258` (test)

Geen Hub-worktree-commit van plan-artefacten in deze run — orchestrator commit SUMMARY/STATE/ROADMAP.

## Files Created/Modified

- `packages/mollie-api/tests/TestCase.php` — `Orchestra\Testbench\TestCase` subclass. `getEnvironmentSetUp()` zet `database.default = testing`, `getPackageProviders()` returnt `[MollieServiceProvider::class]`. Namespace `Emeq\MollieApi\Tests` (matcht autoload-dev mapping in composer.json).
- `packages/mollie-api/tests/Pest.php` — Pest config. `uses(TestCase::class)->in(__DIR__)` koppelt TestCase aan alle test-files in `tests/`. Aparte `uses()->beforeEach(fn () => Cache::flush())->in(__DIR__)` hook flusht cache per test.
- `packages/mollie-api/tests/Support/FakeMollieCredentialResolver.php` — `final class` die `MollieCredentialResolver` implementeert. Variadic ctor (`MollieCredentials ...$credentials`) + drie static factories: `withApiKey`, `withOAuth`, `sequence`. `sequence` detecteert plain-list vs FQCN-keyed shortcut-map. `resolve()` cycled de sequence via modulo-index.
- `packages/mollie-api/tests/ArchTest.php` — 2 pest-arch-rules: (a) `expect(['dd', 'dump', 'ray', 'var_dump'])->each->not->toBeUsed()`, (b) `expect('Emeq\MollieApi')->toUseStrictTypes()`.
- `packages/mollie-api/tests/PackageSmokeTest.php` — 6 `it()`-blocks: registers SP, publishes config, throws MissingCredentialResolverException, resolves Mollie::class met gebonden resolver, resolves Mollie facade-root, MollieApiClient non-singleton. Gebruikt `FakeMollieCredentialResolver::withApiKey($apiKey)` met ≥30-char keys voor TokenValidator-compat.

## Decisions Made

- **≥30-char fixture keys** — `mollie/mollie-api-php` v3.11's `TokenValidator::isApiKey()` eist minimum-lengte van 30 chars. Onze `MollieApiKeyCredentials` Data-class valideert alleen op prefix (`test_`/`live_`). Resultaat: korte keys passeren Data-construction maar gooien `InvalidAuthenticationException` bij `setApiKey()` in `Mollie::client()`. Fixtures verlengd naar 35 chars (`test_smokeAAAAAAAAAAAAAAAAAAAAAAAAA` etc.) zodat client-instantiatie-test ook groen draait. Geen wijziging aan productie-code; deze afspraak hoort niet in de Data-class — Mollie's lib mag voor zichzelf valideren en wij vertrouwen die laag.
- **sequence-factory met dual-vorm** — plan-action-block specificeert beide vormen (plain-list + FQCN-keyed shortcut-map). Detectie via `is_string($key) && is_array($value)` is goedkoop en geeft consumer-tests in 02-06 een ergonomische API: `sequence([MollieApiKeyCredentials::class => ['test_a','test_b']])` levert kortere test-setup dan twee `new MollieApiKeyCredentials(...)` instanties.
- **Cache::flush beforeEach hook is preventief** — 02-05 heeft geen cache-laag in scope, maar mirror van Snelstart-pattern is gratis en sluit een hele klasse problemen uit zodra config-cache / IdempotencyKeyGenerator-cache in latere plans landt.
- **PackageSmokeTest blijft op 6 tests** — alternatieven (1 it()-block per assertion, of een data-provider) zouden de Testbench-bootcost (~150ms/test) verdubbelen of de assertion-context verliezen. Higher-order `->and->` proxy in expectations laat 1 test 4-5 distinct properties asserten zonder reboot.
- **Geen MollieServiceProviderTest / MollieTest in 02-05** — die tests vallen onder 02-06 (type-discriminator + env-guard + idempotency dual-path). 02-05 levert alleen de bootstrap + smoke-laag. Drempel ≥10 wordt ruim gehaald (22).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Korte fixture-API-keys faalden in MollieApiClient::setApiKey()**

- **Found during:** Task 2 (eerste Pest-run)
- **Issue:** `mollie/mollie-api-php`'s `TokenValidator::isApiKey()` (vendor/.../Http/Auth/ApiKeyAuthenticator.php:15) gooit `InvalidAuthenticationException` met de message "An API key must start with 'test_' or 'live_' and must be at least 30 characters long" voor onze testkeys `test_smoke` (10 chars), `test_facade` (11) en `test_per_resolve` (16). Onze eigen `MollieApiKeyCredentials` valideert alleen op prefix; de underlying Mollie-lib heeft strengere validatie. Test `binds MollieApiClient as a non-singleton` faalde omdat de fresh-client-instantiatie via `Mollie::client()` deze validatie triggert.
- **Fix:** API-key-fixtures verlengd naar ≥30 chars: `test_smokeAAAAAAAAAAAAAAAAAAAAAAAAA` (35 chars), `test_facadeAAAAAAAAAAAAAAAAAAAAAAAAA` (35), `test_perresolveAAAAAAAAAAAAAAAAAAAAA` (35). Test-assertion in test 4 (`resolves the main Mollie facade-target`) gebruikt nu een lokale `$apiKey` variabele zodat de assertion-string mee schaalt met de fixture.
- **Files modified:** `packages/mollie-api/tests/PackageSmokeTest.php`
- **Commit:** `4b11258` (rolde de fix mee in de Task 2 commit ipv een aparte fix-commit, want de file was nog niet eerder gecommit)

### Pint cosmetic rewrites

- Pint paste `blank_line_before_statement` toe op `tests/Support/FakeMollieCredentialResolver.php` (extra blank-line voor de inner `foreach` in `sequence()`)
- Pint paste `concat_space` toe op `tests/PackageSmokeTest.php` (spaces rondom `.` in `'No ' . MollieCredentialResolver::class . ' binding found'`)
- Beide cosmetic-only, geen semantische impact.

## Issues Encountered

- **ugrep-alias struikelde over `->not->toBe` in grep-pattern** — lokale shell heeft `grep` als alias naar `ugrep`, die `->` als optie probeert te parsen. Omzeild via `grep -F -- 'patroon'` (fixed-string + double-dash). Geen impact op output, alleen werkwijze.
- **TokenValidator min-length is undocumented in plan-action-block** — de plan-fixtures (`test_smoke`, `test_facade`, `test_per_resolve`) waren te kort voor Mollie's lib-validatie. Eenvoudige Rule-1 fix, maar wijst op een toekomstige plan-hint: documenteer de TokenValidator-constraint in CONTEXT.md voor downstream plans (02-06, 02-07) om dezelfde discovery-cyclus te vermijden.

## Verification Summary

Alle plan-`<verification>`-clausules + `<success_criteria>`:

- `namespace Emeq\MollieApi\Tests` in `tests/TestCase.php` → PASS (1 hit)
- `MollieServiceProvider::class` in `tests/TestCase.php` → PASS (1 hit)
- `uses(TestCase::class)` in `tests/Pest.php` → PASS (1 hit)
- `Cache::flush` in `tests/Pest.php` → PASS (1 hit)
- `implements MollieCredentialResolver` in `tests/Support/FakeMollieCredentialResolver.php` → PASS (1 hit)
- `withApiKey` / `withOAuth` / `sequence` in `FakeMollieCredentialResolver.php` → PASS (≥3 hits per factory)
- `MollieApiKeyCredentials::class` in `FakeMollieCredentialResolver.php` → PASS (3 hits — docblock + sequence-match-arm)
- 2 `arch(...)` rules in `tests/ArchTest.php` → PASS
- 6 `it(...)` blocks in `tests/PackageSmokeTest.php` → PASS
- Geen `->secret()`-aanroepen in `tests/PackageSmokeTest.php` (W-6) → PASS
- `php -l` op alle 5 test-files → PASS
- `./vendor/bin/pest --filter='PackageSmoke|Arch'` exit 0 → PASS (8 tests / 19 assertions)
- Volledige `./vendor/bin/pest` exit 0 → PASS (22 tests / 55 assertions)

## Next Phase Readiness

- **02-06 ready:** type-discriminator/env-guard/idempotency-tests kunnen `Mollie::class` direct via `new Mollie(...)` instantiëren met `FakeMollieCredentialResolver::withApiKey()` of `::withOAuth()`. Multi-tenant key-swap test kan `::sequence([...])` gebruiken (B-6). Cache wordt vanzelf geflusht. Drempel ≥10 tests in 02-06 vertrekt vanaf de huidige 22-tests-basis.
- **02-07 ready:** PHPStan + extra arch-rules analyseren nu een tree met bekende patronen (`final class` + variadic ctor + readonly + match-true). De huidige ArchTest dekt 2 invariants (no-debug + strict_types); 02-07 kan extra rules toevoegen zonder bootstrap-werk.
- **Geen blockers** voor wave 5+ (plans 06+).

## Self-Check: PASSED

Files exist (verified via Read tool eerder in sessie):
- `/Users/yusufkaracaburun/Sites/localhost/emeq-hub-phase2/packages/mollie-api/tests/TestCase.php` → FOUND
- `/Users/yusufkaracaburun/Sites/localhost/emeq-hub-phase2/packages/mollie-api/tests/Pest.php` → FOUND
- `/Users/yusufkaracaburun/Sites/localhost/emeq-hub-phase2/packages/mollie-api/tests/Support/FakeMollieCredentialResolver.php` → FOUND
- `/Users/yusufkaracaburun/Sites/localhost/emeq-hub-phase2/packages/mollie-api/tests/ArchTest.php` → FOUND
- `/Users/yusufkaracaburun/Sites/localhost/emeq-hub-phase2/packages/mollie-api/tests/PackageSmokeTest.php` → FOUND

Sub-repo commits exist (via `git log --oneline` in mollie-api):
- `a86898a test(02-05): Testbench TestCase + Pest bootstrap + FakeMollieCredentialResolver` → FOUND
- `4b11258 test(02-05): ArchTest + PackageSmokeTest groene Pest-suite` → FOUND

---
*Phase: 02-emeq-mollie-api-foundation*
*Completed: 2026-05-14*
