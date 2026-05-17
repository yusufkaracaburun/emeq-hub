# Emeq — Epics dashboard

> Cross-cutting status van twee parallelle epics. Drill-down per epic via de Detail-kolom.

## Status

| Epic | Milestone | Status | Next | Detail |
|---|---|---|---|---|
| **Hub** (Epic 1) | v0.2 — Mollie + Connect + Subscriptions + Hub-skeleton | in-progress (10/11 phases — Phase 5c verifier `passed`) | Merge `feat/05c-snelstart-webhook-handler` → `master` via `/gsd-ship 5c` | [`.planning/ROADMAP.md`](./ROADMAP.md) |
| **Mollie SDK** (Epic 2) | `v0.1.0-alpha.2` (published 2026-05-17) | done → v1.0 next | `/gsd-new-milestone v1.0` in SDK-repo na 5c | [`packages/mollie-api/`](../packages/mollie-api/) |
| **Snelstart SDK** (Epic 2) | `v0.1.0` (published 2026-05-17) | done → v1.0 next | `/gsd-new-milestone v1.0` in SDK-repo na 5c | [`packages/snelstart-api/`](../packages/snelstart-api/) |

_Last sync: 2026-05-17 (Phase 5c ACCEPTED + verifier `passed` — ready for merge)_

## Cross-epic dependencies

- **Hub Phase 5c** blocked op `emeq/snelstart-api >= v0.1.0` — ✅ geleverd (`composer.lock` pin `e9076d4`)
- **Hub Phase 5c** blocked op `emeq/mollie-api >= v0.1.0-alpha.2` — ✅ geleverd (`composer.lock` pin `5315efe`)
- **SDK v1.0-milestones** blocked op Hub Phase 5c afronding — zie [`.docs/todos/sdk-v1-milestones-after-5c.md`](../.docs/todos/sdk-v1-milestones-after-5c.md) (gitignored)
- **Hub Phase 5c HUB-06 SC's** blocked op partner-respons retry-policy (Gmail draft `r-8836998535038336548`) — defensieve-aanname-path geactiveerd, niet hard-blocking

## Update-cadans

Hand-edited bij elke fase-/plan-status-shift in Hub ROADMAP, bij elke SDK-release-tag, en bij milestone-overgang. Automatiseren via `gsd-sdk query state.epic-summary` is een follow-up — capture in een nieuwe quick wanneer de cadans pijn doet.

## Architectural ADR

Zie `.docs/decisions/sdk-redistributability-boundary.md` (gitignored) voor de boundary-keuze tussen Epic 1 (Hub) en Epic 2 (SDKs): wat hoort waar, waarom, en welke checks bij elke nieuwe wijziging.
