---
phase: 01-snelstart-sdk-finalize
plan: 02
subsystem: snelstart-sdk
tags: [snelstart, pest, connector, exceptions, coverage]
requires:
  - "01-01 (NO CRASH REPRO confirmatie)"
provides:
  - "SnelstartConnectorTest met directe getRequestException()-coverage per status-branch (400/401/403/404/429/5xx/204/301)"
  - "handleRetry()-coverage voor FatalRequestException + retryable/non-retryable statuses"
  - "Feature-branch fix/pest-crash-fase-4 klaar voor merge naar main + push in plan 03"
affects:
  - packages/snelstart-api/tests/Unit/Http/SnelstartConnectorTest.php
tech-stack:
  added: []
  patterns:
    - "PHPUnit createMock(Response::class) + createMock(PendingRequest::class) ipv Saloon-fake-pipeline voor exception-mapping-tests"
    - "RequestException-constructie met expliciete message-string om de parent-constructor's body-chain te omzeilen tijdens retry-tests"
key-files:
  created: []
  modified:
    - packages/snelstart-api/tests/Unit/Http/SnelstartConnectorTest.php (1 case → 12 cases / 37 assertions)
decisions:
  - "Task 1 (Pest-crash-fix) overgeslagen als no-op — plan 01-01 confirmde NO CRASH REPRO op main @ 76e0797; geen fake-fix-commit gemaakt"
  - "Mock-strategie: PHPUnit createMock op Saloon\\Http\\Response (status/body/header/getPendingRequest) — geen MockClient-pipeline, geen MockResponse::make"
  - "handleRetry-tests construeren een echte Saloon\\Exceptions\\Request\\RequestException met expliciete `message: 'stub'` zodat de parent-constructor body() niet meer aanroept"
metrics:
  duration: "~3 min"
  completed: 2026-05-14T08:43:25Z
  task_count: 2
  file_count: 1
commits:
  sdk:
    - "16c9ecc test(connector): directe getRequestException + handleRetry coverage zonder MockClient"
---

# Phase 01 Plan 02: SnelstartConnectorTest coverage Summary

SnelstartConnectorTest uitgebreid van 1 zwakke container-binding-case naar 12 cases (37 assertions) die elke `getRequestException()`-status-branch en de `handleRetry()`-retry-policy direct asserteren via PHPUnit-mocks op `Saloon\Http\Response` — geen Saloon-fake-pipeline.

## Aantal groene tests vóór + na

| Run | Tests passed | Assertions | Exit |
|-----|--------------|------------|------|
| Pre-plan baseline (plan 01-01) | 86 | 151 | 0 |
| Filter `--filter=SnelstartConnectorTest` post-fix | 22 | 37 | 0 |
| Full suite post-fix | **107** | **187** | **0** |

Suite-toename: +21 passed cases (+36 assertions). Drempel `≥30 passed` uit SNEL-01 ruimschoots gehaald (107 / 30 = 3.6×).

`SnelstartConnectorTest` levert 22 passed cases via dataset-expansion (4×5xx + 2×unmapped + 5×retryable + 3×non-retryable + 6 statische = 22 — Pest telt elke dataset-rij als aparte test).

## Welke fix is toegepast op de crash

**Geen.** Task 1 (Pest-crash-fix) is een gedocumenteerde no-op:

> Plan 01-01 confirmde NO CRASH REPRO op `main @ 76e0797` over twee opeenvolgende random-order runs (86 / 86 passed). De fase-4 crash was reeds opgelost vóór de fase-5 merge — `MockClient`-pipeline is nooit gelanded in `SnelstartConnectorTest.php`. Plan 01-02 verschuift conform de fallback-instructie in plan 01 van crash-fix naar coverage-versterking.

Er is dus géén `fix(pest):` commit gemaakt; alleen de `test(connector):`-commit (16c9ecc) staat op de feature-branch bovenop 29ed769 (de plan-01 chore-commit).

## Welke test-cases zijn toegevoegd aan SnelstartConnectorTest

`getRequestException()`-coverage (statische + dataset = 8 `it()`-blokken, 16 dataset-expansies):

1. `it returns ValidationException for HTTP 400 and surfaces Snelstart error codes` — asserts `errorCodes === ['ALG-0100']` + message bevat `ALG-0100`
2. `it returns AuthenticationException for HTTP 401` — message bevat `HTTP 401` + `fp:`
3. `it returns AuthenticationException for HTTP 403` — message bevat `HTTP 403`
4. `it returns NotFoundException for HTTP 404 with the request URL in the message` — message bevat `/relaties/deleted-guid` + `404` (verifieert de `getPendingRequest()->getUrl()`-chain)
5. `it returns RateLimitException for HTTP 429 and parses Retry-After header` — `retryAfterSeconds === 42` + message bevat `retry after 42s`
6. `it returns RateLimitException with null retryAfter when no Retry-After header is sent` — `retryAfterSeconds === null` + message bevat geen `retry after`
7. `it returns ServerException for transient 5xx statuses` — datatest [500, 502, 503, 504] → `ServerException` + message bevat `HTTP {status}`
8. `it returns null for unmapped 2xx and 3xx statuses` — datatest [204, 301] → null

`handleRetry()`-coverage (3 `it()`-blokken, 9 dataset-expansies):

9. `it handleRetry returns true for FatalRequestException (connection-level)` — echte `FatalRequestException` met gemockte PendingRequest
10. `it handleRetry returns true for retryable statuses (429, 500, 502, 503, 504)` — datatest [429, 500, 502, 503, 504] → true
11. `it handleRetry returns false for non-retryable 4xx statuses (400, 401, 404)` — datatest [400, 401, 404] → false

Container-binding (behouden uit pre-bestaande versie):

12. `it invokes the authenticator factory` — `app('snelstart.authenticator-factory')` retourneert sluiting die `ClientKeyAuthenticator` produceert

## Of de implementatie moest worden bijgesteld

**Nee.** Alle 22 cases passen meteen tegen de bestaande `src/Http/SnelstartConnector.php` zonder enige wijziging in `src/`. De master-plan-mapping (400→Validation, 401/403→Authentication, 404→NotFound, 429→RateLimit, 5xx→Server, default→null) is exact wat de tests bevestigen, en `handleRetry()`'s `in_array($status, $retryOnStatuses, true)`-pad voor `RequestException` plus de unconditional `true`-tak voor `FatalRequestException` klopt 1-op-1 met de plan-spec.

## Mock-strategie (voor toekomstige referentie)

- **`Saloon\Http\Response` mock** via `test()->createMock(Response::class)` met stubs op `status()`, `body()`, `header()` (callback voor `Retry-After`), en `getPendingRequest()`.
- **`Saloon\Http\PendingRequest` mock** voor de 404-pad-tak: stub `getUrl()` met de gewenste URL-string.
- **`Saloon\Exceptions\Request\RequestException`** geconstrueerd met expliciete `message: 'stub'`-arg zodat de parent-constructor `body()` niet hoeft aan te roepen (waardoor we de body-mock kort kunnen houden).
- **`Saloon\Exceptions\Request\FatalRequestException`** geconstrueerd met een ruwe `RuntimeException` + gemockte PendingRequest.

Twee helpers bovenin de file: `fakeSnelstartResponse(int $status, string $body, ?string $retryAfter, string $url)` en `makeSnelstartConnector()` — vermijden boilerplate-duplicatie zonder een aparte testkit te bouwen.

## Branch-status

```
$ git -C packages/snelstart-api log --oneline main..HEAD
16c9ecc test(connector): directe getRequestException + handleRetry coverage zonder MockClient
29ed769 chore(01-01): ignore .planning-scratch/ voor lokale diagnose
```

Branch `fix/pest-crash-fase-4` ligt **2 commits ahead van `main`** (`76e0797`). Klaar voor merge naar `main` + push naar `github.com:yusufkaracaburun/emeq-snelstart-api` in plan 03 (SNEL-02).

## Deviations from Plan

### Task 1 — no-op (geen `fix(pest):` commit)

- **Found during:** Plan 01-01 (NO CRASH REPRO)
- **Issue:** Plan 02 Task 1 specificeerde een Pest-crash-fix met commit-prefix `fix(pest):`. De crash bestaat niet meer in de tree op `main @ 76e0797` — plan 01-01 confirmde dit met twee groene random-order runs (exit 0, 86 passed).
- **Fix:** Geen fake-fix-commit gemaakt. Plan 01's expliciete fallback-instructie ("als de suite onverwacht volledig groen is … is plan 02's eerste task een no-op en kan plan 02 direct doorgaan") gevolgd. Documenteer in plaats daarvan in deze SUMMARY.
- **Files modified:** geen (Task 1)
- **Commit:** n.v.t.

Geen verdere afwijkingen; plan 02's Task 2 actie-stappen volgden exact de gespecificeerde mock-strategie (PHPUnit `createMock` op `Saloon\Http\Response`, geen MockClient).

## Threat Flags

Geen — alleen test-coverage uitgebreid; geen nieuwe netwerk-, auth- of schema-surface. Threat T-01-02-01 (test-fixtures lekken secrets) gemitigeerd: alleen sentinel-strings `'ck'` en `'sk'`. T-01-02-02 (body-fixtures lijken customer-data) gemitigeerd: alleen gefabriceerde `ALG-0100`-codes en generieke strings (`'unauthorized'`, `'throttled'`, `'gateway error'`). T-01-02-03 (test wijzigt implementatie om groen te krijgen) gemitigeerd: geen `src/`-wijzigingen nodig. T-01-02-04 (cache-leakage tussen tests) gemitigeerd: bestaande `Cache::flush()` in `tests/Pest.php` blijft van kracht.

## Known Stubs

Geen — alle nieuwe test-cases asserteren tegen reëel gedrag van de productiecode in `src/Http/SnelstartConnector.php` en `src/Exceptions/*.php`.

## Self-Check: PASSED

**Files (Hub-repo):**
- FOUND: `.planning/phases/01-snelstart-sdk-finalize/01-02-SUMMARY.md`

**Files (SDK sub-repo, op branch `fix/pest-crash-fase-4`):**
- FOUND: `packages/snelstart-api/tests/Unit/Http/SnelstartConnectorTest.php` (12 `it(`-blokken, 22 cases na dataset-expansion)

**Commits (SDK sub-repo):**
- FOUND: `16c9ecc` — `test(connector): directe getRequestException + handleRetry coverage zonder MockClient`

**Acceptance criteria (alle 7 groen):**
- ≥6 `it()`-declaraties in SnelstartConnectorTest: **12** ✓
- 0 `MockClient`-referenties in SnelstartConnectorTest: **0** ✓
- ≥6 `getRequestException`-calls: **10** ✓
- ≥5 unieke `toBeInstanceOf(...Exception::class)`-asserties: **7** (Validation, Authentication ×2, NotFound, RateLimit ×2, Server) ✓
- Full Pest exit 0 met ≥30 passed: **107 passed / 187 assertions / exit 0** ✓
- `SnelstartConnectorTest`-filter ≥6 passed: **22 passed** ✓
- Eén commit met prefix `test(connector):` op feature-branch: **16c9ecc** ✓
