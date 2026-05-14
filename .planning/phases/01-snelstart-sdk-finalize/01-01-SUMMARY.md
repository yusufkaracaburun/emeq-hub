---
phase: 01-snelstart-sdk-finalize
plan: 01
subsystem: snelstart-sdk
tags: [snelstart, pest, diagnostics, fase-4, no-repro]
requires: []
provides:
  - "Bevestiging dat de Pest-suite groen is op packages/snelstart-api main @ 76e0797"
  - "Feature-branch fix/pest-crash-fase-4 in de SDK-repo, klaar voor plan 02"
  - "Root-cause + hypothese voor plan 02 in .planning-scratch/01-pest-crash-output.txt"
affects:
  - packages/snelstart-api/.gitignore
tech-stack:
  added: []
  patterns:
    - "Lokale diagnose-scratchpads in .planning-scratch/ (gitignored per sub-repo)"
key-files:
  created:
    - packages/snelstart-api/.planning-scratch/01-pest-crash-output.txt (gitignored, lokaal-only)
    - packages/snelstart-api/.planning-scratch/01-pest-crash-run1.txt (gitignored)
    - packages/snelstart-api/.planning-scratch/01-pest-crash-run2.txt (gitignored)
  modified:
    - packages/snelstart-api/.gitignore
decisions:
  - "NO CRASH REPRO — fase-4 Pest-crash bestaat niet meer in tree op main @ 76e0797. Plan 02 verschuift van crash-fix naar coverage-versterking."
metrics:
  duration: "~6 min"
  completed: 2026-05-14T08:38:41Z
  task_count: 1
  file_count: 2
commits:
  sdk:
    - "29ed769 chore(01-01): ignore .planning-scratch/ voor lokale diagnose"
---

# Phase 01 Plan 01: Diagnose Pest-crash fase-4 Summary

Pest-suite draait groen op `packages/snelstart-api main @ 76e0797` — geen crash om te reproduceren; plan 02 verschuift van crash-fix naar het versterken van de zwakke `SnelstartConnectorTest`.

## Wat is gevonden

**Status: NO CRASH REPRO.** De Pest-suite passeert op de SDK-repo HEAD `76e0797` (`feat(fase-5): OData query builder + RawSnelstartRequest`) in twee opeenvolgende random-order runs:

- Run 1: 86 passed / 151 assertions / 1.19s / seed 1778747813 / exit 0
- Run 2: 86 passed / 151 assertions / 0.92s / seed 1778747822 / exit 0

`failOnRisky=true`, `failOnWarning=true`, `beStrictAboutOutputDuringTests=true` in `phpunit.xml.dist` — geen van die strict flags wordt overtreden door de huidige suite.

### Root cause (kopie uit het scratch-bestand)

> De fase-4 Pest-crash is reeds opgelost vóór de fase-5 merge: `tests/Unit/Http/SnelstartConnectorTest.php` bevat slechts 1 zwakke test (resolves authenticator factory uit de container) — de MockClient-pipeline tegen `SnelstartConnector::getRequestException()` is nooit landed; de crash-bron bestaat dus niet meer in de tree op `main @ 76e0797`.

### Hypothese voor plan 02 (kopie uit het scratch-bestand)

> De master-plan-aanpak ("drop MockClient-pipeline en unit-test getRequestException() + exception factories direct op de Response-mock-laag") is nog steeds de juiste richting — niet als crash-fix, maar als versterking van de huidige onderverzekerde test.

Plan 02's eerste task is een no-op (geen crash om te fixen); plan 02 kan direct doorgaan met:

1. `tests/Unit/Http/SnelstartConnectorTest.php` uitbreiden met directe unit-tests op `getRequestException()` per status (400 → ValidationException, 401/403 → AuthenticationException, 404 → NotFoundException, 429 → RateLimitException incl. Retry-After parse, 5xx → ServerException) door een Saloon `Response` te construeren via de mock-helper of via een gestubde `PendingRequest`. Géén `MockClient` connector-pipeline.
2. Een test voor `handleRetry()` (FatalRequestException → true, 429/500/502/503/504 → true, andere statussen → false).
3. Bevestigen dat `executionOrder="random"` + `failOnRisky=true` + `beStrictAboutOutputDuringTests=true` géén echo's of risky assertions activeren in de nieuwe cases.

## Welke files in plan 02 worden geraakt

| File | Verwachte actie |
|------|-----------------|
| `packages/snelstart-api/tests/Unit/Http/SnelstartConnectorTest.php` | Uitbreiden van 1 case naar ≥9 cases (≥6 voor `getRequestException()` + ≥3 voor `handleRetry()`) |
| `packages/snelstart-api/src/Http/SnelstartConnector.php` | Alleen lezen — mapping klopt al met exception-factories; geen wijzigingen verwacht |
| `packages/snelstart-api/tests/TestCase.php` of bootstrap | Eventueel — alleen als Response-construction-helper een gedeelde testkit nodig heeft |

## Eventuele afwijking van de master-plan-strategie

De master-plan ging uit van "crash bestaat, MockClient is de oorzaak, drop hem". Realiteit op `main @ 76e0797`: de MockClient is al niet in de `SnelstartConnectorTest` landed (er is alleen een container-binding-test), dus er valt niets te droppen. Plan 02 verschuift van "fix de crash" naar "vul de gat-in-testcoverage" — zelfde files, andere framing. Dit is een Rule-1-passende observatie tijdens diagnose, geen architecturele afwijking.

## Deviations from Plan

Geen — plan 01 expliciet voorzag deze no-repro-uitkomst (task action stap 9: "Optioneel: als de suite onverwacht volledig groen is … markeer in de file `## STATUS: NO CRASH REPRO`"). Pad gevolgd zoals gespecificeerd.

## Threat Flags

Geen — diagnose-only plan; geen nieuwe netwerk-, auth- of schema-surface geïntroduceerd. Threats T-01-01/02 gemitigeerd via gitignore (zoals gepland): `.planning-scratch/` toegevoegd aan `packages/snelstart-api/.gitignore`; `.planning-scratch/01-pest-crash-output.txt` is gitignored bevestigd via `git check-ignore`. Threat T-01-03 (tampering) staat als `accept` in het threat model.

## Self-Check: PASSED

**Files (Hub-repo):**
- FOUND: `.planning/phases/01-snelstart-sdk-finalize/01-01-SUMMARY.md`

**Files (SDK sub-repo, op branch `fix/pest-crash-fase-4`):**
- FOUND: `packages/snelstart-api/.planning-scratch/01-pest-crash-output.txt` (gitignored, bestaat lokaal)
- FOUND: `packages/snelstart-api/.gitignore` (regel `.planning-scratch/` aanwezig)
- VERIFIED: `git diff --name-only main -- src tests` is leeg

**Commits (SDK sub-repo):**
- FOUND: `29ed769` — `chore(01-01): ignore .planning-scratch/ voor lokale diagnose`

**Acceptance criteria (alle 8 groen):**
- File `01-pest-crash-output.txt` bestaat
- `## Environment` header aanwezig
- `## Root cause` header aanwezig
- `## Hypothese voor plan 02` header aanwezig
- `EXIT:` marker aanwezig (twee keer: RUN 1 en RUN 2)
- Branch is `fix/pest-crash-fase-4`
- `.planning-scratch/` in `packages/snelstart-api/.gitignore`
- Geen wijzigingen in `src/` of `tests/` van de SDK
