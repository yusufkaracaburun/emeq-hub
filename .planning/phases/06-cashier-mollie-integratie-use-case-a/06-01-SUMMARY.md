---
phase: 06-cashier-mollie-integratie-use-case-a
plan: 01
subsystem: cashier-mollie / subscriptions
tags: [adr, compat-check, cashier-mollie, research, sub-01]
requires: []
provides:
  - "Phase 6 SC-1 bewezen: ADR `.docs/decisions/cashier-mollie-compat.md` met gekozen pad (a)"
  - "Decision-tree blocker uit STATE.md gesloten — plan 06-02 t/m 06-08 zijn ontblokt"
  - "REQUIREMENTS.md SUB-01 status = In Progress (06-01 done — compat-check landed)"
affects:
  - .docs/decisions/cashier-mollie-compat.md (gitignored, lokaal op disk)
  - .planning/REQUIREMENTS.md (tracked)
tech-stack:
  added: []
  patterns:
    - "ADR-pattern voor decision-tree-blockers in `.docs/decisions/`"
    - "Compat-check workflow: WebFetch composer.json (master + main + Packagist) + Composer dry-run als bewijs"
key-files:
  created:
    - .docs/decisions/cashier-mollie-compat.md
    - .planning/phases/06-cashier-mollie-integratie-use-case-a/06-01-SUMMARY.md
  modified:
    - .planning/REQUIREMENTS.md
decisions:
  - "Pad (a) — out-of-box `composer require mollie/laravel-cashier-mollie:^2.20` (latest stable v2.20.1, 2026-04-23). Upstream is sinds v2.x compatible met PHP 8.2+ + Laravel 11/12/13."
  - "De originele compat-blocker (memory `reference_cashier_mollie_compat_risk.md`, STATE.md) refereerde aan de obsolete `master`-branch (laatste commit 2020-11-28). De actieve dev-branch is `main`."
  - "Geen fork (pad b) en geen eigen subscription-laag (pad c) — geen werk dat upstream al levert."
metrics:
  duration: "5 minuten"
  completed: "2026-05-15"
  tasks_completed: 3
  files_changed: 2
  commits: 1
---

# Phase 6 Plan 01: Cashier-Mollie compat-pad ADR — Summary

Compat-check uitgevoerd voor `mollie/laravel-cashier-mollie` tegen PHP 8.4 / Laravel 13. **Gekozen pad: (a) out-of-box** — upstream stable v2.20.1 ondersteunt onze stack zonder modificaties.

## Concrete evidence die de keuze onderbouwt

- **Latest stable tag:** `v2.20.1` (commit `529da228e8f4`, 2026-04-23, Packagist `mollie/laravel-cashier-mollie`).
- **Actieve branch:** `main` (NIET `master` — master is een 2020-relic met laatste commit `b399a98d6b33` op 2020-11-28).
- **`require.php`:** `^8.2` → accepteert PHP 8.4.
- **`require.illuminate/support`:** `^11|^12|^13` → accepteert Laravel 13.
- **`require.illuminate/database`:** `^11|^12|^13` → accepteert Laravel 13.
- **`require.mollie/laravel-mollie`:** `^4.0` → trekt `mollie/mollie-api-php v3.11.x` mee, identiek aan wat `emeq/mollie-api` (Phase 2) al wrapt.
- **Composer dry-run:** exit `0`, 85 packages locked, pin't `v2.20.1` exact. Geen `requires`-conflict tegen `laravel/framework v13.9.0`.

Workdir: `/tmp/cashier-compat-check-1778828549/` (dry-run.log behouden).

## Wat is gewijzigd

- **`.docs/decisions/cashier-mollie-compat.md`** (gitignored, lokaal op disk) — ADR met `## Context`, `## Onderzoek`, `## Decision`, `## Consequences`, `## Next Steps`, `## Referenties`. Concrete pad-keuze `a` + onderbouwing + verworpen alternatieven + plan-per-plan consequences voor 06-02 t/m 06-08.
- **`.planning/REQUIREMENTS.md`** — SUB-01 status van `Pending` naar `In Progress (06-01 done — compat-check landed)`.

## Tasks completed

| # | Task | Status | Commit |
|---|------|--------|--------|
| 1 | Compat-check uitvoeren (WebFetch composer.json + tag-listing + commit-meta + Composer dry-run) | Done | n.v.t. (research-only, no files) |
| 2 | ADR schrijven `.docs/decisions/cashier-mollie-compat.md` (gitignored, on disk) | Done | n.v.t. (file is gitignored) |
| 3 | REQUIREMENTS.md SUB-01 update + git commit | Done | `3834b53` |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Stray edits in main repo working tree (worktree-isolation slip)**
- **Found during:** Task 3 verification, vlak vóór commit
- **Issue:** De initial Write/Edit-calls voor de ADR en REQUIREMENTS.md gebruikten het main-repo absolute pad (`/Users/yusufkaracaburun/Sites/localhost/emeq-hub/...`) in plaats van het worktree-pad (`/Users/yusufkaracaburun/Sites/localhost/emeq-hub/.claude/worktrees/agent-a62034ae8ac3772cf/...`). De main repo was op die moment checked out op `feat/v02-mollie-subscriptions`. Dit had — als het was gecommit — direct op de feature-branch geland in plaats van op de worktree-branch.
- **Fix:** Stray REQUIREMENTS.md-edit in main repo gereverteerd met `git checkout -- .planning/REQUIREMENTS.md`. ADR-bestand in main repo `.docs/decisions/` is `.gitignore`d (zie `.gitignore:29`), dus geen git-contamination — bestand blijft als harmless leftover op disk in main repo. Vervolgens dezelfde edits opnieuw uitgevoerd in de worktree (correcte paden), pre-commit HEAD-assertion gerund, en gecommit op `worktree-agent-a62034ae8ac3772cf`.
- **Files modified:** Geen extra files; correctie van eerder verkeerd geplaatste edits.
- **Commit:** `3834b53` (de feitelijke task-3-commit, op de juiste branch).
- **Lesson voor toekomstige worktree-runs:** Wanneer een hook geen pad-prefix injecteert, expliciet de worktree absolute path gebruiken voor élke Write/Edit/Read — niet vertrouwen op `pwd` als impliciete root.

### Niet-aangeraakte items (binnen scope)

- **Docs-sync skill drift-check:** Twee `PostToolUse:Write` hooks gaven een docs-sync trigger door (de ADR is een docs-artifact). Niet uitgevoerd in deze plan-run, want plan 06-01's `files_modified`-whitelist is strikt: `.docs/decisions/cashier-mollie-compat.md` + `.planning/REQUIREMENTS.md`. Een docs-sync run zou potentieel `.docs/README.md`, `CLAUDE.md` of memory aanraken — buiten chirurgische scope. **Aanbeveling als deferred follow-up:** user runt `/docs-sync` (of de skill direct) als losse pass voordat de Phase 6 implementatie-plannen worden uitgevoerd, zodat eventuele drift in `.docs/README.md` of memory niet meegroeit met plannen 06-02+.

## Volgende stap voor de user

Run `/gsd-plan-phase 6 --gaps` (of een fresh planner-run) met deze ADR als locked context. De planner gebruikt de `## Consequences`-sectie van de ADR plus `06-DEFERRED-PLANS.md` om plannen 06-02 t/m 06-08 te genereren conform pad (a).

## Verwijzingen

- ADR: `.docs/decisions/cashier-mollie-compat.md` (gitignored — lokaal op disk in deze worktree én in main repo)
- Deferred plan-set: `.planning/phases/06-cashier-mollie-integratie-use-case-a/06-DEFERRED-PLANS.md`
- Phase context: `.planning/phases/06-cashier-mollie-integratie-use-case-a/06-CONTEXT.md`
- Requirement: `.planning/REQUIREMENTS.md` §SUB-01

## Self-Check: PASSED

- FOUND: `.docs/decisions/cashier-mollie-compat.md` (gitignored maar op disk in deze worktree)
- FOUND: `.planning/REQUIREMENTS.md` (SUB-01 row geüpdatet)
- FOUND: `.planning/phases/06-cashier-mollie-integratie-use-case-a/06-01-SUMMARY.md` (deze file)
- FOUND: commit `3834b53` (`docs(06-01): cashier-mollie compat-ADR + SUB-01 status in-progress`)
- ADR-content geverifieerd: 1× `^## Decision$`, 1× `^## Next Steps$`, 4× verwijzing naar `06-DEFERRED-PLANS`, `**Gekozen pad: \`a\`**` matched exact.
- Productie-code untouched: `git diff HEAD~1 HEAD -- app/ routes/ database/ tests/ config/ composer.json composer.lock` is leeg.
