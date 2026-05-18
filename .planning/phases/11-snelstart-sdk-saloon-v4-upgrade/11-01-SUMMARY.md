---
phase: 11-snelstart-sdk-saloon-v4-upgrade
plan: 01
subsystem: snelstart-sdk
tags: [release, security, saloon-v4, sdk]
status: complete
requires:
  - SDK master tip with Saloon v4 already migrated (commits e9aff1a, e71a9bf, f403fc6, e9076d4)
provides:
  - SDK CHANGELOG.md v0.2.0 entry (Saloon v3 → v4 + 3 advisories closed)
  - SDK release-commit `ce7c66c` on master, pushed to origin
  - SDK annotated tag `v0.2.0` (tag-object `65161ed`, points to commit `ce7c66c`), pushed to origin
affects:
  - packages/snelstart-api/CHANGELOG.md
tech-stack:
  added: []
  patterns:
    - Conventional-commits release-prep (`chore(release): vX.Y.Z`) zonder code-changes
    - Annotated git tag (`-a -m`) voor SDK-releases — Composer-VCS-resolution pakt tag-object voor `^0.2.0` constraint.
key-files:
  created: []
  modified:
    - packages/snelstart-api/CHANGELOG.md
decisions:
  - Spring direct van v0.1.0 naar v0.2.0 (geen patch-bumps tussendoor) — milestone-alignment met v0.2 SDK-state.
  - Blocking-human-checkpoint vóór tag + push (CLAUDE.md `=== .ai/git-policy rules ===`).
  - User approved `approved`-pad (push direct naar `master` + tag) op 2026-05-18 — geen release-branch + PR-flow.
metrics:
  duration_min: 7
  tasks_completed: 3
  tasks_pending: 0
  completed_date: 2026-05-18
requirements:
  - SNEL-03 (in-progress, sluit met Plan 11-02 + 11-03)
---

# Phase 11 Plan 01: snelstart-sdk-saloon-v4-upgrade — Release prep + tag-checkpoint Summary

Plan 11-01 sloot het SDK-deel van SNEL-03 af: CHANGELOG-entry voor v0.2.0 geschreven, Pest-suite groen bevestigd, release-commit `ce7c66c` op SDK-`master`, annotated tag `v0.2.0` geplaatst en samen met de commit gepusht naar `git@github.com:yusufkaracaburun/emeq-snelstart-api.git`. Plan 11-02 kan nu `^0.2.0` pinnen i.p.v. `dev-master`.

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

### Task 3 — Tag + push naar SDK-remote (approved)

User-approval (`approved`) ontvangen op 2026-05-18. Uitgevoerde acties op `packages/snelstart-api`:

1. `git tag -a v0.2.0 -m "v0.2.0 — Saloon v4 upgrade"` — annotated tag aangemaakt.
2. `git push origin master` — release-commit gepusht: `e9076d4..ce7c66c  master -> master`.
3. `git push origin v0.2.0` — tag gepusht: `[new tag] v0.2.0 -> v0.2.0`.

Resultaat geverifieerd via `git ls-remote --tags origin`:

- Tag-object SHA (annotated): `65161ed488b34dfb4c3e41ea8a7f984d88c9450a`
- Pointed commit SHA: `ce7c66c2179ad794a7df1cbd8ddfb2c10c4b1d45` (= release-commit op SDK-`master`)

Geen `--no-verify`, geen force-push, geen branch-switch in de SDK-repo. Conform `=== .ai/git-policy rules ===` block.

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
| Commit op remote | `git -C packages/snelstart-api push origin master` | `e9076d4..ce7c66c  master -> master` |
| Tag op remote | `git -C packages/snelstart-api ls-remote --tags origin \| grep v0.2.0` | `65161ed...  refs/tags/v0.2.0` + `ce7c66c...  refs/tags/v0.2.0^{}` |
| Tag-target SHA | `git -C packages/snelstart-api rev-list -n 1 v0.2.0` | `ce7c66c...` (= release-commit) |
| SDK working-tree clean | `git -C packages/snelstart-api status --short` | (empty) |

## Commits

### SDK repo (`packages/snelstart-api`, branch `master`, PUSHED to origin)

- `ce7c66c` — `chore(release): v0.2.0 — Saloon v4 upgrade + 3 security advisories closed`
- Tag `v0.2.0` (annotated, tag-object `65161ed`, points to `ce7c66c`)

### Hub repo (`emeq-hub`, branch `gsd/phase-11-snelstart-sdk-saloon-v4-upgrade`, NOT pushed)

- (this commit) — `docs(11): finalize SUMMARY 11-01 — SDK v0.2.0 tagged + pushed`

## Next Action

Plan 11-02 kan starten — Hub-side `composer require emeq/snelstart-api:^0.2.0` is nu mogelijk in plaats van `dev-master`. SDK-remote heeft de getagde release op `github.com:yusufkaracaburun/emeq-snelstart-api` (master tip = `ce7c66c`, tag `v0.2.0` → `ce7c66c`).

## Docs-Drift Note

PostToolUse-hook signaleerde "SDK-package edit → docs-sync trigger" na CHANGELOG.md-edit. Beoordeeld: deze edit is een release-prep change zonder API-impact (geen klassen verplaatst, geen routes, geen migratie). Geen ADR vereist; geen wijzigingen aan `.docs/`-index. Docs-sync skill draaien wordt aanbevolen na Task 3 + Plan 11-02/11-03 (dan landt de Hub-side `composer update` + `composer.lock` change en is dat de natuurlijke trigger voor een grotere sweep).

## Self-Check: PASSED

- `packages/snelstart-api/CHANGELOG.md` exists with `## v0.2.0` header — FOUND.
- SDK commit `ce7c66c` in `git -C packages/snelstart-api log` — FOUND.
- SDK tag `v0.2.0` on remote — FOUND (`ls-remote --tags origin` returns `65161ed... refs/tags/v0.2.0` + `ce7c66c... refs/tags/v0.2.0^{}`).
- SDK tag points to release-commit — verified (`rev-list -n 1 v0.2.0` = `ce7c66c2179ad794a7df1cbd8ddfb2c10c4b1d45`).
- Hub branch is `gsd/phase-11-snelstart-sdk-saloon-v4-upgrade` (correct, niet `master`) — FOUND.
- No accidental Hub-side commits to `packages/snelstart-api/*` paths (gitignored) — verified.
