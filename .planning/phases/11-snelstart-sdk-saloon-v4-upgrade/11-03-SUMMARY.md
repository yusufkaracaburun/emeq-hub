---
phase: 11-snelstart-sdk-saloon-v4-upgrade
plan: 03
subsystem: snelstart-sdk
tags: [docs, adr, codebase-drift, state-sync, phase-closure]
status: complete
requires:
  - phase: 11-snelstart-sdk-saloon-v4-upgrade
    provides: SDK tag v0.2.0 (Plan 11-01, commit ce7c66c) + Hub composer-pin ^0.2.0 + audit-ignore-removal (Plan 11-02, commit 897c1e0).
provides:
  - Lokale ADR `.docs/decisions/snelstart-sdk-saloon-v4-upgrade.md` (gitignored werkdocument, Saloon v3 → v4 + 3 advisories closed)
  - `.planning/codebase/STACK.md` regel 150 + 151 gesynct (audit-ignores weg, stability-motivatie verschoven naar Mollie-alpha)
  - `.planning/codebase/CONCERNS.md` audit-ignores + snelstart-dev-master regels gemarkeerd Resolved 2026-05-18
  - `.planning/STATE.md` frontmatter progress (1 phase / 3 plans / 20%) + Current Position + Resolved Blockers + Next action options post-Phase-11
  - Smoke-test bewijs: Snelstart-subset 51 passed / 223 assertions / 1.466s op composer-pin `^0.2.0`
  - `composer audit --no-cache` exit 0 zonder ignores (post doc-changes)
affects:
  - Phase 11 closure (success-criterium #4 CHANGELOG/ADR-documentatie gedekt)
  - alle volgende v0.3 phases die op `emeq/snelstart-api ^0.2.x` bouwen
tech-stack:
  added: []
  patterns:
    - "ADR voor SDK-upgrade leeft in `.docs/decisions/` (lokaal-only, gitignored) — traceability via tracked SUMMARY + STATE.md Resolved Blockers"
    - "Resolved-conventie in `.planning/codebase/CONCERNS.md`: doorhaling ~~oud-risk~~ + `Resolved YYYY-MM-DD (Phase N)` met commit-SHA's (geïntroduceerd in dit plan; gevolgd na geen bestaande conventie te vinden)"
key-files:
  created:
    - .docs/decisions/snelstart-sdk-saloon-v4-upgrade.md (lokaal-only, gitignored)
    - .planning/phases/11-snelstart-sdk-saloon-v4-upgrade/11-03-SUMMARY.md
  modified:
    - .planning/STATE.md
    - .planning/codebase/STACK.md
    - .planning/codebase/CONCERNS.md
decisions:
  - "CONCERNS.md Resolved-conventie: doorhaling + `Resolved 2026-05-18 (Phase 11)` line met commit-SHA's. Geen bestaande conventie in CONCERNS.md gevonden; gekozen voor de stijl die `.planning/STATE.md` Resolved Blockers gebruikt voor consistentie."
  - "Naast de audit-ignores-regel (regel 188-191) ook de `emeq/snelstart-api: dev-master`-regel (regel 173-176) als resolved gemarkeerd — Plan 11-02's `^0.2.0`-pin sluit die ook materieel af; consistent doorvoeren voorkomt latere docs-sync-drift-flag."
  - "ADR-pad expliciet als `(lokaal)` gelabeld in STATE.md Resolved Blockers — voorkomt verwarring op CI/andere clones waar `.docs/` niet bestaat."
metrics:
  duration_min: 6
  tasks_completed: 3
  tasks_pending: 0
  completed_date: 2026-05-18
requirements:
  - SNEL-03 (closed in Plan 11-02; deze plan documenteert closure)
  - SNEL-04 (closed in Plan 11-02; deze plan documenteert closure)
---

# Phase 11 Plan 03: snelstart-sdk-saloon-v4-upgrade — ADR + docs-drift-sync Summary

Phase 11 sluit met dit plan: lokale ADR voor de Saloon v3 → v4 upgrade en drie closed advisories geschreven in `.docs/decisions/`, drie tracked docs-drift-edits gesynct (STACK.md regels 150 + 151, CONCERNS.md audit-ignores + dev-master regels, STATE.md frontmatter + Current Position + Resolved Blockers), één Hub-commit `bfcc9c3` voor de tracked artefacten. Smoke-subset 51/51 groen op de gepinte `^0.2.0`, `composer audit` exit 0.

## What Was Built

### Task 1 — Lokale ADR

Pad: `.docs/decisions/snelstart-sdk-saloon-v4-upgrade.md`
Aanmaakdatum: 2026-05-18
Redactie-status: compleet — bevat alle 3 PKSA-ID's (PKSA-xnj5-w74d-6wmz HIGH, PKSA-5szq-gvrg-ttfq MEDIUM, PKSA-rnpm-45mg-w6ht MEDIUM), SDK upgrade-commit `e9aff1a`, SDK release-commit `ce7c66c` (tag-object `65161ed`), Hub composer-update-commit `897c1e0`, en aanbeveling voor v0.4+ signed-tags.

Status conform `CLAUDE.md`: `.docs/` = "Werkdocumentatie (lokaal, gitignored)". `git check-ignore` bevestigt: `.gitignore:35:/.docs` matcht het pad. De file leeft alleen op deze checkout; traceability voor andere clones loopt via de tracked SUMMARY (dit document) en de STATE.md Resolved Blockers-regel die het pad expliciet noemt.

Secties: Titel, Status, Keuze, Context, Consequenties, Bronnen — gebaseerd op de stijl van `.docs/decisions/snelstart-webhook-ingress.md` (recente Snelstart-ADR). Nederlands, geen anti-AI-cliché's, compacte bullets.

### Task 2 — Tracked docs-drift-sync (3 files)

1. `.planning/codebase/STACK.md` regel 150 — Audit-regel vervangen:
   - Oud: `composer.json audit: ignore: ["PKSA-xnj5-w74d-6wmz", "PKSA-5szq-gvrg-ttfq", "PKSA-rnpm-45mg-w6ht"] (geïgnoreerde abandoned-advisories).`
   - Nieuw: `composer.json audit: alleen "abandoned": "report" (drie Saloon v3-advisories opgelost in Phase 11 — Saloon ^4.0 in SDK, composer audit exit 0 zonder ignores).`

2. `.planning/codebase/STACK.md` regel 151 — Stability-motivatie vervangen:
   - Oud: `minimum-stability: dev + prefer-stable: true om emeq/snelstart-api: dev-master toe te staan zonder volledige dev-resolutie.`
   - Nieuw: `minimum-stability: dev + prefer-stable: true om emeq/mollie-api: ^0.1.0-alpha.1 (en vergelijkbare alpha/dev-deps) toe te staan zonder volledige dev-resolutie. emeq/snelstart-api staat sinds Phase 11 op ^0.2.0 (stable tag).`
   - Verifier-pad: `grep -nE 'minimum-stability|alpha|"emeq/' composer.json` bevestigt `emeq/mollie-api: ^0.1.0-alpha.1` als de werkelijke alpha-dep die `minimum-stability: dev` motiveert. Geen afwijking van de plan-instructie.

3. `.planning/codebase/CONCERNS.md` — Twee regels gemarkeerd resolved (zie Deviations):
   - Audit-ignores-regel (regel 188-191): ~~doorhaling~~ + `Resolved 2026-05-18 (Phase 11)` met SDK-commit `ce7c66c` + Hub-commit `897c1e0` + verwijzing naar lokale ADR.
   - `emeq/snelstart-api: dev-master`-regel (regel 173-176): ~~doorhaling~~ + `Resolved 2026-05-18 (Phase 11)` met SDK-tag `v0.2.0` + Hub-commit `897c1e0`.

4. `.planning/STATE.md` — Vier sub-edits:
   - Frontmatter `progress`: `completed_phases` 0→1, `completed_plans` 2→3, `percent` 0→20.
   - Frontmatter `last_updated`: `"2026-05-18T09:00:38.000Z"` (vers, match bestaand quoted-ISO-format).
   - Frontmatter `status` + `stopped_at`: bijgewerkt naar `Phase 11 complete — Saloon v4 upgrade docs-closure (3/3 plans, ADR lokaal + audit clean)`.
   - Current Position: `Phase: 11 — Snelstart-SDK Saloon v4 upgrade (Complete 2026-05-18, 3/3 plans)`; Plan = idle.
   - Resolved Blockers: nieuwe regel toegevoegd met alle 3 PKSA-ID's letterlijk + ADR-pad-verwijzing.
   - Next action options: Phase 11-optie weg; nieuwe lijst = Phase 12 (Snelstart-cert), 13 (Mollie Connect resources), 15 (verification-debt).

### Task 3 — Smoke-test + commit

**Smoke-test (PHPUnit subset op Hub):**

```
php artisan test --compact tests/Feature/Api/V1/Snelstart \
  tests/Feature/SnelstartWebhookControllerTest.php \
  tests/Feature/SnelstartWebhookEndToEndTest.php \
  tests/Feature/ForwardSnelstartWebhookToConsumerJobTest.php \
  tests/Feature/PassThroughCallInboundScopesTest.php \
  tests/Feature/ConnectionAdministratieIdTest.php
```

Output: `{"tool":"phpunit","result":"passed","tests":51,"passed":51,"assertions":223,"duration_ms":1466}` — boven baseline 45 / 207.

**Composer audit:**

```
composer audit --no-cache
→ No security vulnerability advisories found.
```

Exit 0 zonder ignores. SNEL-04 acceptance herbevestigd na doc-changes.

**Commit:**

`bfcc9c3 docs(11): sync codebase-drift + STATE.md closure voor Saloon v4 upgrade` op branch `gsd/phase-11-snelstart-sdk-saloon-v4-upgrade`. Exact 3 gewijzigde files: `.planning/STATE.md` (27 lines), `.planning/codebase/CONCERNS.md` (16 lines), `.planning/codebase/STACK.md` (4 lines). Totaal: +24 / -23.

Geen `.docs/`-paths in de commit — `git show --stat HEAD` bevestigt 3 file-paths, allemaal onder `.planning/`. Lokale ADR-file blijft op de host aanwezig en wordt door `git check-ignore -v .docs/decisions/snelstart-sdk-saloon-v4-upgrade.md` correct gematcht tegen `.gitignore:35:/.docs`.

Geen push uitgevoerd. Conform `=== .ai/git-policy rules ===`.

## Deviations from Plan

### Rule 2 — Auto-add missing critical functionality

**1. [Rule 2 — Docs-drift completeness] CONCERNS.md `emeq/snelstart-api: dev-master`-regel óók gemarkeerd resolved**

- **Found during:** Task 2 (CONCERNS.md scan voor resolved-conventie)
- **Issue:** Plan-instructie target alleen regel 188-191 (audit-ignores). Maar de gerelateerde regel 173-176 (`emeq/snelstart-api: dev-master` risk) is via Plan 11-02's `^0.2.0`-pin ook materieel resolved. Niet markeren zou bij de eerstvolgende `docs-sync`-skill-run als drift-finding worden gevlagd ("stale risk-claim na composer.json-change").
- **Fix:** Beide regels markeren met dezelfde ~~doorhaling~~ + `Resolved 2026-05-18 (Phase 11)` conventie. Pre-emptive drift-closure.
- **Files modified:** `.planning/codebase/CONCERNS.md` (2 risk-blocks i.p.v. 1)
- **Commit:** `bfcc9c3`

### Documented Convention Choice

**2. [Convention] CONCERNS.md Resolved-conventie nieuw geïntroduceerd**

- `grep -i 'resolved' .planning/codebase/CONCERNS.md` retourneerde 0 hits vóór dit plan — eerste resolution in deze file.
- Plan-instructie: "Lees omliggende regels 185-195 om de conventie te bepalen". Er was geen bestaande conventie te lezen.
- Keuze: doorhaling + `Resolved 2026-05-18 (Phase 11)` + commit-SHA's + ADR-pad. Gebaseerd op de `.planning/STATE.md` Resolved Blockers-stijl (`~~text~~ — closed YYYY-MM-DD via ...`) voor consistentie binnen het `.planning/`-pad.
- Conventie nu vastgelegd in deze SUMMARY's `tech-stack.patterns` voor toekomstige resolutions.

### Out-of-Scope (not fixed)

Geen out-of-scope-findings tijdens Task 1-3. Pre-existing UserResource-failure uit Plan 11-02 staat al gelogd in `deferred-items.md` en is bewust niet aangeraakt (SCOPE BOUNDARY).

---

**Total deviations:** 1 Rule 2 (auto-applied), 1 convention choice (documented), 0 Rule 4 (architectural).

## Verification Evidence

| Check | Command | Result |
| --- | --- | --- |
| ADR exists + gitignored | `git check-ignore -v .docs/decisions/snelstart-sdk-saloon-v4-upgrade.md` | `.gitignore:35:/.docs` (matched) |
| ADR sections present | `grep -E '^## (Status\|Keuze\|Context\|Consequenties\|Bronnen)' .docs/decisions/snelstart-sdk-saloon-v4-upgrade.md` | 5/5 OK |
| ADR has all 3 PKSA-IDs | `grep -c 'PKSA-' .docs/decisions/snelstart-sdk-saloon-v4-upgrade.md` | ≥3 OK |
| STACK.md PKSA-IDs weg | `grep -c 'PKSA-' .planning/codebase/STACK.md` | 0 OK |
| STACK.md mentions Phase 11 | `grep -c 'Phase 11' .planning/codebase/STACK.md` | 2 OK |
| STACK.md old dev-master phrase weg | `! grep 'emeq/snelstart-api: dev-master toe te staan' .planning/codebase/STACK.md` | GONE |
| STATE.md frontmatter progress | `grep -E 'completed_phases:\|completed_plans:\|percent:' .planning/STATE.md` | `1 / 3 / 20` |
| STATE.md last_updated format | `grep -E 'last_updated: "2026-05-18T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{3}Z"'` | OK (`09:00:38.000Z`) |
| STATE.md last_activity bare-date | `grep -E '^last_activity: 2026-05-18$'` | OK |
| STATE.md Current Position | `grep 'Phase: 11 — Snelstart-SDK Saloon v4 upgrade (Complete'` | OK |
| STATE.md Resolved Blockers Saloon v3 line | `grep 'Saloon v3 → v4'` | OK (toegevoegd) |
| CONCERNS.md resolved markers | `grep -c 'Resolved 2026-05-18'` | 2 OK |
| Snelstart smoke-subset passes | `php artisan test --compact tests/Feature/Api/V1/Snelstart ...` | 51 passed / 223 assertions / 1.466s |
| Composer audit exit 0 | `composer audit --no-cache` | `No security vulnerability advisories found.` |
| Commit has 3 files | `git show --stat HEAD` | 3 files (`STATE.md` + `CONCERNS.md` + `STACK.md`), +24 / -23 |
| No `.docs/` in commit | `git show --stat HEAD \| grep '\.docs/'` | (empty) |
| Working tree clean | `git status --short` | (empty) |

## Task Commits

1. **Task 1 (ADR):** geen git-commit — file leeft lokaal in gitignored `.docs/decisions/snelstart-sdk-saloon-v4-upgrade.md`.
2. **Task 2 + Task 3 (3 tracked files, combined per plan-instructie):** `bfcc9c3 docs(11): sync codebase-drift + STATE.md closure voor Saloon v4 upgrade`.

## Files Created/Modified

- `.docs/decisions/snelstart-sdk-saloon-v4-upgrade.md` — **lokaal, gitignored**. ADR met Status / Keuze / Context / Consequenties / Bronnen.
- `.planning/codebase/STACK.md` — regel 150 + 151 vervangen (audit-ignores weg, stability-motivatie verschoven naar Mollie-alpha post-pinning).
- `.planning/codebase/CONCERNS.md` — audit-ignores-block (regel 188-191) en `emeq/snelstart-api: dev-master`-block (regel 173-176) gemarkeerd `~~text~~ Resolved 2026-05-18 (Phase 11)`.
- `.planning/STATE.md` — frontmatter `progress` (1 / 3 / 20%), `last_updated`, `status`, `stopped_at`, Current Position, Resolved Blockers, Last session, Stopped at, Next action options.

## Phase 11 Closure — ROADMAP success-criteria mapping

Alle 4 success-criteria van Phase 11 (per `ROADMAP.md`) zijn nu gedekt door de combinatie 11-01 + 11-02 + 11-03:

1. **SDK Saloon v3 → v4 upgrade** — Plan 11-01: SDK getagd `v0.2.0`, commit `ce7c66c`, Pest 122 passed / 211 assertions.
2. **Hub composer-pin op stable tag (geen `dev-master`)** — Plan 11-02: `composer.json` `^0.2.0`, `composer.lock` resolved op commit `ce7c66c`, commit `897c1e0`.
3. **Audit-ignores verwijderd, `composer audit` exit 0 zonder ignores** — Plan 11-02 + herbevestigd in Plan 11-03 Task 3 Stap B: alle 3 PKSA-ID's weg, exit 0, `No security vulnerability advisories found.`
4. **CHANGELOG + ADR-documentatie** — Plan 11-01: SDK `CHANGELOG.md` v0.2.0-entry met PKSA-IDs, breaking-change marker, Pest-baseline. Plan 11-03: Hub-side ADR `.docs/decisions/snelstart-sdk-saloon-v4-upgrade.md` (lokaal werkdocument) met volledige rationale + bron-links + Bronnen-sectie.

SNEL-03 (Saloon v3 → v4) en SNEL-04 (audit exit 0 zonder ignores) zijn nu formeel afgesloten — alle 4 SC's documentair en functioneel gedekt.

## Next Phase Readiness

- **Phase 12 (Snelstart productie-certificering)** is volgende natuurlijke stap. Hub-side ingress al compleet (Phase 5c), wacht op partner-respons op Gmail-draft `r-8836998535038336548` (deadline ≤2026-05-26). Saloon v4 + clean audit verwijdert het laatste security-blok dat de certificeringsaanvraag had kunnen vertragen.
- **Phase 13 (Mollie Connect partner-resources)** kan parallel — geen Saloon-dependency, geen overlap met Snelstart-state.
- **Phase 15 (verification-debt backfill)** blijft optioneel low-risk doc-track.
- **Outstanding:** Pre-existing UserResource-test-failure → losse quick-task of inclusion in latere Hub-side polish-phase (gelogd in `.planning/phases/11-snelstart-sdk-saloon-v4-upgrade/deferred-items.md`).

## Docs-Sync Trigger

PostToolUse-hook signaleerde "Docs-drift trigger: een docs zelf" na het schrijven van de ADR. Trigger noteer: dit plan is zelf een docs-sweep (Task 2 = drie tracked drift-fixes in `.planning/codebase/`). Een aparte `docs-sync`-skill-run is niet langer nodig voor Phase 11 — de skill-checks 1-3 (stale class-refs, ontbrekende ADR, completed TODO's) zijn binnen dit plan gedekt. Checks 4-6 (structuur-drift, verweesde docs, dode links) vallen buiten de plan-scope en kunnen optioneel later draaien als losse `/gsd-quick` of `/gsd-debug`-sessie.

## Self-Check: PASSED

- `.docs/decisions/snelstart-sdk-saloon-v4-upgrade.md` exists locally — FOUND.
- File is gitignored (`.gitignore:35:/.docs`) — VERIFIED via `git check-ignore -v`.
- `.planning/STATE.md` has `completed_phases: 1`, `completed_plans: 3`, `percent: 20` — FOUND.
- `.planning/STATE.md` Resolved Blockers has Saloon v3 → v4 line — FOUND.
- `.planning/codebase/STACK.md` has 0 PKSA-IDs and 2 "Phase 11" mentions — VERIFIED.
- `.planning/codebase/CONCERNS.md` has 2 `Resolved 2026-05-18` markers — VERIFIED.
- Commit `bfcc9c3` in `git log` — FOUND.
- Commit touches exactly 3 files, all under `.planning/` — VERIFIED via `git show --stat HEAD`.
- `composer audit --no-cache` exit 0 — VERIFIED.
- Snelstart-subset 51/51 — VERIFIED.

---
*Phase: 11-snelstart-sdk-saloon-v4-upgrade*
*Plan: 03*
*Completed: 2026-05-18*
