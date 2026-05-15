---
phase: 02-emeq-mollie-api-foundation
plan: 01
subsystem: infra
tags: [composer, mollie, sdk-skeleton, php8.3, laravel-package-tools, pest, phpstan]

# Dependency graph
requires: []
provides:
  - "Lege emeq/mollie-api package-skeleton (composer.json + dotfiles + config/mollie.php + LICENSE/README/CHANGELOG)"
  - "Werkende composer install met mollie/mollie-api-php v3.11.0 in vendor/"
  - "feat/foundation branch in packages/mollie-api/ sub-repo met 2 commits"
  - "Autoload-mapping Emeq\\MollieApi\\ → src/ klaar voor opvolgende plans"
affects:
  - 02-02-PLAN (Contracts + Data classes)
  - 02-03-PLAN (Exception laag)
  - 02-04-PLAN (MollieServiceProvider + post-autoload-dump prepare-script)
  - 02-05-PLAN (Mollie facade-target)
  - 02-06-PLAN (Pest test-suite bootstrap)
  - 02-07-PLAN (Tests)
  - 02-08-PLAN (Hub-composer.json path-repo entry)

# Tech tracking
tech-stack:
  added:
    - "mollie/mollie-api-php ^3.11 (v3.11.0 installed)"
    - "spatie/laravel-package-tools ^1.16"
    - "spatie/laravel-data ^4.0"
    - "pestphp/pest ^3||^4 (require-dev)"
    - "orchestra/testbench ^9||^10||^11 (require-dev)"
    - "larastan/larastan ^3 (require-dev)"
  patterns:
    - "SDK-skeleton-conventie 1-op-1 gemirrord van packages/snelstart-api/ (dotfiles, pint.json, phpstan.neon.dist, phpunit.xml.dist)"
    - "composer.lock gitignored als eerste regel — SDK-best-practice (libs shippen geen lock)"
    - "Namespace: Emeq\\MollieApi\\ → src/, Tests\\ → tests/"
    - "Auto-discovery: MollieServiceProvider + Mollie-facade-alias"

key-files:
  created:
    - "packages/mollie-api/composer.json"
    - "packages/mollie-api/.gitignore"
    - "packages/mollie-api/.gitattributes"
    - "packages/mollie-api/.editorconfig"
    - "packages/mollie-api/pint.json"
    - "packages/mollie-api/phpstan.neon.dist"
    - "packages/mollie-api/phpunit.xml.dist"
    - "packages/mollie-api/LICENSE.md"
    - "packages/mollie-api/CHANGELOG.md"
    - "packages/mollie-api/README.md"
    - "packages/mollie-api/config/mollie.php"
  modified: []

key-decisions:
  - "PHP-constraint ^8.3 (matchend met snelstart-api; PHP 8.4 host draait dit prima)"
  - "Facade-alias = `Mollie` (niet `EmeqMollie`) per 02-CONTEXT.md decision"
  - "Geen post-autoload-dump/prepare script in dit plan — komt pas in 02-04 zodra MollieServiceProvider concreet bestaat (W-2 revision uit CONTEXT)"
  - "phpstan-level = 6 zonder Dto-exclude (Snelstart heeft die wel, want OData-generated; Mollie heeft geen Dto-laag)"
  - "idempotency.generator docblock documenteert zowel FQCN als container-alias (Mollie::client() roept $container->make($value) aan — beide paden resolven)"

patterns-established:
  - "Sub-repo workflow: package krijgt eigen git, feat/foundation branch, eigen commits — Hub-worktree tracking via path-repository (komt in 02-08)"
  - "composer.lock disk-gegenereerd maar gitignored — git check-ignore bevestigd"
  - "Skeleton is deterministisch installable in isolation zonder Testbench package:discover (geen prepare-script tot 02-04)"

requirements-completed: []  # MOLL-01 voltooid pas na 02-02..02-07; dit plan levert alleen de skeleton.

# Metrics
duration: ~9 min
completed: 2026-05-14
---

# Phase 2 Plan 01: emeq/mollie-api package-skeleton Summary

**Lege emeq/mollie-api Composer-package opgezet met mollie/mollie-api-php v3.11.0 als kerndep, namespace Emeq\\MollieApi → src/, en feat/foundation sub-repo-branch klaar voor src/ + tests/ in plan 02-02.**

## Performance

- **Duration:** ~9 min
- **Started:** 2026-05-14T13:43Z
- **Completed:** 2026-05-14T13:52Z
- **Tasks:** 3
- **Files created:** 11 (in sub-repo: 11; in Hub-worktree: 1 SUMMARY.md)

## Accomplishments

- `packages/mollie-api/composer.json` met `emeq/mollie-api` package-naam, PHP `^8.3`, `mollie/mollie-api-php ^3.11` als kerndep, namespace `Emeq\MollieApi\` mapt naar `src/`, auto-discovery wijst naar `Emeq\MollieApi\MollieServiceProvider` + alias `Mollie` → facade
- Skeleton-metadata files (`.gitignore`, `.gitattributes`, `.editorconfig`, `pint.json`, `phpstan.neon.dist`, `phpunit.xml.dist`, `LICENSE.md` MIT, `CHANGELOG.md`, `README.md`) 1-op-1 gemirrord van `packages/snelstart-api/` met Mollie-specifieke aanpassingen
- `config/mollie.php` met `enforce_environment` / `http` (timeout + guzzle_options) / `idempotency.generator` keys; docblock documenteert FQCN én container-alias paden
- `cd packages/mollie-api && composer install` deterministisch groen: `vendor/mollie/mollie-api-php/` (v3.11.0), `vendor/spatie/laravel-package-tools/`, Pest + Testbench + Larastan in require-dev
- `composer dump-autoload -o` slaagt zonder errors (8887 classes generated)
- Sub-repo `feat/foundation` branch met 2 atomic commits klaar voor opvolgende plans

## Task Commits

Per task atomic commits in `packages/mollie-api/` sub-repo op `feat/foundation`:

1. **Task 1: composer.json + dotfiles + feature-branch** — `e1cf185` (feat)
   - Files: composer.json, .gitignore, .gitattributes, .editorconfig
2. **Task 2: tooling-config + skeleton-metadata + config/mollie.php** — `95aef8a` (chore)
   - Files: pint.json, phpstan.neon.dist, phpunit.xml.dist, LICENSE.md, CHANGELOG.md, README.md, config/mollie.php
3. **Task 3: composer install + lege src/ + tests/ dirs** — *no commit* (zie Deviations)
   - On-disk artifacts: vendor/, composer.lock, src/ (leeg), tests/ (leeg) — alle gitignored of leeg

**Hub-worktree (deze repo):** `gsd/phase-2-emeq-mollie-api-foundation` branch krijgt apart commit voor SUMMARY.md (zie sequential_execution flow).

## Files Created/Modified

### Sub-repo `packages/mollie-api/` (eigen git, niet zichtbaar in Hub git-log)

- `composer.json` — Package manifest: emeq/mollie-api, PHP ^8.3, mollie/mollie-api-php ^3.11, Emeq\\MollieApi namespace + auto-discovery
- `.gitignore` — composer.lock + /vendor + caches + IDE-files (1-op-1 van snelstart-api)
- `.gitattributes` — export-ignore voor tests/, dotfiles, phpstan/phpunit dist
- `.editorconfig` — UTF-8, LF, 4-space indent
- `pint.json` — PSR-12 preset + project-rules (1-op-1 van snelstart-api)
- `phpstan.neon.dist` — level 6 op src + config
- `phpunit.xml.dist` — testsuite "emeq/mollie-api Test Suite"
- `LICENSE.md` — MIT, Copyright Yusuf Karacaburun
- `CHANGELOG.md` — Keep-a-Changelog placeholder met Phase 2 unreleased-blok
- `README.md` — Basis-usage + dual-creds intro + facade-collision note (verwijst naar Phase 6)
- `config/mollie.php` — enforce_environment, http.timeout/guzzle_options, idempotency.generator (FQCN of container-alias)

### Hub-worktree `emeq-hub-phase2`

- `.planning/phases/02-emeq-mollie-api-foundation/02-01-SUMMARY.md` — dit bestand

## Decisions Made

- **PHP-constraint `^8.3`** — matched snelstart-api (CONTEXT decision); Hub draait op PHP 8.4 maar SDK blijft ^8.3 voor consumer-compatibiliteit
- **Facade-alias = `Mollie`** (niet `EmeqMollie`) — bevestigd in 02-CONTEXT.md, opgemerkt dat ROADMAP.md een eerdere `EmeqMollie`-keuze noemt; we volgen de meest recente CONTEXT-beslissing (zie Issues)
- **Geen `post-autoload-dump`/`prepare` script** — W-2 revision: pas in 02-04 toegevoegd zodra MollieServiceProvider concreet is. Voorkomt non-deterministische Testbench package:discover-failure tijdens skeleton-install
- **phpstan level 6** — match snelstart-api, geen `src/Dto/*` exclude (Mollie heeft geen Dto-laag), geen `larastan.noEnvCallsOutsideOfConfig` override (config/mollie.php is clean)
- **`composer.lock` gitignored** — SDK-best-practice; consumer-apps krijgen reproducible installs vanuit hun eigen lock

## Deviations from Plan

**1. [Niet-blokkerend - Geen commit voor Task 3]**

- **Found during:** Task 3 (composer install + lege src/ + tests/)
- **Issue:** Task 3 produceert geen tracked file changes — `vendor/`, `composer.lock`, en lege `src/`/`tests/` dirs zijn allemaal gitignored of door git genegeerd (lege dirs)
- **Fix:** Geen aparte commit gemaakt voor Task 3. Het execute-plan-flow zegt "commit each task atomically" maar er was niets te stagen. Dit is consistent met plan-action ("Commit niets — dat gebeurt pas in plan 02-08") in Task 2's instructie
- **Files affected:** packages/mollie-api/composer.lock (gitignored), packages/mollie-api/vendor/ (gitignored), packages/mollie-api/src/ (lege dir), packages/mollie-api/tests/ (lege dir)
- **Verification:** `git status --short` toont clean working tree; `git check-ignore composer.lock` bevestigt ignored-state

**2. [Procedureel - Plan-files niet eerder gestaged in worktree branch]**

- **Found during:** Pre-execution context-check
- **Issue:** De Hub-worktree branch `gsd/phase-2-emeq-mollie-api-foundation` was afgesplitst vóórdat de 8 PLAN-files in commit `0c9f736` op master landden. De PLAN-files moesten via `git checkout master -- .planning/phases/02-emeq-mollie-api-foundation/` opgehaald worden
- **Fix:** Plans opgehaald via checkout uit master; deze blijven gestaged in working tree. NIET meegenomen in mijn SUMMARY-commit per objective ("Do NOT update STATE.md or ROADMAP.md") — orchestrator beslist over plans-staging
- **Files affected:** 8 plan-files in `.planning/phases/02-emeq-mollie-api-foundation/02-0[1-8]-PLAN.md`

---

**Total deviations:** 2 procedureel/structureel (geen Rule 1/2/3 auto-fixes nodig)
**Impact on plan:** Geen scope creep. Plan exact uitgevoerd zoals geschreven; afwijking zit alleen in commit-granulariteit (Task 3 had niets te committen).

## Issues Encountered

- **ROADMAP.md vs CONTEXT.md inconsistentie over facade-alias**: ROADMAP.md regel 41 noemt `EmeqMollie`, CONTEXT.md regel 95-97 noemt `Mollie`. Plan-file 02-01 noemt expliciet `"Mollie"` als alias (regel 211 en 264) + verwijst naar CONTEXT-decision. Conform `.ai/rules/engineering.md` "conflicten oppervlakken, niet uitmiddelen": gekozen voor `Mollie` zoals plan/CONTEXT voorschrijven. ROADMAP.md update is een follow-up taak (docs-sync).
- **PLAN-files staged-state**: zie deviation #2. Orchestrator-overdracht laat 8 plan-files achter als staged-changes in worktree. Niet meegenomen in mijn commit.

## Known Stubs

Geen — dit plan levert alleen skeleton-metadata. `src/` en `tests/` zijn lege directories die in plan 02-02 t/m 02-07 worden gevuld. Geen hardcoded placeholders die naar UI of runtime-output stromen.

## Follow-up

- **docs-sync skill draaien aan einde Phase 02** — meerdere SDK-package writes triggerden de hook (composer.json, .gitignore, pint.json, etc.). Beste moment is na Phase 02 close (na 02-08), niet na elke plan.
- **ROADMAP.md regel 41 `EmeqMollie` → `Mollie`** — verwerken bij volgende phase-doc-update (CONTEXT.md is canonical).
- **GitHub repo description `yusufkaracaburun/emeq-mollie-api`** — nog steeds stale ("Saloon v3"); update bij eerste push in plan 02-08 of 02-09 (push gebeurt buiten dit plan).

## Next Phase Readiness

- **Voor 02-02 (Contracts + Data classes):** composer autoload-mapping (`Emeq\MollieApi\` → `src/`) is werkend; PSR-4 ready. Pest + Testbench geïnstalleerd voor opvolgende test-bootstrap.
- **Voor 02-04 (MollieServiceProvider):** `extra.laravel.providers` is gezet maar de klasse bestaat nog niet — verwacht is dat 02-04 een `post-autoload-dump: @composer run prepare` script toevoegt zodra de klasse er is.
- **Voor 02-08 (Hub composer.json path-repo entry):** sub-repo `feat/foundation` branch heeft 2 commits; merge naar `main` + GitHub-push pas in 02-08 of later, na approval.
- **Blockers:** geen.

---

## Self-Check: PASSED

**Sub-repo commits (packages/mollie-api/):**
- `e1cf185` FOUND — `feat(02-01): composer.json + dotfiles voor emeq/mollie-api skeleton`
- `95aef8a` FOUND — `chore(02-01): tooling-config + skeleton-metadata + config/mollie.php`

**Files verified on disk:**
- packages/mollie-api/composer.json — FOUND
- packages/mollie-api/.gitignore — FOUND (composer.lock op regel 2 = "Composer Related"-blok eerste entry)
- packages/mollie-api/.gitattributes — FOUND
- packages/mollie-api/.editorconfig — FOUND
- packages/mollie-api/pint.json — FOUND
- packages/mollie-api/phpstan.neon.dist — FOUND (level: 6)
- packages/mollie-api/phpunit.xml.dist — FOUND ("emeq/mollie-api Test Suite")
- packages/mollie-api/LICENSE.md — FOUND (MIT License)
- packages/mollie-api/CHANGELOG.md — FOUND
- packages/mollie-api/README.md — FOUND
- packages/mollie-api/config/mollie.php — FOUND (enforce_environment + http + idempotency)
- packages/mollie-api/vendor/mollie/mollie-api-php — FOUND (v3.11.0)
- packages/mollie-api/composer.lock — FOUND (gitignored)
- packages/mollie-api/src/ — FOUND (lege dir)
- packages/mollie-api/tests/ — FOUND (lege dir)

**Branch state:**
- Sub-repo HEAD: `feat/foundation` (verified via `git symbolic-ref --short HEAD`)
- Hub-worktree HEAD: `gsd/phase-2-emeq-mollie-api-foundation`

---

*Phase: 02-emeq-mollie-api-foundation*
*Completed: 2026-05-14*
