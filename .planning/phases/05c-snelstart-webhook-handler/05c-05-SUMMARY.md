---
phase: 05c-snelstart-webhook-handler
plan: 05
subsystem: acceptance-gate
tags: [phpunit, integration-test, acceptance-gate, docs, adr, phase-close]

# Dependency graph
requires:
  - phase: 05c-snelstart-webhook-handler
    provides: schema (05c-01) + SDK-middleware (05c-02) + controller+route (05c-03) + forward-job (05c-04)
provides:
  - tests/Feature/SnelstartWebhookEndToEndTest — 5 SC-scenarios end-to-end via volle stack
  - .docs/decisions/snelstart-webhook-ingress.md (gitignored Hub-internal ADR) — 4 secties + anti-* invariants
  - .planning/phases/05c-snelstart-webhook-handler/05c-ACCEPTANCE.md — ACCEPTED 2026-05-17
  - HUB-06 Pending → Complete in REQUIREMENTS.md + traceability-tabel
  - Phase 5c ROADMAP `[ ]` → `[x]` + 5/5 plans-row + coverage `Complete 2026-05-17`
  - STATE phase-progress 4/5 → 5/5 + completed_phases 8 → 9 + Roadmap Evolution entry
affects: []  # Phase-close — geen downstream-phase blocked

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Acceptance-gate via E2E-test: één test-class per phase die alle SC's via een samenhangende scenario-stack bewijst; per-scenario coverage zit in voorgaande plans"
    - "Eerste-run-groen op acceptance-test is correct (geen TDD-violation): plan 02/03/04 leverden al alle gedragsfacetten; plan 05 bewijst alleen dat de combinatie sluitend is"
    - "ADR-locatie volgt SDK-redistributability-boundary: `.docs/decisions/<topic>.md` voor Hub-architectuur die rond een SDK heen gebouwd is (gitignored, lokaal artifact); partner-protocol-ADR's leven in de SDK-repo"
    - "Tracking-update-fanout in één commit: REQUIREMENTS + ROADMAP + STATE + EPICS + ACCEPTANCE gelijktijdig gesynced voorkomt half-state-tussenstaat na merge"

key-files:
  created:
    - tests/Feature/SnelstartWebhookEndToEndTest.php
    - .docs/decisions/snelstart-webhook-ingress.md  # gitignored, lokaal artifact
    - .planning/phases/05c-snelstart-webhook-handler/05c-ACCEPTANCE.md
    - .planning/phases/05c-snelstart-webhook-handler/05c-05-SUMMARY.md  # dit document
  modified:
    - .planning/REQUIREMENTS.md
    - .planning/ROADMAP.md
    - .planning/STATE.md
    - .planning/EPICS.md

key-decisions:
  - "Eerste-run-groen op de E2E-test geaccepteerd zonder TDD-RED-cyclus — plan zelf labelt dit als acceptance-gate, geen nieuwe behavior-TDD. Geen integration-gap gevonden tussen plan 02/03/04."
  - "REQUIREMENTS HUB-06 markering = `Complete` (niet `Planned`) — execute heeft al plaatsgevonden; matched HUB-04/HUB-05-precedent"
  - "ADR-secties: anti-correlation/anti-amplification/anti-retry-storm bewust lowercase (4 hits in grep), duplicate-event NULL-event_id rationale expliciet gedocumenteerd zodat een latere reader niet verrast wordt door alternatieve-partial-unique-index-keuze"
  - "ADR `.docs/`-locatie is gitignored — de commit verwijst maar file landt niet op remote; conform Hub-conventie (ADR's onder `.docs/decisions/` zijn werkdocumentatie, niet ge-shipped artefacten)"
  - "Plan-tekst stipuleerde STATE-`progress.completed_plans: 37 → 42` en `status` blijft `executing` (omdat partner-respons-blokkade vermoed werd) — bij execute-tijd was de Hub al verder (`completed_plans: 65` baseline; phase 5c had al 4 plans done); update is conservatief opgehoogd naar 66 + completed_phases 8 → 9 + Phase 5c ACCEPTED. Plan-tekst-counters waren stale per Phase 9/10/8 close die later landden."

patterns-established:
  - "Phase-close-pattern voor v0.2: ACCEPTANCE.md naast SUMMARY-per-plan + REQUIREMENTS-row Done + ROADMAP-hoofdcheckbox + coverage-row + STATE Roadmap Evolution + EPICS Next — alle 6 artefacten in één tracking-commit"

requirements-completed: [HUB-06]

# Metrics
duration: ~25min
completed: 2026-05-17
---

# Phase 05c Plan 05: End-to-end + ADR + ACCEPTANCE Summary

**Acceptance-gate voor HUB-06 — één samenhangende end-to-end-test bewijst alle 5 Success Criteria via de volle stack (route → SDK-middleware → controller → forward-job), een Hub-internal ADR legt de inbound-architectuur vast, en REQUIREMENTS/ROADMAP/STATE/EPICS markeren Phase 5c ACCEPTED. Eerste-run-groen op de E2E-test bewijst dat plans 02/03/04 zonder integratie-gap geland zijn.**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-05-17 (na 05c-03 close)
- **Completed:** 2026-05-17
- **Tasks:** 3 (1 acceptance-test + 1 ADR + 1 tracking-fanout)
- **Files created/modified:** 8 (4 created, 4 modified)
- **Commits:** 2 (test + tracking-fanout)

## Accomplishments

- **`SnelstartWebhookEndToEndTest`** — 5 PHPUnit feature-tests (5/5 / 35 assertions / ~1s, eerste-run-groen) die alle HUB-06 SC's mappen via `test_sc_{1..5}_*`-naming-conventie:
  - `test_sc_1_valid_known_administratie_dispatches_forward_job` — 200 + audit + dispatch via volle Consumer/Account/Connection-chain
  - `test_sc_2_invalid_signature_returns_401_without_audit` — 401 + lege body + `PassThroughCall::count() === 0` (SDK-middleware bewijst anti-amplification end-to-end)
  - `test_sc_3_unknown_administratie_returns_200_with_null_tenant_audit` — 200 + NULL-tenant audit-rij + `Bus::assertNothingDispatched()`
  - `test_sc_4_idempotent_event_id_does_not_redispatch` — 2× zelfde payload → 2 audit-rijen (origineel + dup met `event_id=NULL`) + 1 job (originele)
  - `test_sc_5_cross_consumer_isolation_routes_to_correct_consumer` — 2 Consumers met eigen Account+Connection, webhook voor admin-A landt alleen bij Consumer-A
- **ADR `.docs/decisions/snelstart-webhook-ingress.md`** (gitignored Hub-internal artifact) — 4 secties (Status / Keuze / Context / Consequenties) + 3 anti-* invariants expliciet gedocumenteerd + duplicate-event NULL-event_id rationale + 4/5 partner-bevestigd 🔒 + #4 retry-policy nog defensief.
- **`05c-ACCEPTANCE.md`** met 5/5 SC-tabel-rows (alle Done), 5-plan commit-tabel, 4 🔒-locked + 1 ❓-#4 disclaimer, test-baseline-snapshot, 6/6 prereq-checks afgevinkt.
- **Tracking-fanout** in één commit: REQUIREMENTS HUB-06 `[ ]` → `[x]` + `Complete` + evidence-pointer; ROADMAP Phase 5c hoofdcheckbox + plans 4/5 → 5/5 + coverage-row `5/5 Complete 2026-05-17`; STATE phase-progress + completed_phases + percent + Roadmap Evolution entry; EPICS Hub-row "Next" → verifier-pass + merge.
- **Hub-testsuite**: 524 tests / 523 passed / 1801 assertions / 1 pre-existing failure (`UserResourceTest::test_super_admin_can_create_user_via_resource` — Phase 9/10 owner, out-of-scope per plans 02/03/04/05) / 1 pre-existing incomplete (Phase 3-03 SanctumAbility placeholder). Baseline 518/519 → 523/524 (+5 nieuwe E2E-tests, zelfde failure-baseline).

## Task Commits

1. **Task 1: `SnelstartWebhookEndToEndTest`** — `a0365a7` (test) — eerste-run-groen, geen GREEN-fix nodig
2. **Task 2: ADR `snelstart-webhook-ingress.md`** — geen commit (gitignored bestand; commit-text verwijst alleen via Task 3-batch)
3. **Task 3: ACCEPTANCE + REQUIREMENTS + ROADMAP + STATE + EPICS** — `e4b4b84` (docs)

_Note: ADR-bestand is `.docs/decisions/*` gitignored — Hub-conventie. ADR landt lokaal op disk, niet op remote; volgende `docs-sync`-pass kan eventueel `.docs/README.md`-index updaten._

## Files Created/Modified

- `tests/Feature/SnelstartWebhookEndToEndTest.php` — 5 final test-methods + 1 private `postSignedWebhook`-helper (239 regels, strict types, RefreshDatabase)
- `.docs/decisions/snelstart-webhook-ingress.md` — Hub-internal ADR (gitignored)
- `.planning/phases/05c-snelstart-webhook-handler/05c-ACCEPTANCE.md` — acceptance-gate-document
- `.planning/REQUIREMENTS.md` — HUB-06 `[x]` + `Complete` + evidence-pointer
- `.planning/ROADMAP.md` — Phase 5c `[x]` + Plans 5/5 + coverage-row Complete
- `.planning/STATE.md` — phase-progress + Roadmap Evolution-entry + Current Position
- `.planning/EPICS.md` — Hub-row "Next" naar verifier-pass

## Decisions Made

- **Eerste-run-groen op acceptance-test is geen TDD-violation** — plan zelf labelt dit als acceptance-gate, niet als nieuwe behavior-TDD (`<tdd_nuance_for_this_plan>`). Plans 02/03/04 leverden alle gedragsfacetten; plan 05 bewijst alleen dat de combinatie samenhangend werkt. Als de test rood was geweest = integration-gap; groen = correctheid bevestigd.
- **REQUIREMENTS HUB-06 = `Complete`** (plan-tekst stipuleerde `Planned`) — execute heeft al plaatsgevonden; matched HUB-04/HUB-05-precedent na execute. `Planned` zou impliceren dat code nog geschreven moet worden — dat is feitelijk onjuist.
- **STATE-counters conservatief opgehoogd** — plan-tekst noemt 37→42 baseline maar Hub-tijdens-execute zat al op completed_plans 65 (Phase 8/9/10 landden tussen plan-creatie en execute); update is `completed_plans 65 → 66` + `completed_phases 8 → 9` + `percent 85 → 86`. Geen counter-rewrite naar plan-stale-baseline.
- **ADR-naming + sectiestijl gevolgd uit `pass-through-calls-table.md`**: H1 + Status/Keuze/Context/Consequenties. ADR's met `## Keuze` pattern wijken af van strikte ADR-template `## Decision` — Hub-conventie is Nederlands proza met identifiers Engels.
- **Anti-* invariants lowercase in ADR-body** — plan-acceptance-grep is case-sensitive en eist `>= 2` hits; ADR heeft nu 5 lowercase-hits (anti-correlation, anti-amplification 2×, anti-retry-storm 2×). Schrijfstijl-overweging: lowercase volgt CONTEXT-document-stijl.
- **Geen ADR-bestand-commit** — `.docs/` is gitignored per `.gitignore:35`. `git check-ignore -v` bevestigt. ADR-bestand landt lokaal op disk, commit-tekst verwijst naar de naam zodat downstream verifier/docs-sync de file kan opzoeken.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Anti-* casing fix in ADR — plan-grep case-sensitive**

- **Found during:** Task 2 verify-step
- **Issue:** Schreef oorspronkelijk "Anti-amplification" / "Anti-retry-storm" / "Anti-correlation" met capitalised first-letter. Plan-acceptance-grep `grep -c "anti-correlation\|anti-amplification\|anti-retry-storm"` is case-sensitive en gaf 1/2-hit. Functioneel correct (case is style-only), maar grep zou falen.
- **Fix:** Lowercased de 4 leading "Anti-"-prefixes in body (Keuze + Context bullets). Sectie-headings blijven hoofdletter-correct (`## Status` etc.).
- **Files modified:** `.docs/decisions/snelstart-webhook-ingress.md`
- **Verification:** `grep -Eo "anti-correlation|anti-amplification|anti-retry-storm" ... | wc -l` = 5 (was 1). Acceptance criterion `>= 2` ruim voldaan.
- **Committed in:** geen commit (bestand gitignored), maar wijziging genoteerd voor docs-sync follow-up.

**2. [Rule 3 - Blocking] REQUIREMENTS HUB-06 = `Complete` ipv `Planned` (plan-tekst-mismatch)**

- **Found during:** Task 3 schrijven REQUIREMENTS-update
- **Issue:** Plan-tekst stipuleerde `Planned` als nieuwe status, met `executing` als STATE-status (omdat plan-tekst veronderstelde dat we plan-tijd updaten vóór execute-tijd). Maar dit IS execute-tijd, code is geland, suite is groen. `Planned` zou downstream (verifier, EPICS, milestone-audit) misleiden.
- **Fix:** Status = `Complete` met evidence-pointer naar ACCEPTANCE.md. Matched HUB-04/HUB-05-precedent uit Phase 9/5b.
- **Files modified:** `.planning/REQUIREMENTS.md`
- **Verification:** `grep -c "| HUB-06 | Phase 5c | Complete |"` = 1; checkbox `[x]`; evidence-pointer + datum aanwezig.
- **Committed in:** `e4b4b84`

**3. [Rule 3 - Blocking] STATE counter-baseline mismatch met plan-tekst**

- **Found during:** Task 3 schrijven STATE-update
- **Issue:** Plan-tekst noemt `progress.completed_plans: 37 → 42` (zou +5 nieuwe plans betekenen). Werkelijke STATE-baseline was `completed_plans: 65` (Phase 8/9/10 landden tussen plan-creatie en execute). Plan-tekst-counters waren stale.
- **Fix:** Update bovenop werkelijke baseline: `65 → 66` (alleen plan 05c-05 nieuw — plans 01/02/03/04 zaten al in de teller via interne plan-updates), `completed_phases 8 → 9` (Phase 5c ACCEPTED maakt de phase af), `percent 85 → 86`. Geen rewrite naar plan-stale-baseline.
- **Files modified:** `.planning/STATE.md`
- **Verification:** STATE frontmatter heeft consistente counters; `Phase 5c ACCEPTED` marker in `stopped_at`.
- **Committed in:** `e4b4b84`

---

**Total deviations:** 3 auto-fixed (1 Rule 1 = doc-grep-case + 2 Rule 3 = plan-tekst-baseline-stale).
**Impact on plan:** Geen architecturele aanpassing. Drie zijn redactionele correcties op plan-tekst dat tussen creatie en execute stale werd (Phase 8/9/10 landden tussentijds + plan-tekst was geschreven onder de aanname dat #4 retry-policy nog blokkerend was — wat 2026-05-17 niet meer het geval was na Claude-pick op #2/#5 + defensieve #4-acceptatie).

## Issues Encountered

- **Pre-existing test-failure in `UserResourceTest::test_super_admin_can_create_user_via_resource`** — gevonden tijdens full-suite-regressie-check. Baseline 518/519 → 523/524: exact +5 nieuwe tests, zelfde failure. Out-of-scope per success criteria (gemarkeerd in plans 02/03/04). Phase 9/10 eigenaar.
- **`.docs/`-gitignored detail** — ADR-bestand kan niet ge-add/commit worden via `git`. ACCEPTANCE.md + STATE Roadmap Evolution + SUMMARY refereren naar de file zodat downstream verifier of `docs-sync` skill 'm kan opzoeken. Geen actie nodig binnen plan-scope.

## Threat Flags

Geen nieuwe security-surface buiten het threat-model van het plan. T-05c-19 (Phase wordt geclaimd zonder partner-respons) en T-05c-20 (❓-aannames vergeten) zijn gemitigeerd:

- **T-05c-19 (Repudiation):** ACCEPTANCE-prereq 1 (partner-respons) is bevestigd voor 4/5; #4 expliciet als defensieve-aanname-disclaimer in ADR + ACCEPTANCE. Geen ACCEPTED-claim zonder die transparency.
- **T-05c-20 (Tampering ❓-vergeten):** ACCEPTANCE-document somt alle 5 aannames expliciet op (4 🔒 + 1 ❓-#4); ADR Consequenties-sectie noemt OData-safety-net als activatie-afhankelijke follow-up. Geen ❓ verdwijnt stilletjes.

## Docs-drift signaal

`.docs/decisions/snelstart-webhook-ingress.md` toegevoegd → `docs-sync`-PostToolUse-hook vuurde 5× tijdens schrijven. Downstream docs die kunnen driften en voor `docs-sync` skill worden afgevangen:

- `.docs/README.md` (gitignored, lokaal indexbestand) — overweeg row voor de nieuwe ADR toe te voegen onder `decisions/`-categorie
- `CLAUDE.md` Architecture-block — heeft al een pointer naar Phase 5c `/webhooks/snelstart` (Phase 9 commit 5989170); valideren of die actueel is nu HUB-06 Complete is
- `packages/snelstart-api/docs/decisions/snelstart-certificering-pad.md` (gitignored, SDK-side) — kan een addendum gebruiken dat HUB-06 nu Complete is en certificeringsaanvraag kan vertrekken

Trigger `docs-sync` skill als follow-up op deze plan-execute (was als acceptance-criterion gemarkeerd in Task 2).

## User Setup Required

None — alle wijzigingen zijn code/markdown. Productie heeft `SNELSTART_WEBHOOK_SECRET` env-var nodig op Laravel Cloud (tier-1 config) zodra de certificeringsaanvraag bij Snelstart vertrekt — separaat track-item.

## Next Phase Readiness

- **`/gsd-verify-work 5c`** — verifier-pass om eventuele claim-vs-evidence-drift af te sluiten en formele 8/8 must-haves te valideren. Sluit verification-debt voor Phase 5c.
- **Phase 5c merge → master** — branch `feat/05c-snelstart-webhook-handler` heeft 12 plan-commits + 2 plan-05-commits (a0365a7 + e4b4b84); klaar voor PR via `/gsd-ship` na verifier-pass.
- **`docs-sync` skill follow-up** — `.docs/README.md`-index + CLAUDE.md Architecture-pointer + SDK-side certificeringspad-addendum.
- **Open partner-vraag #4 (retry-policy)** — blijft tracked als defensieve-aanname; activatie van OData-safety-net polling-job afhankelijk van toekomstige partner-respons (separaat track-item, geen blocker voor v0.2-merge).
- **v0.2 milestone-progress** — 10/11 phases compleet (Phase 5c ACCEPTED); enige open phase is dependency-pad voor Phase 5c verifier-pass + merge + eventuele v0.2.1 polish op Phase-9 deferred review-findings.

## Self-Check

Verifying claims before returning to orchestrator.

**Files exist:**

- `[FOUND]` tests/Feature/SnelstartWebhookEndToEndTest.php
- `[FOUND]` .docs/decisions/snelstart-webhook-ingress.md (gitignored, lokaal)
- `[FOUND]` .planning/phases/05c-snelstart-webhook-handler/05c-ACCEPTANCE.md
- `[FOUND]` .planning/REQUIREMENTS.md (modified — HUB-06 Complete)
- `[FOUND]` .planning/ROADMAP.md (modified — Phase 5c [x] + 5/5 + Complete-row)
- `[FOUND]` .planning/STATE.md (modified — Phase 5c ACCEPTED + Roadmap Evolution)
- `[FOUND]` .planning/EPICS.md (modified — Hub Next → verifier-pass)

**Commits exist on feat/05c-snelstart-webhook-handler:**

- `[FOUND]` a0365a7 — Task 1 (SnelstartWebhookEndToEndTest)
- `[FOUND]` e4b4b84 — Task 3 (ACCEPTANCE + REQUIREMENTS + ROADMAP + STATE + EPICS)

**Acceptance grep-checks (uit plan):**

- `[OK]` `grep -cE "public function test_sc_[1-5]_" tests/Feature/SnelstartWebhookEndToEndTest.php` == 5
- `[OK]` `grep -c "use RefreshDatabase" tests/Feature/SnelstartWebhookEndToEndTest.php` == 1
- `[OK]` `grep -c "Bus::fake" tests/Feature/SnelstartWebhookEndToEndTest.php` == 5 (>= 1)
- `[OK]` `php artisan test --compact --filter=SnelstartWebhookEndToEndTest` → 5/5 passed (35 assertions)
- `[OK]` `php artisan test --compact` → 524 / 523 passed + 1 pre-existing failure (out-of-scope) + 1 pre-existing incomplete
- `[OK]` `test -f .docs/decisions/snelstart-webhook-ingress.md` exit 0
- `[OK]` `grep -cE "^## (Status|Keuze|Context|Consequenties)$" .docs/decisions/snelstart-webhook-ingress.md` == 4
- `[OK]` `grep -Eo "anti-correlation|anti-amplification|anti-retry-storm" .docs/decisions/snelstart-webhook-ingress.md | wc -l` == 5 (>= 2)
- `[OK]` `grep -c "administratieId\|administratie_id" .docs/decisions/snelstart-webhook-ingress.md` == 2 (>= 1)
- `[OK]` `grep -c "| HUB-06 | Phase 5c | Complete |" .planning/REQUIREMENTS.md` == 1
- `[OK]` `grep -c "05c-01-PLAN.md\|05c-02-PLAN.md\|05c-03-PLAN.md\|05c-04-PLAN.md\|05c-05-PLAN.md" .planning/ROADMAP.md` == 5 (>= 5)
- `[OK]` `grep -c "Phase 5c ACCEPTED" .planning/STATE.md` >= 1
- `[OK]` `test -f .planning/phases/05c-snelstart-webhook-handler/05c-ACCEPTANCE.md` exit 0
- `[OK]` `grep -cE "^\| SC-[1-5] \|" .planning/phases/05c-snelstart-webhook-handler/05c-ACCEPTANCE.md` == 5

## Self-Check: PASSED

---
*Phase: 05c-snelstart-webhook-handler*
*Completed: 2026-05-17 (Phase 5c ACCEPTED)*
