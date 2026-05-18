---
phase: 13-mollie-connect-partner-resources
plan: 04
subsystem: docs
tags: [adr, mollie, mollie-connect, partner-token, phase-closure, requirements-closure, docs]

requires:
  - phase: 13-mollie-connect-partner-resources
    plan: 01
    provides: MollieAccessTokenResolver + pass_through_calls.token_type + 503 partner_token_missing mapper-branch
  - phase: 13-mollie-connect-partner-resources
    plan: 02
    provides: AbstractMollieConnectPassThroughController + 5 controllers + 9 routes
  - phase: 13-mollie-connect-partner-resources
    plan: 03
    provides: 23 nieuwe tests (17 per-resource + 3 token-resolver + 3 Scramble) — bewijs voor MOLL-05 SC-1, MOLL-05 SC-3, MOLL-06 SC-2
provides:
  - .docs/decisions/mollie-connect-partner-resources.md (ADR — lokaal/gitignored werkdocument)
  - REQUIREMENTS.md MOLL-05 + MOLL-06 marked Complete (checkbox + Traceability-tabel)
affects: [v0.3 milestone closure — Phase 13 ready voor orchestrator-owned phase.complete]

tech-stack:
  added: []
  patterns:
    - "ADR-per-architecturele-keuze in .docs/decisions/ (lokaal-gitignored werkdocument); traceability via tracked SUMMARY-files + Phase 13 closure-flag in STATE.md (orchestrator-owned)"
    - "REQUIREMENTS.md closure-pattern: dubbel-flip per requirement (checkbox in v1-requirements-sectie + status in Traceability-tabel), beide gegrepd op exact regelnummer voorgaand aan edit"

key-files:
  created:
    - .docs/decisions/mollie-connect-partner-resources.md
  modified:
    - .planning/REQUIREMENTS.md

key-decisions:
  - "ADR landde in .docs/decisions/ (lokaal/gitignored) per CLAUDE.md werkdocument-conventie; ADR-content is niet onder git-controle, traceability is via tracked SUMMARY-files (13-01/02/03/04-SUMMARY.md) + de Phase 13 closure-status (STATE.md, orchestrator-owned)"
  - "Scope-fence per orchestrator-instructie: STATE.md + ROADMAP.md edits gedeferreerd naar phase.complete (workflow-protocol); enkel REQUIREMENTS.md + ADR vallen in dit plan-scope"
  - "ADR documenteert D-05 backlog-promotie expliciet: MOLLIE-CONNECT-BOOT-WARN als backlog-ID voor latere DX-verfijning (boot-time warning bij missing partner-token niet geïmplementeerd in v0.3)"
  - "REQUIREMENTS.md dubbel-flip (checkbox-sectie regels 29-30 + Traceability-tabel regels 85-86) maakt de file intern consistent — geen 'half-Complete'-state waar de checkbox flipped is maar de Traceability nog Pending toont"
  - "Geen edit op .docs/decisions/mollie-passthrough-api.md (D-13 verbiedt expliciet baseline-ADR-uitbreiding voor Connect-keuzes)"

patterns-established:
  - "Phase-closure-flow voor docs-only-plans: ADR (lokaal) + REQUIREMENTS.md flips + SUMMARY.md; STATE/ROADMAP edits in orchestrator-owned phase.complete-stap"

requirements-completed: [MOLL-05, MOLL-06]

duration: ~3min
completed: 2026-05-18
---

# Phase 13 Plan 04: ADR + Phase 13 closure documentation Summary

**Sluit Phase 13 af: 7-section ADR `.docs/decisions/mollie-connect-partner-resources.md` legt vier architecturele keuzes (route-prefix-rule + MollieAccessTokenResolver-shape + MOLLIE_PARTNER_ACCESS_TOKEN env-var-bron + pass_through_calls.token_type-kolom) bondig vast, met D-05 → backlog MOLLIE-CONNECT-BOOT-WARN expliciet vermeld; REQUIREMENTS.md flipt MOLL-05 + MOLL-06 naar Complete in zowel de v1-requirements-checkbox-sectie (regels 29-30) als de Traceability-tabel (regels 85-86).**

## Performance

- **Duration:** ~3 min
- **Started:** 2026-05-18T13:52:12Z
- **Completed:** 2026-05-18T13:55:38Z
- **Tasks:** 2 (alle docs-edit, geen code, geen tests)
- **Files created:** 1 (ADR — lokaal/gitignored, niet gecommit)
- **Files modified:** 1 (REQUIREMENTS.md — gecommit)
- **Commits:** 1 (`09b183f`)

## Accomplishments

- **ADR `.docs/decisions/mollie-connect-partner-resources.md` aangemaakt** met 7 H2-sections (Status / Keuze / Context / Alternatieven afgewogen / Consequences / Wanneer herzien / Cross-referenties), 89 regels totaal:
  - **4 keuzes (Keuze-sectie):**
    1. Route-prefix is single source of truth voor token-type — `/v1/mollie/connect/*` → partner; alle andere `/v1/mollie/*` → connection.
    2. `App\Mollie\MollieAccessTokenResolver` met `resolveFor(string $tokenType): string`, singleton-bound, `'partner' | 'connection'`-match.
    3. `MOLLIE_PARTNER_ACCESS_TOKEN` env-var → `config('services.mollie.partner_access_token')`; fingerprint-only-in-logs-invariant (`substr(hash('sha256', $token), 0, 12)`).
    4. `pass_through_calls.token_type`-kolom (forward-only nullable indexed varchar(16)) voor querybaarheid; géén `Connection { purpose: 'partner' }`-DB-row in v0.3.
  - **6 alternatieven gewogen** in Alternatieven-tabel (per-Consumer-DB-tokens, generieke super-class, jsonb-metadata-kolom, aparte Pennant-flag, per-controller try/catch, X-Token-Type-header).
  - **8 consequences** waarvan 1 expliciet over D-05 → backlog `MOLLIE-CONNECT-BOOT-WARN` (boot-time warning niet geïmplementeerd in v0.3, promoted naar backlog voor wanneer dev-feedback dat rechtvaardigt).
  - **6 cross-referenties** naar baseline-ADRs (`mollie-passthrough-api.md`, `oauth-flow-registry.md`, `feature-flags-pennant-kill-switch.md`, `pass-through-calls-table.md`) + 4 Phase-13 plan-bestanden.
  - **Mention-counts:** `MollieAccessTokenResolver` 7×, `mollie-passthrough-api` 3×, `pass_through_calls` 5×, `MOLLIE-CONNECT-BOOT-WARN` 3×.
- **REQUIREMENTS.md flipped voor MOLL-05 + MOLL-06:**
  - Regel 29: `- [ ] **MOLL-05**: …` → `- [x] **MOLL-05**: …`
  - Regel 30: `- [ ] **MOLL-06**: …` → `- [x] **MOLL-06**: …`
  - Regel 85: `| MOLL-05 | Phase 13 | Pending |` → `| MOLL-05 | Phase 13 | Complete |`
  - Regel 86: `| MOLL-06 | Phase 13 | Pending |` → `| MOLL-06 | Phase 13 | Complete |`

## Task Commits

| Task | Beschrijving | Commit | Files |
|------|--------------|--------|-------|
| 1 | ADR aanmaken in `.docs/decisions/` | N.v.t. — gitignored werkdocument (CLAUDE.md werkdocument-conventie) | `.docs/decisions/mollie-connect-partner-resources.md` |
| 2 | REQUIREMENTS.md MOLL-05 + MOLL-06 → Complete (checkbox + Traceability) | `09b183f` | `.planning/REQUIREMENTS.md` |

**Task 1 traceability:** ADR-content is niet onder git-controle, maar de aanmaak is geadministreerd via deze SUMMARY (`key-files.created`) + de regel "**ADR is lokaal opgeslagen in `.docs/decisions/` (gitignored werkdocument…)**" in 13-04-PLAN.md `must_haves.truths` (regel 24). Toekomstige lezers vinden de keuzes terug via:
1. Deze SUMMARY (verbose Keuze-summary boven).
2. 13-CONTEXT.md D-01 t/m D-15 (volledige decision-rationale).
3. Het ADR-bestand zelf in elke werkkopie van de repo waar Phase 13 is gedraaid (b.v. dev-machine, CI-build).

## Files Created/Modified

**Created (1, lokaal/gitignored):**

| Path | Provides |
|------|----------|
| `.docs/decisions/mollie-connect-partner-resources.md` | ADR voor Phase 13 token-resolver + route-prefix + env-token-bron + `pass_through_calls.token_type`-kolom; 7 H2-sections, 89 regels, `MOLLIE-CONNECT-BOOT-WARN`-backlog-vermelding, cross-refs naar 4 sibling-ADRs + 4 Phase-13 plans |

**Modified (1, gecommit):**

| Path | Wijziging |
|------|-----------|
| `.planning/REQUIREMENTS.md` | MOLL-05 + MOLL-06 → Complete (4 regels: 29, 30, 85, 86) |

## Scope-fence — STATE.md + ROADMAP.md deferred

Per orchestrator-instructie (workflow-protocol — `gsd-sdk query phase.complete` is owner van STATE/ROADMAP closure-edits) heeft dit plan **geen** edits op `.planning/STATE.md` of `.planning/ROADMAP.md` uitgevoerd. De plan-frontmatter `files_modified` claimt die files, maar het executor-prompt overschrijft dat met een scope-fence:

> "The plan's stated files_modified includes STATE.md and ROADMAP.md, but per workflow protocol the orchestrator owns those writes via `gsd-sdk query phase.complete`. SCOPE YOUR EDITS TO: ADR + REQUIREMENTS.md (MOLL-05 + MOLL-06)."

De plan-tekst Task 2 §1 ("STATE.md updates") + §2 ("ROADMAP.md updates") is gedeferreerd naar de orchestrator-owned `phase.complete`-stap. Verwachte updates die de orchestrator gaat aanbrengen:

- **STATE.md** — frontmatter (last_updated, last_activity, status, stopped_at, progress.completed_phases +1, progress.completed_plans +4, progress.percent herberekend), Current Position (Phase 13 Complete YYYY-MM-DD), Performance Metrics-tabel (Phase 13-rij), Session Continuity (Last session, Stopped at, Resume file), Next action options-update naar Phase 12 / 15 / 14.
- **ROADMAP.md** — Progress-tabel Phase 13-rij flag → Complete + 2026-05-18, Phase 13-detail-block 4 plan-checkboxen → `[x]`, "Roadmap last updated"-regel.

Dit plan's verifier-fase (indien gerund) moet die deferral-zin niet als blocker zien — de orchestrator's `phase.complete`-stap is de canonieke closure-mutatie voor STATE/ROADMAP.

## REQUIREMENTS.md acceptance-criteria — verified

| Check | Verwacht | Actueel |
|-------|----------|---------|
| `grep -c '^- \[x\] \*\*MOLL-0[56]' .planning/REQUIREMENTS.md` | 2 | **2** ✓ |
| `grep -c '^- \[ \] \*\*MOLL-0[56]' .planning/REQUIREMENTS.md` | 0 | **0** ✓ |
| `grep -c '| MOLL-0[56] | Phase 13 | Complete |' .planning/REQUIREMENTS.md` | 2 | **2** ✓ |
| `grep -c '| MOLL-0[56] | Phase 13 | Pending |' .planning/REQUIREMENTS.md` | 0 | **0** ✓ |

## Verification

- **ADR file-existence:** `test -f .docs/decisions/mollie-connect-partner-resources.md` → exit 0 ✓
- **ADR H2 section-count:** `grep -c '^## '` → 7 (≥6 verplicht) ✓
- **ADR `MollieAccessTokenResolver` mentions:** 7× (≥2 verplicht) ✓
- **ADR cross-ref `mollie-passthrough-api`:** 3× (≥1 verplicht) ✓
- **ADR `pass_through_calls` mentions:** 5× (≥1 verplicht) ✓
- **ADR `MOLLIE-CONNECT-BOOT-WARN` backlog-mention:** 3× (≥1 verplicht — D-05 backlog-promotie expliciet) ✓
- **ADR regel-aantal:** 89 (≥80 verplicht) ✓
- **REQUIREMENTS.md checkbox-flip:** beide MOLL-05 + MOLL-06 staan op `- [x]` (regels 29-30) ✓
- **REQUIREMENTS.md Traceability-flip:** beide rijen 85-86 tonen `Complete` ✓
- **Geen pint-run nodig:** alle wijzigingen zijn `.md`-files (Plan §1.3 + §2.4 staan dit expliciet toe).
- **Geen test-run nodig:** geen PHP-files gewijzigd; Plan 13-03 SUMMARY bewijst 563/564 tests groen op Phase 13 wave-3 HEAD waar dit plan op gebouwd is. (Worktree heeft sowieso geen `vendor/` of `.env` zonder symlinks — een test-run zou een environment-bootstrap vereisen die voor docs-only-changes geen waarde toevoegt.)
- **Geen edit op `.docs/decisions/mollie-passthrough-api.md`:** D-13 verbiedt baseline-ADR-uitbreiding ✓ (file niet getoucht door dit plan).

## Decisions Made

- **ADR-bestandsnaam:** `mollie-connect-partner-resources.md` zoals voorgesteld in 13-CONTEXT.md D-13 — niet ingekort tot b.v. `mollie-connect.md` of `mollie-partner.md`, omdat de langere naam de scope (Connect-partner-resources, niet de OAuth-broker uit Phase 4) duidelijker afbakent. Voorkomt ook collision-risico met een toekomstige `mollie-connect-oauth.md`-ADR.
- **7 H2-sections i.p.v. de 6 verplichte:** "Cross-referenties" als 7e section toegevoegd. Sibling-ADRs (`feature-flags-pennant-kill-switch.md`, `pass-through-calls-table.md`) hebben deze niet expliciet als section, maar wel inline cross-refs in Context. De expliciete sectie is leesbaarder voor toekomstige planners die wantrouwen of een baseline-ADR up-to-date is — het cross-references-blok in mijn ADR fungeert als "lees dit eerst voor context"-index.
- **ADR refereert naar Phase-13 plans expliciet** in Cross-referenties (incl. 4 SUMMARY-files), niet alleen naar sibling-ADRs. Reden: ADR's traceability moet via SUMMARY blijven (file is gitignored); een lezer die de ADR vindt op een dev-machine kan via de SUMMARY-pointers de gecommitte planning-historie reconstrueren.
- **STATE/ROADMAP-deferral expliciet gedocumenteerd in dit SUMMARY** in plaats van stilzwijgend overgeslagen — een verifier of een toekomstige reader die zich afvraagt waarom 13-04-PLAN.md `files_modified` STATE/ROADMAP claimt maar het commit-log alleen REQUIREMENTS.md toont, vindt het antwoord in de "Scope-fence — STATE.md + ROADMAP.md deferred"-sectie hierboven.

## Deviations from Plan

### Auto-fixed Issues / Scope-driven Adjustments

**1. [Scope-fence] STATE.md + ROADMAP.md edits gedeferreerd naar orchestrator-owned `phase.complete`**

- **Found during:** Pre-flight reading van de executor-prompt — de `<objective>`-block bevat een expliciete scope-fence-instructie: "the orchestrator owns those writes via `gsd-sdk query phase.complete`. SCOPE YOUR EDITS TO: ADR + REQUIREMENTS.md".
- **Issue:** 13-04-PLAN.md frontmatter `files_modified` (regels 11-12) en Task 2 `<action>` §1+§2 (regels 212-229) beschrijven STATE.md + ROADMAP.md edits in detail. Een naïeve executor zou die uitvoeren en dubbel-edit veroorzaken (executor schrijft, orchestrator overschrijft).
- **Fix:** Task 2 §1+§2 (STATE.md + ROADMAP.md edits) overgeslagen. Alleen Task 2 §3 (REQUIREMENTS.md flips) uitgevoerd. Deviation expliciet gedocumenteerd in dit SUMMARY in "Scope-fence — STATE.md + ROADMAP.md deferred"-sectie zodat de orchestrator + verifier kunnen valideren dat de deferral bewust was.
- **Files NIET gewijzigd door dit plan:** `.planning/STATE.md`, `.planning/ROADMAP.md`.
- **Verification:** `git diff HEAD~1 HEAD --name-only` toont alleen `.planning/REQUIREMENTS.md` — STATE.md + ROADMAP.md zijn unchanged in dit commit.
- **Committed in:** N.v.t. — geen edit, dus geen commit. Deze deviation-entry is de canonieke administratie.

**2. [Scope-fence] ADR niet gecommit (gitignored werkdocument)**

- **Found during:** Pre-commit `git status --short` toonde alleen REQUIREMENTS.md als gewijzigd — `.docs/` was niet zichtbaar.
- **Issue:** `.gitignore` regel 35 (`/.docs`) maakt alle ADR-files in `.docs/decisions/` lokaal/onzichtbaar voor git. Dit is per project-conventie (CLAUDE.md "werkdocumentatie (lokaal, gitignored): `.docs/decisions/` (ADRs)") + 13-04-PLAN.md `must_haves.truths` regel 24 ("ADR is lokaal opgeslagen in `.docs/decisions/` (gitignored werkdocument)").
- **Fix:** Geen — dit is by-design. De ADR bestaat op disk (`test -f` returnt 0) maar wordt niet door git getrackt. Traceability via deze SUMMARY (key-files.created) + 13-04-PLAN-frontmatter (`files_modified` regel 10).
- **Files modified:** `.docs/decisions/mollie-connect-partner-resources.md` aangemaakt — niet in commit zichtbaar door gitignore.
- **Verification:** `git check-ignore -v .docs/decisions/mollie-connect-partner-resources.md` → `.gitignore:35:/.docs` (bevestigt ignore-reden). `ls -la .docs/decisions/mollie-connect-partner-resources.md` toont de file.
- **Committed in:** N.v.t. — gitignored.

---

**Total deviations:** 2 scope-driven adjustments (beide gedreven door workflow-protocol resp. project-conventie); 0 auto-fixed bugs of blocking issues. **Impact on plan:** Geen impact op deliverables — ADR bestaat, REQUIREMENTS.md is consistent, Phase 13 is klaar voor orchestrator-`phase.complete`.

## Issues Encountered

Geen. Worktree miste `vendor/` en `.env` (zoals bij Plan 13-01 + 13-02 + 13-03), maar dit plan voert geen PHP-code-changes uit en heeft geen test-run nodig — environment-issue is daarmee niet-blocking voor dit specifieke plan.

## Documentation Drift

**De ADR aanmaken IS de docs-sync-actie voor Phase 13.** Plan 13-02 SUMMARY flagt expliciet (regel 244-245): "routes/api.php is gewijzigd → docs-sync skill-trigger: route-tabel in `.docs/decisions/mollie-passthrough-api.md` of een nieuwe `.docs/decisions/mollie-connect-partner-resources.md` (Plan 13-04 owns deze) moet de 9 Connect-routes opnemen." Dit plan voldoet daaraan: de ADR bevat in Keuze + Cross-referenties expliciet de Phase-13 plan-pointers en de pass-through-pattern-context. De 9-route-tabel zelf staat in Plan 13-02 SUMMARY (regel 121-133) — dupliceren in ADR zou drift veroorzaken bij toekomstige route-additie. ADR linkt naar die SUMMARY in plaats van te kopiëren.

Geen verdere drift in CLAUDE.md, memory of andere `.docs/`-files door dit plan.

## Next Phase Readiness

**Phase 13 klaar voor orchestrator-owned closure:**

- 4/4 plans complete (13-01 + 13-02 + 13-03 + 13-04 = elk eigen SUMMARY).
- 9 routes geregistreerd onder `/v1/mollie/connect/*` (Plan 13-02).
- 23 nieuwe tests groen (Plan 13-03: 17 per-resource + 3 token-resolver-integration + 3 Scramble) — totaal Phase-13-suite-delta is 563/564 (1 pre-existing Phase-11 Filament-failure).
- MOLL-05 + MOLL-06 → Complete in REQUIREMENTS.md (dit plan).
- ADR `mollie-connect-partner-resources.md` geland in `.docs/decisions/` (lokaal).

**Orchestrator-owned vervolgstappen** (via `gsd-sdk query phase.complete`):

1. STATE.md frontmatter-counters update (read-then-increment: `completed_phases +1`, `completed_plans +4`, `percent` recalculate).
2. STATE.md `Current Position` → Phase 13 Complete YYYY-MM-DD.
3. STATE.md `Performance Metrics`-tabel Phase 13-rij toevoegen (plans=4, tasks-totaal aggregeer uit 13-01..04-SUMMARY-files, files-totaal idem).
4. STATE.md `Next action options` update naar Phase 12 / 15 / 14 (zoals 13-04-PLAN.md Task 2 §1.6 voorschrijft).
5. ROADMAP.md Progress-tabel Phase-13-rij → `Complete | 2026-05-18`.
6. ROADMAP.md Phase 13-detail-block: 4 plan-checkboxen → `[x]`, "(4/4 complete 2026-05-18)" append op `**Plans:** 4 plans`-regel.
7. ROADMAP.md "Roadmap last updated"-regel updaten.

**Next phase candidates (per ROADMAP "Next action options" voorgesteld in 13-04-PLAN.md):**

1. **Phase 12 — Snelstart productie-cert closeout** — wacht op partner-respons (Gmail draft `r-8836998535038336548`, deadline 2026-05-26).
2. **Phase 15 — VERIFICATION.md backfill** — parallel low-risk doc-track (VERIF-01/02/03 voor Phase 4 + 6 + 7).
3. **Phase 14 — Naschool live E2E** — geblokkeerd op Phase 12 closure voor productie-Snelstart-call.

## Phase 13 closure-statement

| Metric | Waarde |
|--------|--------|
| Plans | 4/4 complete |
| Tasks totaal (over 4 plans) | 9 (Plan 13-01: 2 + Plan 13-02: 2 + Plan 13-03: 3 + Plan 13-04: 2) |
| Nieuwe tests | +23 (17 per-resource + 3 token-resolver + 3 Scramble), suite-totaal 564 (563 passed + 1 pre-existing Phase-11-failure) |
| Routes geregistreerd | 9 onder `/v1/mollie/connect/*` |
| Migrations | 1 forward-only (`pass_through_calls.token_type` + `partner_token_fingerprint`) |
| Requirements complete | **MOLL-05 + MOLL-06** |
| Phase duration | ~84 min cumulatief over 4 waves (25 + 26 + 30 + 3) |
| ADRs aangemaakt | 1 (`mollie-connect-partner-resources.md`, lokaal) |
| Backlog-IDs gepromoot | 2 (`MOLLIE-CONNECT-BOOT-WARN` voor D-05 boot-time warning, `MOLL-PT-RESOLVER-REFACTOR` voor Phase-5a resolver-refactor — laatste was al backlog vóór dit plan) |

## Self-Check: PASSED

- File `.docs/decisions/mollie-connect-partner-resources.md`: FOUND (89 regels, 7 H2-sections; lokaal-gitignored, geen commit)
- File `.planning/REQUIREMENTS.md`: MODIFIED — 4 regels (29, 30, 85, 86) flipped naar Complete
- Commit `09b183f`: FOUND (`docs(13-04): MOLL-05 + MOLL-06 → Complete (Phase 13 closure)`)
- ADR `MollieAccessTokenResolver`-mentions: 7 (≥2 verplicht)
- ADR `mollie-passthrough-api` cross-ref: 3 (≥1 verplicht)
- ADR `MOLLIE-CONNECT-BOOT-WARN` mentions: 3 (≥1 verplicht — D-05 backlog-promotie expliciet)
- REQUIREMENTS.md `- [x] **MOLL-0[56]`-count: 2 (verwacht 2)
- REQUIREMENTS.md `| MOLL-0[56] | Phase 13 | Complete |`-count: 2 (verwacht 2)
- Scope-fence respected: STATE.md + ROADMAP.md NOT modified (orchestrator-owned via `phase.complete`)
- D-13 respected: `.docs/decisions/mollie-passthrough-api.md` NOT modified (baseline-ADR ongewijzigd)

---
*Phase: 13-mollie-connect-partner-resources*
*Plan: 04 (closure — ADR + REQUIREMENTS.md flips)*
*Completed: 2026-05-18*
