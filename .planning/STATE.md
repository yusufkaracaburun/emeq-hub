---
gsd_state_version: 1.0
milestone: v0.2
milestone_name: Mollie + Connect + Subscriptions + Hub-skeleton
status: planning
last_updated: "2026-05-14T10:11:40.878Z"
last_activity: 2026-05-14
progress:
  total_phases: 7
  completed_phases: 0
  total_plans: 0
  completed_plans: 0
  percent: 0
current_position:
  next_phase: 2
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-05-14 na v0.1 milestone-close)

**Core value:** Twee fundamenteel verschillende providers (OData/clientkey + REST/OAuth2) productie-gevalideerd via één SDK-pattern, en beide live in één concrete consumer-feature. v0.1 heeft Snelstart-deel bewezen; v0.2 zet Mollie + Connect + Subscriptions + Hub-skeleton op.
**Current focus:** v0.2 milestone met ROADMAP gedefinieerd (Phase 2-8). Wacht op `/gsd-plan-phase 2` om eerste fase (emeq/mollie-api foundation) te plannen.

## Current Position

Phase: Not started — next phase = 2 (emeq/mollie-api foundation)
Plan: —
Status: Roadmap gedefinieerd, klaar voor `/gsd-plan-phase 2`
Last activity: 2026-05-14 — ROADMAP.md v0.2 gedefinieerd (12/12 requirements gemapped naar Phase 2-8)

## Performance Metrics

**v0.1 Velocity:**

- Total plans completed: 3 (Phase 1)
- Total execution time: ~12 uur (2026-05-14 00:42 → 12:02 CEST)
- Sub-repo werk: snelstart-sdk submodule wiring + Pest-coverage + push

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 01-snelstart-sdk-finalize | 3 | ~16 min | ~5 min |

**Recent Trend:**

- Last 3 plans: 01-01 (diagnose, ~6 min), 01-02 (coverage, ~3 min), 01-03 (push + smoke, ~7 min)
- Trend v0.1: phase 1 sneller dan ingeschat (master-plan-aanname 30-60 min crash-fix; bleek NO CRASH REPRO)

*Updated bij milestone-close 2026-05-14.*

## Accumulated Context

### Decisions

Decisions zijn gelogd in PROJECT.md Key Decisions table. Decisions die uit v0.1 zijn gekomen:

- ✅ Drop Saloon `MockClient`-pipeline voor exception-mapping tests; PHPUnit-mocks op `Response` zijn cleaner (01-02)
- ✅ VCS-distributie zonder auth voor publieke SDKs is voldoende (01-03)
- ✅ `Dto/` + `Resources/` leeg in Snelstart — `RawSnelstartRequest` + OData QueryBuilder dekken 96 endpoints
- ❌ **Reversed 2026-05-14:** Eigen Saloon-wrapper voor Mollie → vervangen door wrap `mollie/mollie-api-php` direct
- ❌ **Reversed 2026-05-14:** API-key auth voor Mollie in v0.1 → Mollie Connect vanaf v0.2 dag 1
- 🆕 **New 2026-05-14:** Subscriptions in v0.2 voor beide use-cases (Emeq→Consumers + Accounts→eindgebruikers)
- 🆕 **New 2026-05-14:** Mollie facade-alias = `EmeqMollie` (niet `Mollie`) i.v.m. collision met laravel-mollie

### Pending Todos

Geen actieve todos. v0.2-roadmap gedefinieerd; klaar voor `/gsd-plan-phase 2`.

### Blockers/Concerns

- **Cashier-Mollie compat-risico (v0.2)**: `mollie/laravel-cashier-mollie` master-branch hangt op PHP 7.2 / Laravel 6-8. Compatibiliteit met PHP 8.4 / Laravel 13 moet worden gecheckt in Phase 6 (use-case A integratie). Mogelijk fork-and-update of zelf subscription-laag bouwen. Phase 6 success criterion 1 vereist expliciete ADR met conclusie.
- **`yusufkaracaburun/emeq-mollie-api` repo description is stale**: zegt nog "Saloon v3" terwijl die keuze is gereverseerd. Wordt bijgewerkt bij eerste push in Phase 2.
- **`.docs/partners/mollie/` bestaat nog niet**: moet aangemaakt worden bij start van Phase 2 (PROJECT.md "geen verzonnen partner-features" invariant).

## Deferred Items

Items acknowledged en deferred bij milestone-close 2026-05-14:

| Category | Item | Status | Deferred At |
|----------|------|--------|-------------|
| requirement | MOLL-01 (Mollie-SDK skeleton) | Herzien voor v0.2 (wrap mollie-api-php) | 2026-05-14 |
| requirement | MOLL-02 (Mollie auth + connector) | Herzien voor v0.2 (Connect OAuth-broker) | 2026-05-14 |
| requirement | MOLL-03 (Mollie resources) | Uitgebreid voor v0.2 (+ Mandates + Subscriptions) | 2026-05-14 |
| requirement | MOLL-04 (Mollie webhook verifier) | Herzien voor v0.2 (Connect-webhook signing) | 2026-05-14 |
| requirement | NSCH-01 (Composer-wiring + resolvers) | Mollie-deel verandert (via Hub); Snelstart-deel ongewijzigd | 2026-05-14 |
| requirement | NSCH-02 (SyncEnrollmentToSnelstartJob) | Ongewijzigd voor v0.2 | 2026-05-14 |
| requirement | NSCH-03 (Mollie checkout-flow) | Herzien voor v0.2 (via Hub-Connect) | 2026-05-14 |

## Session Continuity

Last session: 2026-05-14 — v0.2 ROADMAP gedefinieerd
Stopped at: ROADMAP.md geschreven; 12/12 requirements gemapped; traceability bijgewerkt in REQUIREMENTS.md
Resume file: `.planning/ROADMAP.md` (Phase 2-8) + `.claude/plans/fancy-honking-spring.md` (master-plan context)
Next action: `/gsd-plan-phase 2` — plan emeq/mollie-api foundation
