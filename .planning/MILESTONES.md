# Milestones — Emeq integration stack

Shipped versies, oudste eerst.

## v0.1 — Snelstart-SDK finale (2026-05-14)

**Status:** ✅ SHIPPED
**Archive:** [`.planning/milestones/v0.1-ROADMAP.md`](milestones/v0.1-ROADMAP.md) · [`.planning/milestones/v0.1-REQUIREMENTS.md`](milestones/v0.1-REQUIREMENTS.md)
**Git tag:** `v0.1`

**Delivered:**

- `emeq/snelstart-api` SDK met Pest-suite groen (107 passed / 187 assertions)
- Public op `github.com:yusufkaracaburun/emeq-snelstart-api`, `main` @ `16c9ecc`, upstream-tracking actief
- VCS-installeerbaar zonder authenticatie via composer-VCS-repository-entry (bewezen via fresh smoke-test)
- `SnelstartConnectorTest` her-schreven met directe `getRequestException()`-coverage (MockClient-pipeline gedropt)

**Key Decisions:**

- Drop Saloon `MockClient`-pipeline voor exception-mapping; directe PHPUnit-mocks op `Response` zijn schoner
- VCS-distributie zonder auth volstaat voor publieke SDKs in v0.1 — geen private composer-registry
- Snelstart `Dto/` + `Resources/` blijven leeg — OData QueryBuilder + `RawSnelstartRequest` dekken 96 endpoints
- **2026-05-14 scope-herziening:** v0.1 teruggebracht tot Snelstart-only; Mollie + Connect + Subscriptions + Hub naar v0.2 (~8-10 weken, plan in `.claude/plans/fancy-honking-spring.md`)

**Stats:**

- 1 phase · 3 plans · 19 commits · ~3300 LOC toegevoegd
- Timeline: 2026-05-14 (00:42 → 12:02 CEST, ~12 uur effectieve werk)

**Known Gaps / Deferred:**

- Phase 2 (Mollie-SDK foundation), Phase 3 (Mollie-SDK resources + webhooks), Phase 4 (Naschool wiring — Snelstart), Phase 5 (Naschool wiring — Mollie + flow-test) — verschoven naar v0.2
- 7 v1-requirements (MOLL-01..04, NSCH-01..03) deferred met herzieningen (zie v0.1-REQUIREMENTS.md Carry-forward)

---

*Next milestone: v0.2 — Mollie + Connect + Subscriptions + Hub-skeleton. Start via `/gsd-new-milestone v0.2`.*
