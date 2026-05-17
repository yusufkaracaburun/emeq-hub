# Emeq integration stack (v0.2)

> **Master-plan**: [`.claude/plans/2026-05-14-emeq-integration-strategy.md`](../.claude/plans/2026-05-14-emeq-integration-strategy.md) — bron-van-waarheid voor scope, locked decisions en track-breakdown.
> Deze PROJECT.md is de operationele synthese voor de GSD-workflow.

## What This Is

Een Hub-platform en losse, Saloon-gebaseerde Laravel SDK-packages (`emeq/snelstart-api`, `emeq/mollie-api`) voor Nederlandse boekhoud- en betaal-partner-API's. De Hub (`emeq-hub`) host multi-tenant OAuth-koppelingen, webhook-routing en een pass-through REST-API; SDKs leveren de partner-specifieke wrapping. v0.2 bouwt Mollie + Connect + Subscriptions + Hub-skeleton bovenop het in v0.1 gevalideerde Snelstart-pattern, met Naschool als eerste concrete consumer-feature. Doelgroep v0.2: Emeq's eigen SaaS-apps die nu ad-hoc partner-integraties hebben. Doelgroep v1.0+ (later): commercieel beschikbaar voor andere NL dev-shops.

## Core Value

**Twee fundamenteel verschillende providers (OData/clientkey + REST/OAuth2) productie-gevalideerd via één SDK-pattern, en beide live in één concrete Naschool-feature.** Dat valideert het pattern voor toekomstige SDKs en levert directe DRY-winst in Naschool.

## Requirements

### Validated

<!-- Shipped and confirmed valuable. -->

- [x] **SNEL-01** — Snelstart-SDK Pest-suite groen (107 passed / 187 assertions). Gevalideerd in Phase 1 (`fase-4 crash` bleek NO REPRO op `main @ 76e0797`; rewrote SnelstartConnectorTest van 1 → 12 cases met directe `getRequestException()`-coverage).
- [x] **SNEL-02** — Snelstart-SDK gepusht naar `github.com:yusufkaracaburun/emeq-snelstart-api` met upstream-tracking. VCS-installeerbaar zonder auth bewezen via smoke-test.
- [x] **HUB-01** — Hub-skeleton: `consumers`/`accounts`/`connections` tabellen + Sanctum-PAT-auth (`/v1/*`) + encrypted credential-velden + multi-tenant scoping. Gevalideerd in Phase 3 (5/5 plans, 28 tests groen incl. encryption-at-rest voor alle 4 credential-velden en cross-Consumer query-isolation). Acceptance via `hub:consumer:create` + `GET /v1/ping`.
- [x] **MOLL-03** — `emeq/mollie-api` Resources + Idempotency-Key forward op alle 5 write-endpoints. Gevalideerd in Phase 5a (6/6 plans, 207 tests groen, 13/13 truths verified). Gehoiste `AbstractMolliePassThroughController::buildClient` zorgt voor verbatim Consumer-header forward naar Mollie SDK; 7 resources (Payments / Customers / PaymentMethods / Refunds / Mandates / Subscriptions / PaymentLinks) + 22 routes onder `/v1/mollie/*`.
- [x] **MOLL-04** — `MollieWebhookVerifier` Connect-webhooks. Gevalideerd in Phase 5a — signature-verify via SDK helper + anti-spoofing-fetch + fan-out via `spatie/laravel-webhook-server` naar Consumer-callback + stap-0 hard-fail guard bij empty/null `MOLLIE_WEBHOOK_SECRET` (D-08 stap 1, T-05a-06).
- [x] **HUB-03** — Pass-through REST API `/v1/mollie/*`. Gevalideerd in Phase 5a — multi-tenant Bearer→Consumer→Account→Connection-resolutie + `PassThroughCall` audit-log + error-mapping (401→502 cloaked, 422→422, 404→404, 429→429+RetryAfter, 5xx→502, timeout→504) + Scramble OpenAPI op `/docs/api`. Cross-Consumer-access geeft 404 (geen leakage).

### Active

<!-- v0.2 milestone actief sinds 2026-05-14. 9/11 phases shipped, 2 phases open. Authoritative status: `.planning/REQUIREMENTS.md` + `.planning/ROADMAP.md`. -->

Open v0.2-requirements:

- **HUB-06** — Snelstart webhook-handler op `POST /webhooks/snelstart` (HMAC-verified ingress + audit-log + async fan-out). Phase 5c, **BLOCKED** op partner@snelstart.nl certificeringsantwoord (Gmail draft `r-8836998535038336548`, verwacht ≤ 2026-05-26). Zie `packages/snelstart-api/docs/decisions/snelstart-certificering-pad.md`.
- **NSCH-01** — Naschool wiring: Stancl-tenancy resolver voor Snelstart + composer-VCS-entries voor `emeq/snelstart-api` + `emeq/mollie-api`. Phase 8, unblocked alternatief pad terwijl HUB-06 op partner wacht.
- **NSCH-02** — Naschool `EnrollmentConfirmed` → Snelstart-verkoopfactuur via `SyncEnrollmentToSnelstartJob`. Phase 8.
- **NSCH-03** — Naschool vrijwillige-bijdrage-flow via Mollie Connect (= via Hub-Connect, op school's eigen Mollie). Phase 8, e2e-bewijs smoke-test.

Open human-UAT: 3 items in `.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-HUMAN-UAT.md` (Scramble UI render, live Mollie testmode webhook, NSCH-03 e2e — laatste hangt aan NSCH-03 hierboven).

### Next Milestone Backlog (v0.3+, niet gestart)

> v0.3 scope nog niet gepland — formaliseren bij `/gsd-new-milestone v0.3` na v0.2 close. Bron-van-waarheid voor de tracked-deferred items: `.planning/REQUIREMENTS.md` § Future Requirements.

Carry-forward kandidaten (uit REQUIREMENTS.md):

- **SNEL-V4** — Snelstart-SDK Saloon v3 → v4 (3 ignored security advisories)
- **PROV-MONEYBIRD / PROV-EXACT / PROV-IBANITY / PROV-STRIPE** — extra provider-SDKs
- **HUB-BILLING / HUB-DOCS / HUB-ONBOARDING** — commerciële Hub-features voor derde-partij Consumers

### Out of Scope

<!-- Permanent buiten roadmap of expliciet later. -->

- **`emeq/exact-api`, `emeq/moneybird-api`, `emeq/ibanity-api`, `emeq/stripe-api`** — derde+ providers; wachten tot Mollie+Snelstart-pattern gevalideerd is in productie.
- **DTO-codegen vanuit OpenAPI specs** — Snelstart `Dto/` en `Resources/` blijven leeg; consumers gebruiken `RawSnelstartRequest` + OData QueryBuilder. Codegen pas wanneer `emeq/hub` typed responses nodig heeft.
- **Snelstart Saloon v3 → v4** — 3 ignored security advisories oplossen; v0.3-werk.
- **Commerciële Hub-features** (billing, public docs-site, self-service onboarding) — pas in latere milestone na v0.2.

## Current State (per 2026-05-16, na Phase 10 ship)

- **Shipped:** v0.1 — `emeq/snelstart-api` SDK live op `github.com:yusufkaracaburun/emeq-snelstart-api` (`main` @ `16c9ecc`), Pest-suite groen (107/187), VCS-installeerbaar zonder auth.
- **Active milestone:** v0.2 — Mollie + Connect + Subscriptions + Hub-skeleton. Phases 2 / 3 / 4 / 5a / 5b / 6 / 7 / 9 / 10 complete (9/11 phases done). Open: Phase 5c (BLOCKED, partner-response) + Phase 8 (Naschool wiring, unblocked).
- **Recent ship:** Phase 10 — Phase 9 polish (11 deferred review-findings closed: CR-02 permission-enforcement + Hub-eigen `App\Models\WebhookCall` + cross-Consumer-isolation test bewijs voor HUB-04 SC-7 + WR-01..06 + IN-01..04). 6/6 plans, 437 tests / 1479 assertions / 1 pre-existing incomplete. Verifier 13/13 truths passed.
- **Architectuur-vision** (geverifieerd 2026-05-14): Emeq = Mollie Connect Partner. Consumers (Naschool, Planny, derde-partij SaaS) routeren door Hub. Accounts (klanten van die SaaS-apps) koppelen eigen partner-credentials via OAuth (Mollie Connect, Snelstart oAuth, etc.). Subscriptions: zowel Emeq→Consumers (Cashier-Mollie pattern) als Accounts→eindgebruikers (Connect + eigen subscription-laag).

## Current Milestone: v0.2 Mollie + Connect + Subscriptions + Hub-skeleton

**Goal:** `emeq/mollie-api` SDK + Mollie Connect OAuth-broker + Hub-skeleton (Consumer/Account/Connection-tabellen) + Subscriptions voor beide use-cases + Naschool wiring — eindstand: Naschool's vrijwillige-bijdrage flow loopt namens School A op School A's eigen Mollie-account via Hub-Connect.

**Target features:**
- `emeq/mollie-api` foundation (wrap `mollie/mollie-api-php`, multi-tenant `MollieCredentialResolver`, dual creds API-key + OAuth)
- Hub-skeleton: `consumers`/`accounts`/`connections` tabellen + Sanctum-PAT-auth + pass-through `/v1/mollie/*` REST API
- Mollie Connect OAuth-broker (client_id/client_secret, redirect-handler, token-exchange, refresh-flow, encrypted token-storage)
- `emeq/mollie-api` Resources + Webhooks (Payments, Customers, PaymentMethods, Refunds, Mandates, Subscriptions; Connect-webhook HMAC-verifier)
- Cashier-Mollie integratie (use-case A: Emeq → Naschool/Planny billing)
- Account-level subscriptions via Connect (use-case B: Accounts → eindgebruikers)
- Naschool wiring (Snelstart Stancl-resolver + EnrollmentConfirmed-job + Mollie-via-Hub checkout-flow)

**Phases:** 2-9 (continued numbering vanaf v0.1's Phase 1). Volledig plan in [`.claude/plans/fancy-honking-spring.md`](../.claude/plans/fancy-honking-spring.md).

## Context

- **Multi-repo workspace**: `.planning/` leeft in `emeq-hub` als coordinatie. Code-fases werken in 3 repos:
  - `packages/snelstart-api/` ↔ `github.com:yusufkaracaburun/emeq-snelstart-api` (sub-repo, geregistreerd in `planning.sub_repos`) — **v0.1 shipped**
  - `packages/mollie-api/` ↔ `github.com:yusufkaracaburun/emeq-mollie-api` (bestaat al sinds 2026-05-13, publiek, leeg — wordt actief in v0.2 Fase 1)
  - `/Users/yusufkaracaburun/Sites/localhost/school-activities-hub/` — Naschool app, **buiten** deze workspace; wordt geraakt in v0.2 wiring-fase
- **Snelstart-SDK staat**: gepusht naar `origin/main` @ `16c9ecc` (Phase 1 afgerond 2026-05-14), upstream-tracking actief, VCS-installeerbaar zonder auth. `OData/{Filter,Guid,QueryBuilder}.php` aanwezig. `Dto/` en `Resources/` leeg by design.
- **Naschool integratie-pattern (v0.1-vision, herzien voor v0.2)**: Stancl-tenancy met per-tenant credentials in `Tenant->settings()` voor Snelstart. Voor Mollie schuift dit naar Hub-Connection-routing in v0.2.
- **Verplicht: feature-validatie per SDK**. Niet "code in productie", maar "één concrete Naschool-feature draait erop".

## Constraints

- **Tech stack**: PHP 8.4, Laravel 13.9, Saloon v4 (gebruikt in `emeq/snelstart-api`; `emeq/mollie-api` wrapt `mollie/mollie-api-php` rechtstreeks, geen Saloon-laag), Spatie laravel-data. Tests: PHPUnit 12 in de Hub, Pest in SDK-packages. Geen afwijking zonder approval.
- **Timeline**: v0.2-indicatie ~8-10 weken vanaf milestone-kickoff.
- **Repo-grenzen**: SDK-packages krijgen géén Hub-domeinmodellen (`Connection`, `Account`, etc.) — invariant uit CLAUDE.md. Hub-tabellen leven in `emeq-hub` zelf.
- **Tokens encrypted at rest**: gevoelige credentials (clientkey, subscription-key, API-key, OAuth access/refresh tokens) nooit raw in DB of logs. Fingerprint-only voor debugging.
- **Geen verzonnen partner-features**: code moet exact kloppen met officiële Snelstart/Mollie docs (zie `.docs/partners/<provider>/`).
- **Git-policy**: nooit op `master` werken, nooit pushen zonder approval, geen `--no-verify`.

## Key Decisions

| Decision | Rationale | Status |
|----------|-----------|--------|
| SDK-first, geen Hub-platform in v0.1 | Eerst SDK-pattern valideren | ✅ Validated v0.1 (Snelstart-SDK shipped, pattern bewezen) |
| ~~Eigen Saloon-wrapper voor Mollie ipv `mollie/mollie-api-php` als dependency~~ | ~~Consistency met snelstart-api~~ | ❌ **Reversed 2026-05-14** — `emeq/mollie-api` wrapt `mollie/mollie-api-php` direct (niet eigen Saloon, niet `laravel-mollie`). Reden: laravel-mollie issue #245/PR #246 multi-tenant afgewezen; eigen Saloon ~70% meer code dan winst |
| `Dto/` + `Resources/` leeg laten in Snelstart-SDK | `RawSnelstartRequest` + OData QueryBuilder dekt alle 96 endpoints zonder 32 resource-classes te genereren | ✅ Validated v0.1 |
| ~~API-key auth voor Mollie (geen OAuth2 Connect) in v0.1~~ | ~~SaaS-apps werken in eigen Mollie-account~~ | ❌ **Reversed 2026-05-14** — Mollie Connect-flow zit in v0.2 dag 1 (Emeq = Mollie Partner; Accounts koppelen eigen Mollie). API-key auth blijft als fallback voor Emeq's eigen Mollie. |
| `.planning/` in emeq-hub committen (commit_docs: true) | Hub is canonical coordinatie-repo; planning artefacten overleven sessies en machines | ✅ Validated |
| Sequential execution (geen parallel) | Cross-repo werk laat zich slecht parallelliseren | ✅ Validated v0.1 |
| **Subscriptions in v0.2 — twee use-cases** (Emeq→Consumers + Accounts→eindgebruikers) | Beide patronen nodig: Emeq factuureert SaaS-licenties (Cashier-pattern), Accounts factureren ouders/leden via eigen Mollie (Connect-pattern) | New 2026-05-14 — Pending implementatie |
| **Mollie-facade alias = `EmeqMollie`** (niet `Mollie`) | Cashier-Mollie hangt af van `laravel-mollie` die `Mollie`-alias claimt; v0.2 wil beide pakketten naast elkaar | New 2026-05-14 — Pending implementatie |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-05-16 — "Active" + "Next Milestone Goals" stale-cluster opgeruimd na Phase 10 ship. Active sectie lijst nu alleen openstaande v0.2-requirements (HUB-06 BLOCKED, NSCH-01..03 in Phase 8); voormalige "Next Milestone Goals (v0.2 — voorbereid, niet gestart)" vervangen door "Next Milestone Backlog (v0.3+)". Current State bijgewerkt naar Phase 10. Validated-sectie nog niet uitgebreid met de in-tussen-gevalideerde requirements (MOLL-01 / MOLL-02 / HUB-02 / HUB-04 / HUB-05 / SUB-01 / SUB-02) — REQUIREMENTS.md is bron-van-waarheid; volgt bij `/gsd-complete-milestone v0.2`.*
