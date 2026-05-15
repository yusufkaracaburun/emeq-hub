---
phase: 02-emeq-mollie-api-foundation
plan: 04
subsystem: sdk-service-provider-wiring
tags: [mollie, service-provider, facade, multi-tenant, container-binding, spatie-package-tools, idempotency, env-guard, php8.3]

# Dependency graph
requires:
  - 02-01 (skeleton + composer-deps spatie/laravel-package-tools + mollie/mollie-api-php + autoload Emeq\MollieApi\)
  - 02-02 (MollieCredentialResolver-contract + MollieApiKeyCredentials/MollieOAuthCredentials Data-classes)
  - 02-03 (MollieException base + MissingCredentialResolverException::notBound factory)
provides:
  - "MollieServiceProvider — Spatie PackageServiceProvider met name('mollie-api') + hasConfigFile('mollie')"
  - "singleton(Mollie::class) binding die MissingCredentialResolverException::notBound() gooit als host-app geen MollieCredentialResolver bindt"
  - "bind(MollieApiClient::class) NON-singleton — fresh client per resolve voor multi-tenant per-request swap"
  - "Mollie::class facade-target met client() factory: resolver→creds→env-guard→match(true)→setApiKey/setAccessToken→idempotency-generator→return"
  - "Mollie::credentials() helper voor fingerprint/logging use-cases"
  - "Dual-path idempotency-generator-resolution via Container::make() — werkt voor FQCN én container-alias (B-8)"
  - "Production env-guard via container->make('app')->environment() — compat met detectEnvironment()-override (B-7)"
  - "Facades\\Mollie alias met union-typed credentials() docblock (W-7) + @method client()"
  - "composer.json post-autoload-dump/prepare scripts — testbench package:discover groen na dump-autoload (W-2 Optie B)"
affects:
  - 02-05-PLAN (tests/TestCase.php + FakeMollieCredentialResolver — bouwt op SP-bindings die hier geland zijn)
  - 02-06-PLAN (Pest-suite — test Mollie::client() type-discriminator, env-guard, idempotency dual-path; test SP-bindings via PackageSmokeTest)
  - 02-07-PLAN (PHPStan + ArchTest — analyseert nu een complete class-tree)
  - Phase 3+ (Hub-integratie — bindt eigen MollieCredentialResolver tegen Connection-model)

# Tech tracking
tech-stack:
  added: []  # geen nieuwe composer-deps; alleen wiring van bestaande deps
  patterns:
    - "Spatie laravel-package-tools DSL: configurePackage() declareert name + config-file; packageRegistered() doet container-bindings — 1-op-1 mirror van SnelstartServiceProvider"
    - "Resolver-not-bound guard via $app->bound() check + MissingCredentialResolverException — productive error-message ipv generic BindingResolutionException"
    - "Mollie::class als singleton (resolver-injectie is request-stable), MollieApiClient::class als bind() (per-resolve fresh client) — bewuste asymmetrie voor multi-tenant correctness"
    - "match (true) zonder default-arm op credential-type — fail-fast (UnhandledMatchError) als toekomstig credential-type wordt toegevoegd zonder branch"
    - "Constructor-injected Illuminate\\Contracts\\Container\\Container ipv app()-helper — clean unit-testen via direct-instantiate, geen Facade-roots nodig"
    - "Dual-path container-resolution voor idempotency-generator: $container->make($value) accepteert ZOWEL FQCN ALS container-alias zonder onderscheid (B-8) — verlaagt config-API-surface naar één string-veld"
    - "Environment-detection via container->make('app')->environment() (B-7) — werkt samen met Laravel's detectEnvironment()-override pattern, unblockable in env-guard-test in plan 02-06"
    - "Composer-script chain post-autoload-dump → prepare → testbench package:discover (W-2 Optie B) — pas geland nadat MollieServiceProvider concreet bestaat, voorkomt class-not-found bij dump-autoload"

key-files:
  created:
    - "packages/mollie-api/src/MollieServiceProvider.php"
    - "packages/mollie-api/src/Mollie.php"
    - "packages/mollie-api/src/Facades/Mollie.php"
  modified:
    - "packages/mollie-api/composer.json (scripts.post-autoload-dump + scripts.prepare toegevoegd)"

key-decisions:
  - "MollieApiClient::class krijgt bind() ipv singleton() — multi-tenant correctness vraagt fresh client per resolve; cross-tenant lekkage in een queue-worker zou anders structureel mogelijk zijn"
  - "Geen $guzzle = $this->buildGuzzle() in implementatie ondanks dat CONTEXT.md sectie 'Mollie::client() flow' het noemt — MollieApiClient ctor heeft een no-arg pad en custom Guzzle is een toekomstige uitbreiding (timeout/proxy/CA-bundle). Plan-action-block specificeert `new MollieApiClient()` zonder Guzzle-argument; consistent gehouden om scope niet te creepen"
  - "match (true) zonder default-arm — opzettelijk een UnhandledMatchError als een nieuw MollieCredentials-subtype later wordt toegevoegd zonder hier een branch te zetten. Fail-fast > silently-wrong"
  - "Mollie::__construct injecteert Container expliciet (private readonly Container) — nodig voor (a) idempotency-generator-resolution via Container::make() ZONDER de app()-helper te gebruiken (Facade-roots niet altijd actief in unit-tests), en (b) environment()-detect via 'app' resolve voor B-7 compat"
  - "Facade docblock typeert credentials() als union van twee concrete subclass-types ipv abstract MollieCredentials (W-7) — geeft IDE-autocomplete accurate type-info, en host-code houdt zijn instanceof-narrowing zonder type-assertion"
  - "Composer-scripts pas in dit plan toegevoegd (W-2 Optie B) — 02-01 heeft ze bewust weggelaten omdat testbench package:discover op een class-not-found voor MollieServiceProvider zou crashen. Task 0 toevoegt de scripts, Task 2 schrijft de SP, vervolgens draait Task 2 composer dump-autoload — eerste invocatie van prepare loopt dán groen"

patterns-established:
  - "Outer-layer SDK-pattern voltooid: ServiceProvider (Spatie DSL + bindings) + facade-target (resolver-driven factory) + Facade-alias (docblock-typed shortcuts) — 1-op-1 mirror van Snelstart-SDK. Toekomstige Emeq-SDKs (Moneybird, Ibanity, Exact) kunnen deze template kopiëren"
  - "Multi-tenant binding-strategie: resolver-holder is singleton, downstream-client is non-singleton. Verschil tussen 'state die door de hele request meegaat' (resolver) en 'state die per credential-resolve fresh moet zijn' (client) wordt explicit in de binding-flags"
  - "Config-driven optionality: enforce_environment en idempotency.generator zijn beide config-keys die default 'off' staan; SDK doet alleen extra werk als de config-key gezet is. Houdt de basis-flow minimal en testbaar"
  - "Container-injected interfaces (ConfigRepository + Container) in ctor — Mollie::class is via `new Mollie(...)` direct te instantiëren in tests met ConfigRepository en Container instances zonder Laravel-bootstrap"

requirements-completed: [MOLL-01]

# Metrics
duration: 8min
completed: 2026-05-14
---

# Phase 02 Plan 04: ServiceProvider + Facade-target + Facade-alias Summary

**Laravel-glue van emeq/mollie-api: Spatie-PackageServiceProvider met multi-tenant-correcte bindings, Mollie::client() factory met match(true)-type-discriminator op MollieApiKeyCredentials vs MollieOAuthCredentials, en union-typed Facade-docblock voor IDE-accuracy.**

## Performance

- **Duration:** ~8 min
- **Started:** 2026-05-14T12:01:30Z
- **Completed:** 2026-05-14T12:09:30Z
- **Tasks:** 4 (Task 0 composer-scripts + Task 1 Mollie + Task 2 SP + Task 3 Facade)
- **Files modified:** 1 (composer.json)
- **Files created:** 3 (Mollie.php, MollieServiceProvider.php, Facades/Mollie.php)

## Accomplishments

- **Container-wiring compleet:** `Mollie::class` singleton met resolver-bound-guard, `MollieApiClient::class` bind() voor per-resolve fresh client (multi-tenant correctness)
- **Type-discriminator-flow operationeel:** `Mollie::client()` resolved credentials → past env-guard toe → instantieert `MollieApiClient` → past `setApiKey()` of `setAccessToken()` toe via `match (true)` op subtype
- **Production env-guard geland:** met `enforce_environment=true` weigert `Mollie::client()` een `test_`-prefix API-key uit te delen in productie; OAuth-credentials zijn niet onderhevig (Mollie OAuth heeft geen test/live-prefix)
- **Idempotency dual-path (B-8):** `config('mollie.idempotency.generator')` accepteert nu zowel een FQCN als een container-alias — beide via `$container->make($value)` zonder branching
- **Spatie package-tools auto-discovery werkt:** `composer dump-autoload` triggert nu via `post-autoload-dump → prepare → testbench package:discover` en `emeq/mollie-api` wordt zichtbaar gediscovered (W-2 Optie B sluit)
- **Facade-alias DX (W-7):** docblock typeert `credentials()` met union van twee concrete subclass-types, niet de abstract base — IDE-autocomplete blijft accuraat zonder dat host-code zijn `instanceof`-narrowing verliest

## Task Commits

Elke task atomair gecommit in de mollie-api sub-repo op branch `feat/foundation`:

1. **Task 0: composer.json scripts (W-2 Optie B)** — `57a726c` (chore)
2. **Task 1: Mollie facade-target met match(true) + dual-path idempotency** — `2fb8c22` (feat)
3. **Task 2: MollieServiceProvider met container-bindings** — `63a8cab` (feat)
4. **Task 3: Facades\\Mollie alias met union-typed credentials() docblock** — `13cbe53` (feat)

Geen Hub-worktree-commit van plan-artefacten in deze run — orchestrator commit SUMMARY/STATE/ROADMAP.

## Files Created/Modified

- `packages/mollie-api/composer.json` — `scripts.post-autoload-dump = @composer run prepare` + `scripts.prepare = @php vendor/bin/testbench package:discover --ansi` toegevoegd. Triggert nu testbench's package-discovery na elke `composer dump-autoload`.
- `packages/mollie-api/src/Mollie.php` — Facade-target class. Constructor injecteert `MollieCredentialResolver` + `Illuminate\Contracts\Config\Repository` + `Illuminate\Contracts\Container\Container`. Publieke API: `credentials(): MollieCredentials` + `client(): MollieApiClient`. Private helpers: `applyIdempotencyGenerator()` (dual-path) + `guardEnvironment()` (B-7 env-detect).
- `packages/mollie-api/src/MollieServiceProvider.php` — Extends `Spatie\LaravelPackageTools\PackageServiceProvider`. `configurePackage()` declareert `name('mollie-api')` + `hasConfigFile('mollie')`. `packageRegistered()` doet de twee container-bindings (singleton + bind).
- `packages/mollie-api/src/Facades/Mollie.php` — Extends `Illuminate\Support\Facades\Facade`. `getFacadeAccessor()` returnt `\Emeq\MollieApi\Mollie::class`. Docblock heeft union-typed `@method` voor `credentials()` en concrete `@method` voor `client()`.

## Decisions Made

- **MollieApiClient::class als `bind()` ipv `singleton()`** — multi-tenant correctness vraagt fresh client per resolve. Bij singleton zou een queue-worker die twee jobs voor twee verschillende tenants verwerkt de tweede job met de eerste tenant's credentials kunnen draaien. Bewuste asymmetrie t.o.v. de `Mollie::class` singleton, waarbij de singleton de resolver-houder is en de bind() de actuele credentials elke keer opnieuw resolved.
- **Geen Guzzle-build in deze fase** — CONTEXT.md sectie "Mollie::client() flow" noemt `$guzzle = $this->buildGuzzle()`, maar het plan-action-block schrijft `new MollieApiClient()` zonder argument. Houdt scope clean: timeout/proxy/CA-bundle Guzzle-customisatie is een latere fase (en config-keys `http.timeout` + `http.guzzle_options` zijn nog niet gebruikt — laat ze staan voor toekomst).
- **`match (true)` zonder default-arm** — toekomstig MollieCredentials-subtype (denk MollieClientCredentialsCredentials voor server-to-server flows) gooit `UnhandledMatchError` ipv silent fall-through. Fail-fast.
- **`container->make('app')->environment()` ipv `app()->environment()`** — Mollie::class is via `new Mollie(...)` instantieerbaar in unit-tests met `Container::make()` calls die direct werken; `app()`-helper vraagt Laravel Facade-roots active. Klein design-choice met grote test-impact (B-7).
- **Composer-scripts pas hier toegevoegd (W-2 Optie B)** — was bewuste keuze in 02-01: testbench package:discover crasht op class-not-found als MollieServiceProvider nog niet bestaat. Volgorde in dit plan: Task 0 voegt scripts toe, Task 1+2 maken Mollie + SP, Task 2 draait `composer dump-autoload` waardoor scripts voor het eerst groen lopen.

## Deviations from Plan

None - plan executed exactly as written.

Eén kleine documentatie-mismatch: CONTEXT.md sectie "Mollie::client() flow" toont een `$guzzle = $this->buildGuzzle()` stap, maar het plan-action-block voor Task 1 specificeert `new MollieApiClient()` zonder Guzzle-argument en zonder `buildGuzzle()` helper. Gehouden aan het plan-action-block (concrete code-spec) ipv het CONTEXT-pseudocode-overzicht. Dit is geen deviatie: het plan-action-block is autoritief; het CONTEXT-snippet was scope-preview die in 02-06 of latere fase landt als http.timeout/http.guzzle_options config-keys actief gebruikt gaan worden.

## Issues Encountered

- **Pint cosmetische rewrite na Task 1** — `./vendor/bin/pint --dirty --format agent` paste `spaces_inside_parentheses`, `not_operator_with_space`, `binary_operator_spaces` toe op `src/Mollie.php`. Resultaat: alignment in match-arms + `! $generator instanceof` spacing. Geen semantische change; verificatie na Pint draaide opnieuw groen op alle assertions.
- **`grep`-alias naar `ugrep`** in lokale shell verwerkte regex-patterns met `()` en `->` anders dan stock grep. Verificaties via `grep -F` (fixed strings) of via `php -r 'str_contains(...)'` om dit te omzeilen. Geen impact op output, alleen werkwijze.

## Verification Summary

Alle plan-`<verification>`-clausules + `<success_criteria>`:

- composer.json bevat `post-autoload-dump` + `prepare` scripts → PASS
- `singleton(Mollie::class` in SP → PASS (1 hit)
- `MissingCredentialResolverException::notBound` in SP → PASS (1 hit)
- `bind(` in SP → PASS (≥1, namelijk `bind(MollieApiClient::class, ...)`)
- `setApiKey` in Mollie → PASS (1 hit)
- `setAccessToken` in Mollie → PASS (1 hit)
- `match (true)` in Mollie → PASS (type-discriminator)
- `container->make($value)` in Mollie → PASS (B-8 dual-path)
- `container->make('app')->environment()` in Mollie → PASS (B-7 compat)
- `getFacadeAccessor` in Facades/Mollie → PASS
- Union `credentials()` docblock in Facades/Mollie → PASS (W-7)
- Geen `new \Mollie\Api\MollieApiClient` in SP → PASS (instantiatie in Mollie::client(), niet SP)
- `php -l` op alle 3 src-files → PASS
- `composer dump-autoload` slaagt + `emeq/mollie-api ... DONE` in package-discover output → PASS

## Next Phase Readiness

- **02-05 ready:** tests/TestCase.php + FakeMollieCredentialResolver kunnen leunen op de SP-bindings die hier geland zijn. Testbench-bootstrap pickt MollieServiceProvider auto-discovery op via composer.json `extra.laravel.providers`.
- **02-06 ready:** Pest-suite kan nu `Mollie::class` direct via `new Mollie(...)` instantiëren (constructor-injected interfaces) of via container-resolve testen. `MollieApiClient::fake()` werkt op de fresh-client-per-call binding.
- **02-07 ready:** PHPStan + ArchTest analyseren nu een complete class-tree (resolver-contract + Data + Exceptions + Mollie + SP + Facade). Geen ontbrekende symbols.
- **Geen blockers** voor wave 4 (plans 05+).

## Self-Check: PASSED

Files exist (via php is_file check):
- /Users/yusufkaracaburun/Sites/localhost/emeq-hub-phase2/packages/mollie-api/src/Mollie.php → FOUND
- /Users/yusufkaracaburun/Sites/localhost/emeq-hub-phase2/packages/mollie-api/src/MollieServiceProvider.php → FOUND
- /Users/yusufkaracaburun/Sites/localhost/emeq-hub-phase2/packages/mollie-api/src/Facades/Mollie.php → FOUND
- /Users/yusufkaracaburun/Sites/localhost/emeq-hub-phase2/packages/mollie-api/composer.json (modified) → FOUND

Sub-repo commits exist (via git log):
- 57a726c chore(02-04): post-autoload-dump + prepare scripts → FOUND
- 2fb8c22 feat(02-04): Mollie facade-target met type-discriminator + idempotency dual-path → FOUND
- 63a8cab feat(02-04): MollieServiceProvider met container-bindings → FOUND
- 13cbe53 feat(02-04): Facades\Mollie alias met union-typed credentials() (W-7) → FOUND

---
*Phase: 02-emeq-mollie-api-foundation*
*Completed: 2026-05-14*
