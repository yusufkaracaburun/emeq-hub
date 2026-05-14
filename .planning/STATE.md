---
gsd_state_version: 1.0
milestone: v0.2
milestone_name: — Mollie + Connect + Subscriptions + Hub-skeleton
status: executing
stopped_at: Plan 03-01 voltooid — consumers/accounts/connections migrations + Eloquent-models + factories geland (3 commits, 9 nieuwe files)
last_updated: "2026-05-14T13:58:07.436Z"
last_activity: 2026-05-14 — Phase 03 plan 01 voltooid
progress:
  total_phases: 9
  completed_phases: 0
  total_plans: 13
  completed_plans: 1
  percent: 8
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-05-14 na v0.1 milestone-close)

**Core value:** Twee fundamenteel verschillende providers (OData/clientkey + REST/OAuth2) productie-gevalideerd via één SDK-pattern, en beide live in één concrete consumer-feature. v0.1 heeft Snelstart-deel bewezen; v0.2 zet Mollie + Connect + Subscriptions + Hub-skeleton op.
**Current focus:** Phase 03 — hub-skeleton

## Current Position

Phase: 03 (hub-skeleton) — EXECUTING
Plan: 2 of 5
Status: Executing Phase 03 — plan 01 voltooid, plan 02 klaar voor start
Last activity: 2026-05-14 — Phase 03 plan 01 voltooid

## Performance Metrics

**v0.1 Velocity:**

- Total plans completed: 3 (Phase 1)
- Total execution time: ~12 uur (2026-05-14 00:42 → 12:02 CEST)
- Sub-repo werk: snelstart-sdk submodule wiring + Pest-coverage + push

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 01-snelstart-sdk-finalize | 3 | ~16 min | ~5 min |
| 03-hub-skeleton | 1/5 | ~5 min | ~5 min |

**Recent Trend:**

- Last 3 plans: 01-01 (diagnose, ~6 min), 01-02 (coverage, ~3 min), 01-03 (push + smoke, ~7 min); 03-01 (migrations+models+factories, ~5 min, 3 commits, 10 nieuwe files)
- Trend v0.1: phase 1 sneller dan ingeschat (master-plan-aanname 30-60 min crash-fix; bleek NO CRASH REPRO)
- Trend v0.2 (start): plan 03-01 binnen gepland tijdsvenster; PATTERNS.md analog-mapping leverde alle copy-targets aan zonder context-switches

*Updated 2026-05-14 — plan 03-01 voltooid.*

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
- 🆕 **New 2026-05-14 (03-01):** `connections.subscription_id` niet versleuteld — Snelstart's `subscriptionId` is een tenant-UUID, niet zelf een secret (alleen `client_key`/`subscription_key`/`access_token`/`refresh_token` krijgen `encrypted` cast)
- 🆕 **New 2026-05-14 (03-01):** Connection-factory default = Snelstart-shape; `forSnelstart()`/`forMollie()` als state-methodes (niet aparte factories) — bewijst SC-5 uit HUB-01

### Pending Todos

- Plan 03-02 — Sanctum-config (`config/auth.php` `sanctum`-guard + `consumers`-provider, `bootstrap/app.php` `apiPrefix: 'v1'`, `App\Sanctum\TokenAbilities` constants-class, `routes/api.php` skeleton)
- Plan 03-03 — `routes/api.php` `/v1/ping` + `PingController` + PingTest + SanctumAbilityTest
- Plan 03-04 — `ConnectionEncryptionTest` (verifieert 03-01's encrypted casts + `#[Hidden]` via DB-bypass) + `ConsumerAccountScopingTest`
- Plan 03-05 — `hub:consumer:create` artisan-command + `DatabaseSeeder` demo-data + acceptance-run
- Scramble (`dedoc/scramble`) installeren met `/docs/api` + Sanctum-bearer-extension (quick-task) — voorbereiding op Phase 5a/5b
- `/gsd-plan-phase 5b` runnen na Phase 3 — Snelstart-pass-through API
- Mollie-tak (Phase 2 + 4 + 5a) blijft parallel werk; aparte sessie/working-copy
- **Out-of-scope cleanup (deferred-items.md):** Pint formatting-drift op vendor-published `webhook_calls`-migrations — quick-task of meenemen in Phase 5a/5b

### Blockers/Concerns

- **Cashier-Mollie compat-risico (v0.2)**: `mollie/laravel-cashier-mollie` master-branch hangt op PHP 7.2 / Laravel 6-8. Compatibiliteit met PHP 8.4 / Laravel 13 moet worden gecheckt in Phase 6 (use-case A integratie). Mogelijk fork-and-update of zelf subscription-laag bouwen. Phase 6 success criterion 1 vereist expliciete ADR met conclusie.
- **`yusufkaracaburun/emeq-mollie-api` repo description is stale**: zegt nog "Saloon v3" terwijl die keuze is gereverseerd. Wordt bijgewerkt bij eerste push in Phase 2.
- **`.docs/partners/mollie/` bestaat nog niet**: moet aangemaakt worden bij start van Phase 2 (PROJECT.md "geen verzonnen partner-features" invariant).

### Quick Tasks Completed

| # | Description | Date | Commit | Directory |
|---|-------------|------|--------|-----------|
| 260514-iai | App-wide noindex/nofollow voor bots | 2026-05-14 | 0354074 | [260514-iai-app-wide-noindex-nofollow-voor-bots](./quick/260514-iai-app-wide-noindex-nofollow-voor-bots/) |

### Roadmap Evolution

- 2026-05-14 — Phase 9 (Filament admin-UI voor Emeq-medewerkers) toegevoegd aan v0.2 milestone; HUB-04 toegevoegd aan REQUIREMENTS.md. Plan-bron: `.claude/plans/ow-dit-wil-ik-immutable-snowglobe.md`. Depends on Phase 3 + Phase 4; parallel met Phase 6/7.
- 2026-05-14 — Phase 5 gesplitst in 5a (Mollie) + 5b (Snelstart-pass-through) en HUB-05 toegevoegd aan REQUIREMENTS.md. Plan-bron: `.claude/plans/volgens-mij-is-snelstart-api-piped-parasol.md`. Reden: user wil consumer-app via `hub.emeq.nl` Snelstart-calls passen-doorzetten — was eerder niet expliciet in scope (Phase 8 deed Snelstart-direct-via-SDK in Naschool). Phase 5b depends on Phase 3 only; parallel met Phase 4 mogelijk.
- 2026-05-14 — Plan 03-01 voltooid: `consumers`/`accounts`/`connections` migrations + `Consumer`/`Account`/`Connection` Eloquent-models + factories. Fundatie voor HUB-01 staat; HUB-01 blijft Pending tot Phase 3 in z'n geheel geland is (Sanctum + ping + tests).

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

Last session: 2026-05-14 — Phase 03 plan 01 voltooid
Stopped at: Plan 03-01 voltooid — 3 migrations + 3 models + 3 factories geland; alle SC-5-mutually-exclusive-shapes bewezen via tinker
Resume file: `.planning/phases/03-hub-skeleton/03-02-PLAN.md` (Sanctum-config)
Next action: Plan 03-02 — Sanctum guard + consumers-provider + TokenAbilities + routes/api.php skeleton
