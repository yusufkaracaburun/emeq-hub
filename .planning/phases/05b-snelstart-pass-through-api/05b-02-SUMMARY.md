---
phase: 05b-snelstart-pass-through-api
plan: 02
subsystem: api
tags:
  - laravel
  - snelstart
  - credential-resolution
  - sdk-binding
  - phpunit
  - tdd

# Dependency graph
requires:
  - phase: 03-hub-skeleton
    provides: "Connection-model met encrypted casts voor client_key/subscription_key/access_token/refresh_token + ConnectionFactory::forSnelstart/forMollie-states"
  - phase: 01-snelstart-sdk-finalize
    provides: "Emeq\\SnelstartApi\\Contracts\\SnelstartCredentialResolver-interface en SnelstartCredentials-DTO met fingerprint() (full sha256)"
provides:
  - "App\\Services\\Snelstart\\HubSnelstartCredentialResolver — final readonly class die per-Connection de Snelstart-credentials decryptert en als SnelstartCredentials-DTO uitlevert"
  - "Bewijs (4 tests, 8 assertions) dat decryption-pad, contract-conformance, fingerprint-determinisme en missing-credential-rejection werken"
  - "ServiceProvider-vrije resolver — kan in Plan 05's ResolveSnelstartAccount-middleware per-request gebonden worden via app()->instance(...) zonder globale state"
affects:
  - 05b-03 (PassThroughCall audit-model — independent)
  - 05b-05 (ResolveSnelstartAccount-middleware + PassThroughController — binnen deze middleware wordt deze resolver per-request gebonden)
  - 05b-04 (Provisioning-endpoints — independent)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Thin per-Connection service-class als SDK-contract-implementatie — geen ServiceProvider-binding, geen container-singletons, geen multi-Account-state"
    - "Test-namespace Tests\\Feature\\Services voor service-laag-bewijs (naast Tests\\Feature\\Api voor HTTP-feature-tests)"

key-files:
  created:
    - "app/Services/Snelstart/HubSnelstartCredentialResolver.php"
    - "tests/Feature/Services/HubSnelstartCredentialResolverTest.php"
  modified: []

key-decisions:
  - "Geen `declare(strict_types=1)` in de service-class — Hub-conventie (geen enkel `app/`-bestand gebruikt het); plan-action-voorbeeld bevatte het maar match bestaande style heeft voorrang (`.ai/rules/engineering.md` — conformance > smaak)"
  - "Geen ServiceProvider-binding in dit plan — die hoort in Plan 05's ResolveSnelstartAccount-middleware zodat de binding per-request scoped is en niet globaal lekt tussen Connections"
  - "Missing-credential-pad bewust gedelegeerd aan SnelstartCredentials DTO-constructor (InvalidArgumentException op lege strings); resolver vertrouwt zijn input — single-responsibility-principe (T-05b-07 disposition `accept` in threat model)"

patterns-established:
  - "Per-Connection credential-resolver = `final readonly class`, één `Connection` in constructor, één `resolve(): <CredentialDto>` methode. Future providers (Mollie Connect in Phase 4) krijgen analoge `Hub<Provider>CredentialResolver`-class."
  - "Cleartext-DTO retournering: resolver decryptert via Eloquent-casts en returnt direct de SDK-DTO. Geen tussenliggende cleartext-string-properties, geen logging, geen `__toString`. T-05b-05 mitigation."

requirements-completed:
  - HUB-05  # alleen voorbereiding van SC-3 ("GET /v1/snelstart/echo/ping proxied → bewijst resolver-binding") — volledige SC-bewijs landt in Plan 05b-05 met middleware + route

# Metrics
duration: ~6 min
completed: 2026-05-14
---

# Phase 05b Plan 02: HubSnelstartCredentialResolver Summary

**Thin per-Connection credential-resolver die de Snelstart SDK-interface implementeert en uit een Hub-`Connection` decrypted `client_key`/`subscription_key`/`subscription_id` levert — klaar voor per-request middleware-binding in Plan 05.**

## Performance

- **Duration:** ~6 min
- **Started:** 2026-05-14T16:10:00Z
- **Completed:** 2026-05-14T16:16:00Z
- **Tasks:** 1 (TDD: RED + GREEN, geen REFACTOR nodig)
- **Files created:** 2 (1 service-class + 1 test-class)
- **Files modified:** 0

## Accomplishments

- `App\Services\Snelstart\HubSnelstartCredentialResolver` als `final readonly class` die `Emeq\SnelstartApi\Contracts\SnelstartCredentialResolver` implementeert en `SnelstartCredentials` produceert uit een Hub-`Connection`
- 4 PHPUnit-tests (8 assertions) bewijzen happy-path-decryption, SDK-contract-conformance, fingerprint-determinisme over twee `resolve()`-calls en missing-credential-rejection via DTO-validatie
- Volledige Hub-suite blijft groen: **36 passed / 1 incomplete / 0 failed** (de incomplete is de bestaande `SanctumAbilityTest`-placeholder voor Plan 05)
- Geen wijziging onder `packages/snelstart-api/` (SDK-grens-invariant respected); geen wijziging in `app/Providers/AppServiceProvider.php` (per-request binding hoort in middleware, niet globaal)

## Task Commits

TDD-cyclus voor Task 1:

1. **RED — failing test** — `32e2b31` (`test(05b-02): add failing test for HubSnelstartCredentialResolver`) — 4 tests falen met "Class not found"
2. **GREEN — implementation** — `7a5ef7b` (`feat(05b-02): implement HubSnelstartCredentialResolver`) — 4 tests groen (8 assertions)

REFACTOR-fase overgeslagen: de implementatie is minimaal (één `resolve()` met direct DTO-construction, geen interne state), en pint vond geen drift. Geen refactor-commit is correcter dan een lege diff.

**Plan metadata commit:** volgt na deze SUMMARY-write (zie git-log na completion).

## Files Created/Modified

- `app/Services/Snelstart/HubSnelstartCredentialResolver.php` — final readonly class, constructor neemt `Connection`, `resolve()` bouwt `new SnelstartCredentials(clientKey, subscriptionKey, subscriptionId)` met cast van potentieel-null `client_key`/`subscription_key` naar string (DTO valideert non-empty)
- `tests/Feature/Services/HubSnelstartCredentialResolverTest.php` — 4 tests in `Tests\Feature\Services`-namespace (nieuwe sub-namespace voor service-laag-bewijs); gebruikt `RefreshDatabase` + `Connection::factory()->forSnelstart()` / `->forMollie()` states

## Decisions Made

1. **Geen `declare(strict_types=1)` in `HubSnelstartCredentialResolver.php`** — Hub-codebase gebruikt geen `strict_types`-declaratie in `app/` (`grep -rl "declare(strict_types" app` levert niets op). Plan-action-voorbeeld toonde wél `declare(strict_types=1)` maar `.ai/rules/engineering.md` ("Match bestaande style — ook als je 'm niet mooi vindt. Conformance > smaak.") prevaleerde. SDK-packages volgen wel `strict_types`; dat is een aparte conventie binnen `packages/snelstart-api/`.
2. **Geen ServiceProvider-binding hier** — plan was expliciet dat binding in Plan 05b-05's middleware hoort. Daar bindt `ResolveSnelstartAccount` per-request via `app()->instance(SnelstartCredentialResolver::class, new HubSnelstartCredentialResolver($connection))`. Geen `singleton()` in een ServiceProvider zou de invariant uit het threat-model (T-05b-06) breken.
3. **Missing-credential-pad gedelegeerd aan DTO** — Test 4 (`forMollie()` Connection → `resolve()`) gooit `InvalidArgumentException` uit `SnelstartCredentials::__construct` omdat `client_key`/`subscription_key` null zijn en `(string) null === ''`. De resolver zelf checkt niets — single-responsibility, en de middleware in Plan 05 zorgt dat niet-Snelstart Connections deze resolver nooit bereiken.

## Deviations from Plan

**None van betekenis** — plan executed exactly als geschreven met één style-aanpassing:

### Style-conformance (geen Rule-deviatie, geen extra werk)

- **Wat:** `declare(strict_types=1)` weggelaten uit `app/Services/Snelstart/HubSnelstartCredentialResolver.php`
- **Reden:** Hub-conventie (`grep -rl "declare(strict_types" app` = 0 matches in `app/`). Volgt `.ai/rules/engineering.md` regel "Match bestaande style".
- **Impact op acceptance:** geen — alle 7 acceptance-grep-checks blijven 1/1/1/1/≥4/0/0 (geen criterion grepte naar `declare`)

**Total deviations:** 0 auto-fixed
**Impact on plan:** Zero scope-drift. Implementatie is 30 regels code (resolver) + 64 regels test, exact wat plan voorzag.

## Issues Encountered

- **Worktree-bootstrap:** deze parallel-executor worktree had geen `vendor/` of `packages/` (gitignored). Workaround: symlinken naar de hoofdwerk-tree (`/Users/yusufkaracaburun/Sites/localhost/emeq-hub/vendor` en `/...packages`) + `cp` van `.env`. Dit is bootstrapping en raakt geen commits. Daarna draaide `php artisan test` direct.
- **Composer-autoload-cache:** na het schrijven van de implementation-file faalden de tests nog 1 keer met "Class not found" omdat de composer class-map cache op de gedeelde vendor-symlink stond. `composer dump-autoload` loste het op — geen file-change in deze repo (`git status` was schoon).

## Self-Check

- [x] `app/Services/Snelstart/HubSnelstartCredentialResolver.php` bestaat (FOUND)
- [x] `tests/Feature/Services/HubSnelstartCredentialResolverTest.php` bestaat (FOUND)
- [x] Commit `32e2b31` (RED test) bestaat in `git log --all` (FOUND)
- [x] Commit `7a5ef7b` (GREEN feat) bestaat in `git log --all` (FOUND)
- [x] Acceptance greps: implements=1, final readonly class=1, new SnelstartCredentials=1, resolve signature=1, test methods=4 (alle PASS)
- [x] `app/Providers/AppServiceProvider.php` ongewijzigd (PASS)
- [x] `packages/snelstart-api/**` ongewijzigd (PASS — symlink alleen, geen file-modifications)
- [x] `php artisan test --compact` = 36 passed / 1 incomplete / 0 failed (PASS)

## Self-Check: PASSED

## Next Phase Readiness

- **Plan 05b-05 (Wave 3) kan starten** zodra de andere Wave 1/2 plans landen. In `ResolveSnelstartAccount`-middleware schrijft die plan:
  ```php
  use App\Services\Snelstart\HubSnelstartCredentialResolver;
  use Emeq\SnelstartApi\Contracts\SnelstartCredentialResolver;

  $resolver = new HubSnelstartCredentialResolver($connection);
  app()->instance(SnelstartCredentialResolver::class, $resolver);
  ```
- **HUB-05 SC-3 ("GET /v1/snelstart/echo/ping proxied → bewijst resolver-binding")** — de resolver-component bestaat nu en is contract-conform. SC-3 wordt end-to-end bewezen wanneer de pass-through-route in Plan 05b-05 een echte Snelstart-call doet via deze resolver.
- **Geen blockers** voor parallelle Wave-1 plans (`05b-01` Account-form-request, `05b-03` PassThroughCall-model). Deze plans raken disjuncte files.

## TDD Gate Compliance

- **RED gate:** `32e2b31` (`test(05b-02): …`) committed met failende tests vóór implementation. PASS.
- **GREEN gate:** `7a5ef7b` (`feat(05b-02): …`) committed na implementatie met 4/4 tests groen. PASS.
- **REFACTOR gate:** overgeslagen (geen refactor nodig — implementatie was minimaal in eerste pass). Toegestaan per TDD-cycle (REFACTOR is optioneel).

---
*Phase: 05b-snelstart-pass-through-api*
*Plan: 02*
*Completed: 2026-05-14*
