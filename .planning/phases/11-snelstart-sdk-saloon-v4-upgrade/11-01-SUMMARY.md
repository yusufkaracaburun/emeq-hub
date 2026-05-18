---
phase: 11-snelstart-sdk-saloon-v4-upgrade
plan: 01
subsystem: snelstart-sdk
tags: [release, security, saloon-v4, sdk]
status: awaiting-checkpoint
requires:
  - SDK master tip with Saloon v4 already migrated (commits e9aff1a, e71a9bf, f403fc6, e9076d4)
provides:
  - SDK CHANGELOG.md v0.2.0 entry (Saloon v3 → v4 + 3 advisories closed)
  - SDK local release-commit `ce7c66c` on master
  - Awaiting user approval for `git tag v0.2.0` + push to SDK remote
affects:
  - packages/snelstart-api/CHANGELOG.md
tech-stack:
  added: []
  patterns:
    - Conventional-commits release-prep (`chore(release): vX.Y.Z`) zonder code-changes
key-files:
  created: []
  modified:
    - packages/snelstart-api/CHANGELOG.md
decisions:
  - Spring direct van v0.1.0 naar v0.2.0 (geen patch-bumps tussendoor) — milestone-alignment met v0.2 SDK-state.
  - Blocking-human-checkpoint vóór tag + push (CLAUDE.md `=== .ai/git-policy rules ===`).
metrics:
  duration_min: 5
  tasks_completed: 2
  tasks_pending: 1
  completed_date: 2026-05-18
requirements:
  - SNEL-03 (in-progress, sluit met Plan 11-02 + 11-03)
---

# Phase 11 Plan 01: snelstart-sdk-saloon-v4-upgrade — Release prep + tag-checkpoint Summary

Plan 11-01 dichtte het SDK-deel van SNEL-03 dicht: CHANGELOG-entry voor v0.2.0 geschreven, Pest-suite groen bevestigd, release-commit lokaal in de SDK-working-tree. Tag-plaatsing + push wachten op user-approval onder een blocking-checkpoint (Task 3).

## What Was Built

### Task 1 — SDK CHANGELOG v0.2.0 entry

- `packages/snelstart-api/CHANGELOG.md`: vervangen `## Unreleased` (stale: Saloon ^3.0) door `## v0.2.0 - 2026-05-18` met vier subsecties:
  - **Changed** — Saloon v3 → v4 bump als breaking-change gemarkeerd; `resolveBaseUrl()` als enige URL-source genoemd; `getRequestException()`-hook + `FatalRequestException` / `RequestException` retry-pad bevestigd.
  - **Security** — alle drie PKSA-ID's letterlijk genoemd met categorisering (HIGH / MEDIUM / MEDIUM), CVE-koppeling en bron-link naar `packagist.org/.../advisories`.
  - **Added** — `SnelstartWebhookSignature` (HMAC verify+sign) + `VerifySnelstartSignature` middleware (commits `e71a9bf` + `f403fc6` uit upgrade-window).
  - **Notes** — Pest-baseline: 122 passed / 211 assertions op master-tip.

Conform `.ai/rules/global.md`: tekst Engels (OSS-pattern), geen marketing-taal, geen fenced code blocks voor identifier-lijsten.

### Task 2 — Pest-suite verificatie + lokale SDK-commit

- Verse Pest-run vanuit `packages/snelstart-api/`: **122 passed / 211 assertions** in 2.34s — ruim boven de SC-grens van ≥107.
- SDK-working-tree commit `ce7c66c` op branch `master`:
  - Subject: `chore(release): v0.2.0 — Saloon v4 upgrade + 3 security advisories closed`
  - Body in Nederlands (NL conventie voor commit-bodies), met `Co-Authored-By: Claude Opus 4.7`.
  - Diff: `+19 / -4` regels in CHANGELOG.md.
- Geen push uitgevoerd. `git log origin/master..HEAD --oneline` toont 1 lokale commit ahead.

### Task 3 — Checkpoint reached (awaiting user approval)

Niet uitgevoerd. Pauze met `<resume-signal>` keuzes:
- `approved` → tag `v0.2.0` op huidige `master`-tip (`ce7c66c`) + push commit + tag naar `git@github.com:yusufkaracaburun/emeq-snelstart-api.git`.
- `branch-en-pr` → eerst release-branch + PR-pad vóór tag (extra review-gate).
- `stop` → eindig hier; Plan 11-02 kan dan niet starten (geen getagde release om `dev-master` mee te vervangen).

## Deviations from Plan

None — plan executed exactly as written. Geen Rule 1/2/3 auto-fixes nodig; geen Rule 4 architectural surprises.

## Verification Evidence

| Check | Command | Result |
| --- | --- | --- |
| CHANGELOG v0.2.0 header | `grep '^## v0.2.0' packages/snelstart-api/CHANGELOG.md` | OK |
| 3 PKSA-ID's letterlijk in tekst | `grep PKSA-… packages/snelstart-api/CHANGELOG.md` | 3/3 OK |
| Saloon-vermelding + breaking-marker | `grep -E 'resolveBaseUrl\|breaking' …` | OK (beide) |
| Stale `## Unreleased` weg | `! grep '^## Unreleased' …` | OK |
| Pest groen ≥107 | `./vendor/bin/pest --compact` | 122 passed / 211 assertions |
| SDK commit gemaakt | `git -C packages/snelstart-api log --oneline -1` | `ce7c66c chore(release): v0.2.0 …` |
| Geen push naar remote | `git -C packages/snelstart-api log origin/master..HEAD --oneline` | 1 lokale commit ahead |
| SDK working-tree clean | `git -C packages/snelstart-api status --short` | (empty) |

## Commits

### SDK repo (`packages/snelstart-api`, branch `master`, NOT pushed)

- `ce7c66c` — `chore(release): v0.2.0 — Saloon v4 upgrade + 3 security advisories closed`

### Hub repo (`emeq-hub`, branch `gsd/phase-11-snelstart-sdk-saloon-v4-upgrade`)

- (volgt) — `docs(11-01): complete plan 11-01 — SDK release-prep + checkpoint awaiting tag-approval`

## Open Questions / Next Action

Wacht op `<resume-signal>` (`approved` / `branch-en-pr` / `stop`). Bij `approved`:
1. `git -C packages/snelstart-api tag -a v0.2.0 -m "v0.2.0 — Saloon v4 upgrade"`
2. `git -C packages/snelstart-api push origin master`
3. `git -C packages/snelstart-api push origin v0.2.0`

Bij `branch-en-pr`: eerst nieuwe release-branch (b.v. `release/v0.2.0`) vanaf de huidige `master`-tip, push branch, GitHub PR aanmaken, na merge tag plaatsen op de mergecommit.

## Docs-Drift Note

PostToolUse-hook signaleerde "SDK-package edit → docs-sync trigger" na CHANGELOG.md-edit. Beoordeeld: deze edit is een release-prep change zonder API-impact (geen klassen verplaatst, geen routes, geen migratie). Geen ADR vereist; geen wijzigingen aan `.docs/`-index. Docs-sync skill draaien wordt aanbevolen na Task 3 + Plan 11-02/11-03 (dan landt de Hub-side `composer update` + `composer.lock` change en is dat de natuurlijke trigger voor een grotere sweep).

## Self-Check: PASSED

- `packages/snelstart-api/CHANGELOG.md` exists with `## v0.2.0` header — FOUND.
- SDK commit `ce7c66c` in `git -C packages/snelstart-api log` — FOUND.
- Hub branch is `gsd/phase-11-snelstart-sdk-saloon-v4-upgrade` (correct, niet `master`) — FOUND.
- No accidental Hub-side commits to `packages/snelstart-api/*` paths (gitignored) — verified (`git status --short` toont enkel `.planning/STATE.md` + SUMMARY.md).
