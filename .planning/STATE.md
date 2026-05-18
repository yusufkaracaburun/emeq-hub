---
gsd_state_version: 1.0
milestone: v0.3
milestone_name: milestone
status: Idle — ready voor volgende phase
stopped_at: Phase 11 complete — Saloon v4 upgrade docs-closure (3/3 plans, ADR lokaal + audit clean)
last_updated: "2026-05-18T09:05:01.780Z"
last_activity: 2026-05-18
progress:
  total_phases: 5
  completed_phases: 1
  total_plans: 3
  completed_plans: 3
  percent: 20
---

# Project State

## Project Reference

See: [`.planning/PROJECT.md`](PROJECT.md) (updated 2026-05-18 — v0.3 milestone-kickoff)

**Core value:** Twee fundamenteel verschillende providers (OData/clientkey + REST/OAuth2) productie-gevalideerd via één SDK-pattern, beide via één Hub geconsumeerd, multi-tenant + encrypted-at-rest + audit-logged + admin-managed. v0.1 + v0.2 hebben dit Hub-side bewezen; v0.3 sluit met `NSCH-LIVE-E2E` de eerste concrete consumer-feature (Naschool) end-to-end.

**Current focus:** Phase 11 — snelstart-sdk-saloon-v4-upgrade

## Current Position

Phase: 11 — Snelstart-SDK Saloon v4 upgrade (Complete 2026-05-18, 3/3 plans)
Plan: — (Phase 11 closed; geen actieve plan)
Status: Idle — ready voor volgende phase
Last activity: 2026-05-18

## Shipped Milestones

- **v0.1 (2026-05-14)** — Snelstart-SDK finale. Archive: [`milestones/v0.1-ROADMAP.md`](milestones/v0.1-ROADMAP.md)
- **v0.2 (2026-05-17)** — Mollie + Connect + Subscriptions + Hub-skeleton. 11 phases · 67 plans · 511 commits · ~498 tests · ~100k LOC. Archive: [`milestones/v0.2-ROADMAP.md`](milestones/v0.2-ROADMAP.md) · [`milestones/v0.2-REQUIREMENTS.md`](milestones/v0.2-REQUIREMENTS.md) · [`milestones/v0.2-MILESTONE-AUDIT.md`](milestones/v0.2-MILESTONE-AUDIT.md)

## Accumulated Context

### Decisions (cross-milestone summary; bron-van-waarheid: [`PROJECT.md`](PROJECT.md) Key Decisions)

Validated v0.1:

- SDK-first, geen Hub-platform in v0.1
- Drop Saloon MockClient-pipeline voor exception-mapping (directe PHPUnit-mocks)
- VCS-distributie zonder auth volstaat voor publieke SDKs
- Snelstart `Dto/` + `Resources/` leeg — `RawSnelstartRequest` + OData QueryBuilder dekt 96 endpoints

Validated v0.2:

- `emeq/mollie-api` wrapt `mollie/mollie-api-php` direct (reversed Saloon-wrapper)
- Mollie Connect dag 1 (reversed API-key-only)
- Subscriptions in twee use-cases (Cashier-Mollie + eigen Connect-laag)
- `EmeqMollie`-facade naast `Mollie` (coexist runtime bewezen in Phase 6)
- Provider-agnostisch `OAuthFlow`-contract met `FakeOAuthFlow` pattern-portability-bewijs
- `pass_through_calls` als eigen tabel (afgesplitst van `webhook_calls`)
- Cashier-Mollie pad-a (`^2.20.1` out-of-the-box, geen fork)
- Spatie laravel-permission ^6 met 2-rol-model (`super-admin`/`staff`) — drop `is_emeq_staff` boolean
- `ProviderCredentialDescriptor` als single source of truth (`config/hub-providers.php`)
- Pennant feature-flag voor provider kill-switch (auto-gedefinieerd op `config('hub-providers')` keys)
- Hub-eigen `WebhookCall extends Spatie\WebhookClient\Models\WebhookCall` met `consumer()` belongs-to
- HMAC-verifier naar SDK (SDK-redistributability boundary)
- D-03 scope-fence Phase 8: Hub-side only; Naschool-repo werk + live E2E naar v0.3

### Open Blockers / Carry-forward naar v0.3

- **`NSCH-LIVE-E2E`** — Naschool-repo composer-VCS-entries + `StancltenancyCredentialResolver` + `EnrollmentConfirmed`-listener live wiring + e2e door test-ouder. Hub-side substrate compleet per D-03 scope-fence. Sluit ook 3 deferred Phase 5a human-UAT items af. → Phase 14.
- **Snelstart productie-certificering** — Hub-side ingress compleet; wacht op partner-respons (Gmail draft `r-8836998535038336548` ≤2026-05-26). Vraag #4 (retry-policy) ❓ open. → Phase 12.
- **VERIFICATION.md ontbreekt voor Phases 4, 6, 7** — claims via ACCEPTANCE-files; optioneel `/gsd-verify-work` per phase in v0.3 of accepteer als gesloten. → Phase 15.
- **`yusufkaracaburun/emeq-mollie-api` repo description is stale** — zegt nog "Saloon v3" terwijl die keuze gereverseerd is. Bijwerken bij volgende SDK-push.

### Resolved Blockers

- ~~Cashier-Mollie compat-risico v0.2~~ — opgelost in Phase 6 (pad-a out-of-the-box, ADR `cashier-mollie-compat.md`)
- ~~Mollie partner-docs ontbraken~~ — opgelost via quick-task 260514-tny, verplaatst naar `packages/mollie-api/docs/partners/` op 2026-05-17 (SDK-redistributability-refactor)
- ~~Phase 5b verification-debt~~ — closed 2026-05-17 via gsd-verifier (`05b-VERIFICATION.md`, 8/8 must-haves)
- ~~Saloon v3 → v4 upgrade + 3 advisories (PKSA-xnj5-w74d-6wmz HIGH, PKSA-5szq-gvrg-ttfq MEDIUM, PKSA-rnpm-45mg-w6ht MEDIUM)~~ — closed 2026-05-18 via Phase 11 (3 plans, SDK getagd v0.2.0, Hub composer audit exit 0 zonder ignores). ADR (lokaal): `.docs/decisions/snelstart-sdk-saloon-v4-upgrade.md`.

## Performance Metrics

**v0.1 Velocity:** 1 phase · 3 plans · ~12 uur effectieve werk (2026-05-14 00:42 → 12:02 CEST)

**v0.2 Velocity:** 11 phases · 67 plans · ~4 dagen high-velocity execution (2026-05-14 → 2026-05-17, 511 commits)

| Milestone | Phases | Plans | Tests at close | Notable |
|-----------|--------|-------|----------------|---------|
| v0.1 | 1 | 3 | 107 Pest (SDK) | Snelstart-SDK pattern validated |
| v0.2 | 11 | 67 | ~498 PHPUnit (Hub) + 4 integration | 9/10 phases shipped within first 3 days; Phase 5c afgesloten met partner-blocker-resolutie |
| v0.3 | 5 (planned) | — | — | Productie-closure: Naschool live + risk-reductie (Saloon v4, Mollie Connect resources, verification-debt) |
| Phase 11 P02 | 12 | 2 tasks | 3 files |
| Phase 11 P11-03 | 6m | 3 tasks | 3 files |

## Session Continuity

Last session: 2026-05-18T09:04:17.743Z
Stopped at: Phase 11 complete — Saloon v4 upgrade docs-closure (3/3 plans, ADR lokaal + audit clean)
Resume file: None

Next action options:

1. `/gsd-execute-phase 12` — Snelstart productie-certificering (wacht op partner-respons Gmail-draft `r-8836998535038336548` ≤2026-05-26; Hub-side ingress al compleet).
2. `/gsd-plan-phase 13` — Mollie Connect partner-resources (parallelliseerbaar met Phase 12).
3. `/gsd-plan-phase 15` — verification-debt backfill als parallel low-risk doc-track.

## Decisions

- [Phase ?]: Plan 11-02: composer.json + composer.lock in één commit (chore(deps) 897c1e0) — atomic install-state per plan-instructie
- [Phase ?]: Plan 11-02: pre-existing UserResource-test-failure NIET fixen — SCOPE BOUNDARY, gelogd in deferred-items.md
- [Phase ?]: Plan 11-03: ADR voor Saloon v4 upgrade leeft lokaal in gitignored .docs/decisions/ (CLAUDE.md werkdocument-conventie); traceability via tracked SUMMARY + STATE.md Resolved Blockers
- [Phase ?]: Plan 11-03: CONCERNS.md Resolved-conventie geintroduceerd (doorhaling + Resolved YYYY-MM-DD Phase N + commit-SHA's); geen bestaande conventie gevonden in CONCERNS.md voor dit plan
