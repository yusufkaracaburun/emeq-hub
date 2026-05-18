---
phase: 02-emeq-mollie-api-foundation
plan: 03
subsystem: sdk-exception-layer
tags: [mollie, exceptions, runtime-exception, security-no-secret-leak, snelstart-pattern, php8.3]

# Dependency graph
requires:
  - 02-01 (skeleton + autoload onder Emeq\MollieApi\)
  - 02-02 (MollieCredentialResolver-contract — FQCN referent in MissingCredentialResolverException-message)
provides:
  - "MollieException — package-base RuntimeException-subclass voor SDK-eigen wiring/config-errors"
  - "MissingCredentialResolverException::notBound() — static factory met FQCN-only message, geen secret-acceptatie"
affects:
  - 02-04-PLAN (MollieServiceProvider — gebruikt MissingCredentialResolverException::notBound() in singleton-binding van Mollie::class)
  - 02-05-PLAN (Mollie facade-target — gebruikt MollieException voor enforce_environment-guard wanneer test_-key in production draait)

# Tech tracking
tech-stack:
  added: []  # geen nieuwe composer-deps
  patterns:
    - "Package-base exception class (lege body) — uitbreidbaar voor toekomstige SDK-eigen runtime-errors, zónder zelf de HTTP-status-mapping van mollie/mollie-api-php te dupliceren"
    - "Static ::notBound() factory met FQCN-only message-shape — geen $secret-parameters, alleen contract-classname via ::class-resolution, voorkomt secret-leak via exception-trace"
    - "1-op-1 mirror van Snelstart-SDK exception-laag (SnelstartException + MissingCredentialResolverException) — consistent pattern over alle Emeq-SDKs"

key-files:
  created:
    - "packages/mollie-api/src/Exceptions/MollieException.php"
    - "packages/mollie-api/src/Exceptions/MissingCredentialResolverException.php"
  modified: []

key-decisions:
  - "MollieException extends RuntimeException (niet \\Exception) — runtime-categorie matcht SPL-semantiek voor errors die niet tijdens normale flow afgevangen hoeven worden; consistent met SnelstartException-pattern"
  - "Geen eigen HTTP-status-exception-hiërarchie (UnauthorizedException/NotFoundException/etc.) — host-apps catchen direct mollie/mollie-api-php's exceptions (ApiException, ValidationException, UnauthorizedException, NotFoundException, TooManyRequestsException, ServerException, ServiceUnavailableException). Beslist in 02-CONTEXT.md sectie 'Bewust NIET' — wrap-hiërarchie zou alleen rederiving zijn"
  - "::notBound() is parameter-loos — geen $secret-acceptatie in factory-signature, eliminieert de mogelijkheid dat een caller per ongeluk een raw apiKey of accessToken doorgeeft die dan in exception-message en stack-trace landt"
  - "Message bevat FQCN via ::class-resolutie (PHP-compile-time-string), niet via sprintf met user-input — kan dus geen runtime-data lekken"

patterns-established:
  - "Exception-laag = minimaal, semantisch laaggrijp: package-base + één concrete subclass voor de enige wiring-conditie die in deze fase getest wordt (resolver unbound). Verdere subclasses komen pas wanneer een concreet plan een specifieke runtime-fault-mode nodig heeft"
  - "Security-by-design in exception-factories: factory-signatures geven geen mogelijkheid om secrets door te geven, dus secret-leak is structureel onmogelijk in plaats van conventioneel-vermeden"

requirements-completed: []  # MOLL-01 voltooid pas na 02-07 (volledige SDK + tests). 02-03 levert de exception-trigger voor de SP-binding in 02-04.

# Metrics
duration: ~5 min
completed: 2026-05-14
---

# Phase 2 Plan 03: MollieException + MissingCredentialResolverException Summary

**Minimal exception-laag (MollieException als RuntimeException-subclass + final MissingCredentialResolverException met parameter-loze ::notBound()-factory die FQCN-only message produceert) in de sub-repo gezet — bewust geen eigen HTTP-status-hiërarchie omdat mollie/mollie-api-php die zelf adequaat doet, en bewust geen secret-acceptatie in de factory-signature zodat secret-leak via exception-message structureel onmogelijk is.**

## Performance

- **Duration:** ~5 min
- **Started:** 2026-05-14T12:01Z
- **Completed:** 2026-05-14T12:06Z
- **Tasks:** 1
- **Files created:** 2 (sub-repo) + 1 (Hub-worktree SUMMARY.md)
- **Files modified:** 0

## Accomplishments

- `packages/mollie-api/src/Exceptions/MollieException.php` — `class MollieException extends RuntimeException` met PHPDoc die toelicht waarom de package géén eigen HTTP-exception-hiërarchie heeft (mollie/mollie-api-php exposeert ApiException + ValidationException + UnauthorizedException + NotFoundException + TooManyRequestsException + ServerException + ServiceUnavailableException — host-apps catchen die direct)
- `packages/mollie-api/src/Exceptions/MissingCredentialResolverException.php` — `final class MissingCredentialResolverException extends MollieException` met parameter-loze static `::notBound(): self` factory; message bevat `MollieCredentialResolver::class` FQCN tweemaal (eenmaal als bericht-onderdeel, eenmaal als concreet copy-paste bind-snippet) zodat dev direct weet welk contract gebonden moet worden en hoe
- Security-invariant gehandhaafd: zero parameter-flow in `::notBound()` betekent dat er geen pad bestaat waarlangs een runtime-secret (apiKey, accessToken, subscription-key) in de exception-message kan belanden — structureel, niet conventioneel

## Task Commits

Per-task atomic commits in `packages/mollie-api/` sub-repo op `feat/foundation`:

1. **Task 1: MollieException + MissingCredentialResolverException** — `2c848d2` (feat)
   - Files: `src/Exceptions/MollieException.php`, `src/Exceptions/MissingCredentialResolverException.php`
   - 63 insertions, 0 deletions

**Hub-worktree (deze repo):** `gsd/phase-2-emeq-mollie-api-foundation` branch krijgt apart commit voor SUMMARY.md (sequential_execution flow). Geen sub-repo-commits zichtbaar in Hub-git-log — sub-repo is een eigen `.git`.

## Files Created/Modified

### Sub-repo `packages/mollie-api/` (eigen git, niet zichtbaar in Hub git-log)

- `src/Exceptions/MollieException.php` (created) — `class MollieException extends RuntimeException` met uitgebreide PHPDoc over de mollie/mollie-api-php exception-set die we bewust NIET wrappen. Lege class-body.
- `src/Exceptions/MissingCredentialResolverException.php` (created) — `final class MissingCredentialResolverException extends MollieException`, single static method `::notBound(): self` met sprintf-message die `MollieCredentialResolver::class` FQCN tweemaal injecteert. Geen ctor-override, geen instance-state.

### Hub-worktree `emeq-hub-phase2`

- `.planning/phases/02-emeq-mollie-api-foundation/02-03-SUMMARY.md` — dit bestand

## Decisions Made

- **MollieException extends RuntimeException (lege body)** — Consistent met SnelstartException-pattern in `packages/snelstart-api/src/Exceptions/SnelstartException.php`. `RuntimeException` (vs. `\Exception`) signaleert dat dit niet "exceptional but expected"-flow is maar echte programming/wiring-errors. Lege body is intentioneel: er is nu géén shared state of helper-method nodig — toekomstige subclasses kunnen specifieke factory-methods toevoegen wanneer er een concrete runtime-fault-mode opduikt die niet door de onderliggende lib gedekt is
- **Géén HTTP-status-mapping exception-hiërarchie** — `02-CONTEXT.md` sectie "Bewust NIET" en het plan's interfaces-block specificeren expliciet dat `Mollie\Api\Exceptions\{ApiException, ValidationException, UnauthorizedException, NotFoundException, TooManyRequestsException, ServerException, ServiceUnavailableException}` direct door host-apps gecatched worden — niet via een eigen wrapper-laag. Dit voorkomt: (a) rederiving van mollie/mollie-api-php interface bij elke nieuwe HTTP-statuscode die ze toevoegen, (b) inconsistente exception-types bij gemixed use (één try-catch-host catches Mollie\Api én Emeq\MollieApi exceptions in dezelfde handler)
- **`::notBound()` is parameter-loos** — Plan must_have #3: "Geen raw apiKey of accessToken kan in de exception-messages terechtkomen (geen \$secret-acceptatie in ::notBound())". Implementatie maakt dit structureel: er is letterlijk geen weg om vanuit caller-code een string in de message te krijgen anders dan via de hard-coded sprintf-template
- **FQCN tweemaal in message** — Eerste `%s` documenteert *welk* contract niet gebonden is. Tweede `%s` levert een copy-paste-ready PHP-snippet (`$this->app->bind(Emeq\MollieApi\Contracts\MollieCredentialResolver::class, YourTenantResolver::class);`). Beide gebruiken `::class`-resolutie zodat een rename via IDE-refactor de message automatisch update — geen string-literal-drift mogelijk
- **`final` keyword op MissingCredentialResolverException** — Verhindert dat host-apps deze klasse subclassen en daarmee mogelijk via een ctor-override secrets injecteren. `final` op een leaf-exception is goed pattern (PSR-suggestie) en match Snelstart-pattern

## Deviations from Plan

### Auto-fixed Issues

**1. [Style - Pint reformatting] concat-operator spacing**

- **Found during:** Pint-pass na file-creation in Task 1
- **Issue:** Pint paste `concat_space`-fixer toe op `MissingCredentialResolverException.php`: `'…ServiceProvider, e.g.: ' .` ↔ `'…ServiceProvider, e.g.: '.` — Pint koos voor spaces-around-concat-operator. Plan-template uit PLAN had geen explicit spacing-preference; Pint-config wint
- **Fix:** Geen revert nodig — Pint-output geaccepteerd, blijft binnen plan-spec (functioneel identieke string-concatenation, message-output exact gelijk)
- **Files affected:** `packages/mollie-api/src/Exceptions/MissingCredentialResolverException.php` regels 24-25
- **Commit:** included in `2c848d2`

---

**Total deviations:** 1 documenteren-only (Pint format-pass, geen scope/functionele wijziging)
**Impact on plan:** Geen. PLAN exact uitgevoerd, beide verify-greps + must_have-truths gehandhaafd post-Pint

## Issues Encountered

- **Docs-drift hook-signaal op elke Write naar `packages/mollie-api/`:** post-tool-hook waarschuwde 2 keer voor "SDK-package = mogelijk ADR-trigger, run docs-sync". Behandeld als false-positive (zelfde patroon als plan 02-02): deze plan voert uit wat in `02-CONTEXT.md` al beslist is — package-base exception + missing-resolver-exception is een geplande deliverable van fase 2, geen nieuwe architecturele keuze. Docs-sync-run staat al gepland aan einde Phase 02.

## Known Stubs

Geen. `MollieException` is intentioneel een lege package-base — dat is geen stub, dat is het ontwerp (uitbreidbaarheid zonder dwingende premature subclass-tree). `MissingCredentialResolverException::notBound()` levert direct een werkende, parameter-loze factory met volledige message — geen TODO, geen FIXME.

## Follow-up

- **Plan 02-04 (MollieServiceProvider):** zal `MissingCredentialResolverException::notBound()` aanroepen in de `Mollie::class`-singleton-binding wanneer `$app->bound(MollieCredentialResolver::class)` false retourneert (zie 02-CONTEXT.md "Container-bindings"). Deze SUMMARY documenteert het contract; plan 02-04 valideert end-to-end gebruik
- **Plan 02-05 (Mollie::client facade-target):** zal `new MollieException('Production env requires live_ API-key')` gooien in de `enforce_environment`-guard (zie 02-CONTEXT.md `Mollie::client()` flow). Deze SUMMARY bevestigt dat `MollieException` non-final is en dus direct instantieerbaar — geen factory-method nodig voor ad-hoc gebruik
- **Geen test-file in dit plan:** PLAN explicit: "Geen dedicated test-file — de exception is een trigger-mechanism, getest in MollieServiceProviderTest / MollieTest (plan 02-06)". 02-06 zal: (a) `MollieServiceProviderTest::it_throws_missing_credential_resolver_exception_when_resolver_unbound()` en (b) `MollieTest` enforce-environment-test toevoegen

## Next Phase Readiness

- **Voor 02-04 (MollieServiceProvider):** `Emeq\MollieApi\Exceptions\MissingCredentialResolverException` is bereikbaar via autoload (composer.json PSR-4 root uit 02-01) zodra `composer dump-autoload` draait in de sub-repo. `::notBound()` is statisch, geen DI-resolutie nodig. SP-binding kan direct: `throw MissingCredentialResolverException::notBound();`
- **Voor 02-05 (Mollie facade-target):** `MollieException` is non-final, lege ctor-signature geërfd van RuntimeException — Mollie::client() kan `throw new MollieException('Production env requires live_ API-key');` doen voor enforce_environment-guard
- **Voor 02-06 (Pest-bootstrap):** geen test-file in dit plan, dus geen autoload/namespace-issue om af te vangen
- **Blockers:** geen.

---

## Self-Check: PASSED

**Sub-repo commits (packages/mollie-api/ — `feat/foundation`):**

- `2c848d2` FOUND — `feat(02-03): MollieException + MissingCredentialResolverException`

**Files verified on disk:**

- `packages/mollie-api/src/Exceptions/MollieException.php` — FOUND (class MollieException extends RuntimeException, lege body, package-base PHPDoc)
- `packages/mollie-api/src/Exceptions/MissingCredentialResolverException.php` — FOUND (final class extends MollieException, public static notBound(): self, FQCN-only message)

**PLAN verify-greps — all PASS:**

- `class MollieException extends RuntimeException` in MollieException.php — 1 hit
- `final class MissingCredentialResolverException extends MollieException` in MissingCredentialResolverException.php — 1 hit
- `public static function notBound(): self` in MissingCredentialResolverException.php — 1 hit
- `MollieCredentialResolver::class` in MissingCredentialResolverException.php — 2 hits
- Geen `$apiKey` of `$accessToken` of `secret` keywords in beide exception-files — 0 hits (security-property gehandhaafd)

**PHP syntax (`php -l`) — all clean:**

- `src/Exceptions/MollieException.php` — No syntax errors
- `src/Exceptions/MissingCredentialResolverException.php` — No syntax errors

**Branch state:**

- Sub-repo HEAD: `feat/foundation` (verified via `git symbolic-ref --short HEAD`)
- Hub-worktree HEAD: `gsd/phase-2-emeq-mollie-api-foundation`

---

*Phase: 02-emeq-mollie-api-foundation*
*Completed: 2026-05-14*
