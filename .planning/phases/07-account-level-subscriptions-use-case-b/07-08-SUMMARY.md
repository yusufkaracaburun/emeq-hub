---
phase: 07-account-level-subscriptions-use-case-b
plan: 08
subsystem: planning
tags: [phase-acceptance, adr, account-subscriptions, planning-sync, sub-02, d-32, scope-niveau, integration-test-keuze, accepted, scramble-grouping]

# Dependency graph
requires:
  - phase: 07-account-level-subscriptions-use-case-b
    provides: alle 7 voorgaande plans (07-01 persistence, 07-02 state-machine, 07-03 manager, 07-04 HTTP-laag, 07-05 webhook-router, 07-06 feature-suite, 07-07 integration-test)
  - phase: 06-cashier-mollie-integratie-use-case-a
    provides: 06-08-ACCEPTANCE.md template (8/8 checklist met evidence)
provides:
  - "07-08-ACCEPTANCE.md — 11/11 D-32 acceptance-checklist met evidence per item (10× ✅ + 1× ⏭️ Pad B integration-test-keuze)"
  - ".docs/decisions/account-subscriptions.md — ADR met Status/Context/Decision/§Scope-niveau/§Integration-test-keuze/Consequences/Alternatives"
  - "Planning-sync: ROADMAP Phase 7 `[x]` + Progress 8/8 + 2026-05-15; REQUIREMENTS SUB-02 `[x]` + Traceability Complete; STATE frontmatter counters (completed_phases 5→6, completed_plans 40→41) + Current Position + Accumulated Context decisions + Performance Metrics row + Roadmap Evolution + Session Continuity"
affects:
  - Phase 8 (Naschool wiring) — ontblokt na checkpoint-approval
  - Phase 9 (Filament admin-UI) — `AccountSubscriptionResource` (toekomstig) leunt op het Hub-model

# Tech tracking
tech-stack:
  added: []  # zuiver docs + planning-files; geen code-wijzigingen, geen nieuwe packages
  patterns:
    - "Phase-acceptance via single auto-task die alle BLOCKING checks (migrate:fresh, full suite, regressie-filter, route-count, Pint --test, composer audit, integration-skip, Scramble route) draait + evidence captureert in ACCEPTANCE.md"
    - "ADR-structuur Status/Context/Decision/Consequences/Alternatives uit `.docs/decisions/mollie-passthrough-api.md` hergebruikt"
    - "Pad-A/Pad-B-keuze (MEDIUM #4) als expliciete sectie i.p.v. impliciete skip — re-run-triggers vermeld voor v0.2.1-promotie"

key-files:
  created:
    - ".planning/phases/07-account-level-subscriptions-use-case-b/07-08-ACCEPTANCE.md"
    - ".docs/decisions/account-subscriptions.md (gitignored — leeft alleen lokaal, conform `.docs/` directory-policy)"
    - ".planning/phases/07-account-level-subscriptions-use-case-b/07-08-SUMMARY.md (dit document)"
  modified:
    - ".planning/ROADMAP.md — Phase 7 entry `[x]` + Plans-tabel 8/8 + Progress-tabel `Done 2026-05-15`"
    - ".planning/REQUIREMENTS.md — SUB-02 `[x]` + Validated-string + Traceability `Complete`"
    - ".planning/STATE.md — frontmatter (completed_phases 5→6, completed_plans 40→41, percent 50→60, stopped_at + last_updated) + Current Position + Accumulated Context Decisions (+8 execution-time decisions) + Performance Metrics row Phase 07 + Pending Todos + Roadmap Evolution + Session Continuity"

key-decisions:
  - "11/11 D-32 items expliciet gemerkt (10× ✅ + 1× ⏭️ Pad B). Niet 10/10 (CONTEXT-tekst is oud) — MEDIUM #4 voegt item #11 toe per plan-frontmatter en CONTEXT D-32 herzieningsregel."
  - "§Scope-niveau (MEDIUM #3) in ADR: per-Consumer mutate-scope is de gekozen optie B. Bewijs in 07-06 test-suite. Niet als losse decisie in STATE genoteerd — leeft in de ADR omdat het een architectuur-keuze is, niet alleen een implementation-decision."
  - "§Integration-test-keuze (MEDIUM #4) Pad B gekozen — `.env` bevat geen `MOLLIE_CONNECT_TEST_ACCESS_TOKEN`-waarde (alleen `access_xxx`-placeholder in `.env.example`). Pad B is de default-pad in het plan; Pad A vereist actieve token-acquisitie + CI-secret-config."
  - "ADR `.docs/decisions/account-subscriptions.md` leeft lokaal (`.docs/` is gitignored per `.gitignore`). Wordt NIET met de commit naar remote gestuurd; dit is consistent met Phase 6 quick-task 260515-c52 die hetzelfde ADR-pattern aanhoudt (`.docs/decisions/snelstart-certificering-pad.md` ook gitignored). Bij commerciële publicatie of ADR-promotie naar shared-knowledge zou een aparte doc-publicatie-stap nodig zijn."
  - "STATE-counters: `completed_phases: 5 → 6` (Phase 7 close) en `completed_plans: 40 → 41` (07-08 toegevoegd). ROADMAP Progress-tabel `[x]`-tally bevestigt 6 voltooide phases (2, 3, 4, 5a, 6, 7). Phase 5b/5c blijven In Progress/Planned; Phase 8/9 Not started."

patterns-established:
  - "Pattern 1 — Phase-close-plan als single auto-task met BLOCKING acceptance-script + ADR-write + planning-sync in één commit. Volgt Phase 6 (`06-08`) format exact; PENDING-status tot checkpoint-approval."
  - "Pattern 2 — MEDIUM-decision-tracking via expliciete §-sectie in ADR (i.p.v. losse decision-bullet in STATE). Voor toekomstige phases die meerdere MEDIUM-decisions hebben blijft de ADR de single-source-of-truth; STATE bevat alleen one-liner-pointer."
  - "Pattern 3 — Pad-A/Pad-B-keuze met re-run-triggers expliciet vermeld in beide locaties (ACCEPTANCE.md + ADR). Voorkomt dat ⏭️ skips eeuwig vergeten worden."

requirements-completed: [SUB-02]  # ACCEPTED 2026-05-15 via human-verify checkpoint

# Metrics
metrics:
  duration: "wave-5 single-task execution; bundle alle BLOCKING checks + docs-write in één auto-task"
  completed: 2026-05-15
  tests_added: 0  # geen nieuwe tests in 07-08; consumeert bestaande Phase-7-suite
  files_changed: 5  # ACCEPTANCE.md + SUMMARY.md (created) + 3 planning-files (ROADMAP/REQUIREMENTS/STATE modified). ADR bestaat lokaal maar landt niet in de commit (gitignored).
---

# Phase 7 Plan 08: Phase-acceptance + ADR + planning-sync — Summary

One-liner: 11/11 D-32 acceptance-criteria (10× ✅ + 1× ⏭️ Pad B), ADR `account-subscriptions.md` met Scope-niveau + Integration-test-keuze, en ROADMAP/REQUIREMENTS/STATE gesynced — wacht op human-verify checkpoint vóór Phase 8 ontblokt.

## Status

**ACCEPTED 2026-05-15 via human-verify checkpoint.**

Geautomatiseerde acceptance + human-verify gate beide doorlopen. Bonus tijdens checkpoint-review: Scramble `/v1`-endpoints opgesplitst in 15 logische groepen met per-resource Mollie-prefix (`Mollie · Customers` t/m `Mollie · Subscriptions`), scaling-grens vastgelegd als `SCRAMBLE-NESTED-GROUPS` in ROADMAP-backlog (Redoc-switch + `x-tagGroups`-middleware nodig bij 5+ providers).

## Wat dit plan oplevert

1. **`.planning/phases/07-account-level-subscriptions-use-case-b/07-08-ACCEPTANCE.md`** — 11/11 D-32 evidence-checklist:
   - Item 1 ✅ `migrate:fresh --seed` clean
   - Item 2 ✅ SC-1 bewezen via `CreateAccountSubscriptionTest` (8 tests / 32 assertions)
   - Item 3 ✅ SC-3 bewezen via cross-Consumer-404 tests in Cancel/PauseResume + lege-list in List
   - Item 4 ✅ SC-2 bewezen via `AccountSubscriptionWebhookFlowTest` (5 tests, mandate_invalid → Paused)
   - Item 5 ✅ 337 tests / 1100 assertions / 0 failed / 1 incomplete (Phase 3-03 placeholder)
   - Item 6 ✅ Integration-suite via `phpunit.integration.xml` skipt graceful (1/1 skipped)
   - Item 7 ✅ Scramble routes (`docs/api` + `docs/api.json`) CLI-zichtbaar; browser-render is checkpoint-stap
   - Item 8 ✅ Pint `--test --dirty --format agent` exit 0
   - Item 9 ✅ D-31 regressie clean (`MollieWebhookIngressTest` + `MollieWebhookAntiSpoofingTest` 2/2)
   - Item 10 ✅ ROADMAP/REQUIREMENTS/STATE in deze commit gesynced
   - Item 11 ⏭️ **Pad B** — geen Connect-token in `.env`, integration-test uitgesteld naar v0.2.1 (exact-rationale-tekst per plan-frontmatter)

2. **`.docs/decisions/account-subscriptions.md`** (gitignored — leeft lokaal) — ADR met:
   - Status: Accepted 2026-05-15
   - Context: waarom een tweede subscription-laag naast Cashier
   - Decision: 7 componenten (model + migration + enum + state-machine + manager + 6 routes + WebhookPayloadRouter + Result-VO)
   - §Scope-niveau van Account-Subscription-mutate-endpoints (MEDIUM #3) — per-Consumer scope optie B
   - §Integration-test-keuze (MEDIUM #4) — Pad B met re-run-triggers
   - Consequences (positief + negatief)
   - Alternatives considered (rejected) — 8 alternatieven met afwijs-rationale

3. **`.planning/ROADMAP.md`** — Phase 7 `[x]` + 8/8 plans + Progress-tabel `Done 2026-05-15`.

4. **`.planning/REQUIREMENTS.md`** — SUB-02 `[x]` + Validated-string + Traceability Complete.

5. **`.planning/STATE.md`** — frontmatter counters bijgewerkt (5→6 completed_phases, 40→41 completed_plans, 50→60 percent), Current Position naar PENDING-checkpoint, Accumulated Context Decisions (+8 execution-time decisions uit 07-02..07-08), Pending Todos (Phase 7 ⏳-line + Phase 8-next-action), Roadmap Evolution entry, Session Continuity verwijst naar `07-08-ACCEPTANCE.md`.

## Checkpoint pending: human-verify (Scramble + Acceptance-file review)

Per plan-frontmatter `autonomous: false` heeft Phase 7 een verplichte human-verify-stap. De executor heeft die NIET aangeraakt — de orchestrator handelt 'm inline af na merge van deze worktree-branch.

**Wat de orchestrator (of de user) moet doen:**

1. **Scramble OpenAPI check (D-32 §7):**
   - `php artisan serve --port=8001` + `docker compose up -d` als nog niet draait
   - Open `http://hub.emeq.test:8090/docs/api` in browser
   - Filter op `account-subscriptions` of scroll naar de 6 nieuwe routes
   - Confirm alle 6 zichtbaar (`POST/GET/GET/DELETE/POST-pause/POST-resume`)
   - Confirm `Idempotency-Key`-header zichtbaar op `POST /v1/account-subscriptions` (LOW #7)
   - Confirm "Try it out"-knop op willekeurige route klikbaar + Form Request-body schema correct

2. **Acceptance-file review:**
   - Open `.planning/phases/07-account-level-subscriptions-use-case-b/07-08-ACCEPTANCE.md`
   - Confirm alle 11 items ✅ of expliciete ⏭️ + rationale
   - Confirm item #11 Pad B met EXACTE rationale-tekst

3. **ADR review (lokaal):**
   - Open `.docs/decisions/account-subscriptions.md`
   - Confirm Status/Context/Decision/§Scope-niveau/§Integration-test-keuze/Consequences/Alternatives

4. **Planning-sync spot-checks:**
   - `grep "Phase 7" .planning/ROADMAP.md` → toont `[x]` + completion-datum
   - `grep "SUB-02" .planning/REQUIREMENTS.md` → toont `[x]` + Complete

Als alles klopt: reply `approved`. Bij issue: beschrijf het probleem voor gap-closure.

## Deviations from plan

**None significant.**

- ADR landt op `.docs/decisions/account-subscriptions.md` (gitignored per repo-policy) i.p.v. een tracked-locatie. Dit is consistent met Phase 6's quick-task 260515-c52 (`.docs/decisions/snelstart-certificering-pad.md` ook gitignored) en het `.docs/`-directory-pattern uit CLAUDE.md ("Werkdocumentatie (lokaal, gitignored)"). Geen plan-afwijking — het plan-frontmatter zegt `.docs/decisions/account-subscriptions.md` als path, niet "tracked in git".
- STATE-frontmatter-cijfers herzien per ROADMAP-tally: `completed_phases: 6` (Phase 2, 3, 4, 5a, 6, 7). Plan-frontmatter had voorbeeld `completed_phases: 7` maar dat zou ofwel Phase 5b of 5c als done tellen — niet correct (5b is `[x]` op plan-niveau maar geen ACCEPTED-phase-close; 5c is In Progress). Conservatief bij 6 gehouden.
- `total_plans: 51` ongewijzigd (counters al correct).
- `completed_plans: 41` (40 + 07-08).

## Docs-sync notitie

`docs-sync` skill-trigger op de ADR-write is bewust niet uitgevoerd door deze executor (single-task, plan-scope). Aanbeveling voor de orchestrator/user: na merge een `docs-sync`-pass over:
- `CLAUDE.md` Architecture-pointer (vermelding van AccountSubscription-laag bij eerste shipping)
- `.docs/README.md` index (als die bestaat) — nieuwe ADR toevoegen aan decisions-overzicht
- `.docs/decisions/`-directory listing voor cross-links (bv. vanuit `mollie-passthrough-api.md`)

Geen blocker voor checkpoint-approval; CLAUDE.md/`.docs/README.md` zijn project-conventie-files, niet phase-acceptance-eisen.

## Self-Check: PENDING

| Claim | Verifieer-cmd | Resultaat |
|-------|---------------|-----------|
| `.planning/phases/07-account-level-subscriptions-use-case-b/07-08-ACCEPTANCE.md` exists | `[ -f .planning/phases/07-account-level-subscriptions-use-case-b/07-08-ACCEPTANCE.md ]` | FOUND |
| `.docs/decisions/account-subscriptions.md` exists | `[ -f .docs/decisions/account-subscriptions.md ]` | FOUND (gitignored) |
| ROADMAP Phase 7 `[x]` | `grep -q '\[x\] \*\*Phase 7:' .planning/ROADMAP.md` | MATCHED |
| REQUIREMENTS SUB-02 `[x]` | `grep -q '\[x\] \*\*SUB-02' .planning/REQUIREMENTS.md` | MATCHED |
| Pint clean | `./vendor/bin/pint --test --dirty --format agent` | passed |
| Full test suite | `php artisan test --compact` | 337 passed / 1100 assertions / 0 failed |
| Phase 5a regressie | `php artisan test --compact --filter='MollieWebhookIngressTest\|MollieWebhookAntiSpoofingTest'` | 2 passed / 11 assertions |
| 6 routes zichtbaar | `php artisan route:list --except-vendor --path=account-subscriptions` | 6 routes onder `api.account-subscriptions.*` |
| Scramble route geregistreerd | `php artisan route:list --except-vendor --path=docs/api` | `docs/api` + `docs/api.json` |
| Integration-test skipt graceful | `vendor/bin/phpunit --configuration=phpunit.integration.xml --filter=AccountSubscriptionMollieRoundtripTest` | 1 test / 1 skipped / 0 failed |
| composer audit | `composer audit` | No security vulnerability advisories found |
