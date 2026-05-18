# Roadmap: Emeq integration stack

**Project code:** EMEQ

## Shipped Milestones

- ✅ **v0.1 — Snelstart-SDK finale** (2026-05-14) — Phase 1. Zie [`milestones/v0.1-ROADMAP.md`](milestones/v0.1-ROADMAP.md) · [`MILESTONES.md`](MILESTONES.md)
- ✅ **v0.2 — Mollie + Connect + Subscriptions + Hub-skeleton** (2026-05-17) — Phases 2-10 (11 phases incl. 5a/5b/5c). Zie [`milestones/v0.2-ROADMAP.md`](milestones/v0.2-ROADMAP.md) · [`milestones/v0.2-REQUIREMENTS.md`](milestones/v0.2-REQUIREMENTS.md) · [`MILESTONES.md`](MILESTONES.md)

## Active Milestone

_None — v0.3 nog niet gepland. Start via `/gsd-new-milestone v0.3`._

## Phases

<details>
<summary>✅ v0.1 Snelstart-SDK finale (Phase 1) — SHIPPED 2026-05-14</summary>

- [x] Phase 1: Snelstart-SDK finalize (3/3 plans) — completed 2026-05-14

</details>

<details>
<summary>✅ v0.2 Mollie + Connect + Subscriptions + Hub-skeleton (Phases 2-10) — SHIPPED 2026-05-17</summary>

- [x] Phase 2: emeq/mollie-api foundation (8/8 plans) — completed 2026-05-14
- [x] Phase 3: Hub-skeleton (5/5 plans) — completed 2026-05-14
- [x] Phase 4: Mollie Connect OAuth-broker (5/5 plans) — completed 2026-05-14
- [x] Phase 5a: Mollie SDK Resources + Webhooks + Pass-through API (6/6 plans) — completed 2026-05-15
- [x] Phase 5b: Snelstart-pass-through API (5/5 plans) — completed 2026-05-16; verifier-pass 2026-05-17
- [x] Phase 5c: Snelstart webhook-handler (5/5 plans) — completed 2026-05-17
- [x] Phase 6: Cashier-Mollie integratie (use-case A) (8/8 plans) — completed 2026-05-15
- [x] Phase 7: Account-level subscriptions (use-case B) (8/8 plans) — completed 2026-05-15
- [x] Phase 8: Naschool wiring (Hub-side per D-03) (5/5 plans) — completed 2026-05-17
- [x] Phase 9: Filament admin-UI voor Emeq-medewerkers (11/11 plans) — completed 2026-05-16
- [x] Phase 10: Phase 9 polish — deferred review-findings (6/6 plans) — completed 2026-05-16

</details>

## Backlog (v0.3+)

Verzamelpunt voor ideeën die nog geen milestone hebben. Bij milestone-kickoff worden relevante items uit deze sectie naar de active milestone gepromoveerd.

- **`NSCH-LIVE-E2E`** — Naschool-repo wiring + live E2E. Hub-side substrate compleet in v0.2 Phase 8 (D-03 scope-fence); resterend: composer-VCS-entries voor `emeq/snelstart-api` + `emeq/mollie-api` in `school-activities-hub/backend/composer.json`, `StancltenancyCredentialResolver` voor Snelstart, `EnrollmentConfirmed`-listener-wiring met `SyncEnrollmentToSnelstartJob`, live Mollie checkout-flow walkthrough door test-ouder met webhook → enrollment-status update. Sluit ook 3 deferred Phase 5a human-UAT items af.
- **`SNEL-V4`** — Snelstart-SDK Saloon v3 → v4 upgrade (3 ignored security advisories oplossen, o.a. SSRF via endpoint-override)
- Andere providers wanneer Mollie+Snelstart in productie gevalideerd: `emeq/moneybird-api`, `emeq/exact-api`, `emeq/ibanity-api`, `emeq/stripe-api`, `emeq/bizcuit-api`
- **`emeq/bizcuit-api`** — Bizcuit SDK (NL boekhouden/banking) — OpenAPI docs op https://app.bizcuit.nl/openapi/documentation/getting-started.html. Volgt SDK-pattern uit `packages/`-conventie. Trigger: zodra een host-app Bizcuit-integratie nodig heeft. User-captured 2026-05-17.
- OAuth Connect-implementaties voor providers die in v0.2 alleen contract-level zijn gedekt (Snelstart-OAuth, Exact-OAuth, Ibanity-OAuth)
- DTO-codegen vanuit OpenAPI specs voor providers die typed-response consumers nodig hebben
- Hub commerciële features: `HUB-BILLING` (public billing-flow voor derde-partij Consumers), `HUB-DOCS` (public docs-site `docs.hub.emeq.nl`), `HUB-ONBOARDING` (self-service onboarding)
- **`HUB-AUDIT`** — admin-acties audit-log via `spatie/laravel-activitylog` (Phase 9 out-of-scope) — pas als compliance of incident-respons het vereist
- **`MOLL-CONNECT-RES`** — Mollie Connect partner-resources via pass-through (Onboarding-status, Organizations, Profiles, Permissions, ClientLinks) — pad onbekend in v0.2, **blokkerend voor host-app productie-go-live** wanneer een Connect-merchant via de Hub moet onboarden. Volgt hetzelfde pass-through-pattern als Phase 5a (ADR `mollie-passthrough-api.md`). Promote naar active milestone zodra een host-app dit nodig heeft.
- **`SCRAMBLE-NESTED-GROUPS`** — Echte hiërarchische groepering in `/docs/api`. v0.2 gebruikt platte per-resource groepen met `Mollie · {Resource}`-prefix (Scramble v0.13 + Stoplight Elements 8.4 honoreren geen `x-tagGroups`). Pad: (1) tags blijven per-resource via `#[Group]`; (2) custom middleware op `docs/api.json` injecteert `x-tagGroups` post-serialisatie; (3) `docs.blade.php` overzetten van Stoplight Elements naar Redoc. Trigger: zodra 5+ providers of resource-lijst per provider voorbij ~10 endpoints groeit.
- **`BRAIN-AUDIT-CI`** — `laramint/laravel-brain` promoveren tot dev-dep + `bin/audit-pennant-gates.php` als blokkerende CI-check. Spike-validatie op 2026-05-17 toonde 21/21 SDK-routes met correcte `feature.provider:{provider}` gate. Trigger: 3e SDK toegevoegd, of 2e dev op de repo, of v1.0+ commercieel. Install-recept: `.docs/stack/architecture-audit.md`.
- **Phase verification-debt v0.2** — VERIFICATION.md ontbreekt voor Phases 4, 6, 7 (claims via ACCEPTANCE-files; optioneel `/gsd-verify-work` per phase in v0.3 of accepteer als gesloten).

---

*Roadmap last reorganized: 2026-05-18 bij v0.2 milestone-close. v0.1 en v0.2 archived in `.planning/milestones/`. Active milestone leeg tot `/gsd-new-milestone v0.3`.*
