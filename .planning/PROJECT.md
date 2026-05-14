# Emeq integration stack (v0.1)

> **Master-plan**: [`.claude/plans/2026-05-14-emeq-integration-strategy.md`](../.claude/plans/2026-05-14-emeq-integration-strategy.md) — bron-van-waarheid voor scope, locked decisions en track-breakdown.
> Deze PROJECT.md is de operationele synthese voor de GSD-workflow.

## What This Is

Een set van losse, Saloon-gebaseerde Laravel SDK-packages (`emeq/snelstart-api`, `emeq/mollie-api`) voor Nederlandse boekhoud- en betaal-partner-API's, plus integratie-wiring in Emeq's eerste consumer-app (Naschool). Doelgroep v0.1: Emeq's eigen SaaS-apps die nu ad-hoc partner-integraties hebben. Doelgroep v1.0+ (later): commercieel beschikbaar voor andere NL dev-shops.

## Core Value

**Twee fundamenteel verschillende providers (OData/clientkey + REST/OAuth2) productie-gevalideerd via één SDK-pattern, en beide live in één concrete Naschool-feature.** Dat valideert het pattern voor toekomstige SDKs en levert directe DRY-winst in Naschool.

## Requirements

### Validated

<!-- Shipped and confirmed valuable. -->

- [x] **SNEL-01** — Snelstart-SDK Pest-suite groen (107 passed / 187 assertions). Gevalideerd in Phase 1 (`fase-4 crash` bleek NO REPRO op `main @ 76e0797`; rewrote SnelstartConnectorTest van 1 → 12 cases met directe `getRequestException()`-coverage).
- [x] **SNEL-02** — Snelstart-SDK gepusht naar `github.com:yusufkaracaburun/emeq-snelstart-api` met upstream-tracking. VCS-installeerbaar zonder auth bewezen via smoke-test.

### Active

<!-- Geen actieve milestone — v0.1 SHIPPED 2026-05-14. v0.2 voorbereid in `.claude/plans/fancy-honking-spring.md`; nog niet formeel gestart. Start via `/gsd-new-milestone v0.2`. -->

*(Geen requirements actief — wachtend op `/gsd-new-milestone v0.2`.)*

### Next Milestone Goals (v0.2 — voorbereid, niet gestart)

> v0.2 Working title: **"Mollie + Connect + Subscriptions + Hub-skeleton"**
> Realistische timeline: ~8-10 weken vanaf milestone-start
> Volledig plan: [`.claude/plans/fancy-honking-spring.md`](../.claude/plans/fancy-honking-spring.md)

Carry-forward requirements (formaliseren bij `/gsd-new-milestone v0.2`):

- **MOLL-01** — `emeq/mollie-api` skeleton wrappend `mollie/mollie-api-php` — multi-tenant `MollieCredentialResolver`-pattern, dual creds (API-key + OAuth)
- **MOLL-02** — Mollie Connect OAuth-broker: client_id/client_secret config, redirect-handler, token-exchange, refresh-token-flow, `access_`-token storage encrypted
- **MOLL-03** — `emeq/mollie-api` Resources + DTOs voor Payments, Customers, PaymentMethods, Refunds + **Mandates + Subscriptions** (nieuw vs oorspronkelijke v0.1)
- **MOLL-04** — `MollieWebhookVerifier` voor Connect-webhooks (HMAC-signed namens platform)
- **HUB-01** — Hub-skeleton: `consumers`, `accounts`, `connections` tabellen + Sanctum-PAT-auth voor Consumer-routes (Naschool)
- **HUB-02** — OAuth-broker pattern (provider-agnostisch contract, eerste implementatie = Mollie Connect)
- **HUB-03** — Pass-through REST API `/v1/mollie/*` — Bearer-token-resolutie naar `Connection.access_token` → SDK-call
- **SUB-01** — Cashier-Mollie integratie voor use-case A (Emeq rekent aan Naschool/Planny-klanten via Emeq's eigen Mollie) — compat-check PHP 8.4 / Laravel 13 nodig
- **SUB-02** — Account-level subscriptions via Connect voor use-case B (klanten rekenen aan hun eindgebruikers) — eigen subscription-laag boven Mollie's Subscriptions + Mandates API
- **NSCH-01** — Naschool wiring: Stancl-tenancy resolver voor Snelstart (uit oorspronkelijke v0.1, ongewijzigd)
- **NSCH-02** — Naschool's `EnrollmentConfirmed` → Snelstart-verkoopfactuur (uit oorspronkelijke v0.1, ongewijzigd)
- **NSCH-03** — Naschool's vrijwillige-bijdrage-flow via Mollie Connect (= via Hub, met klant-school's eigen Mollie-account)

### Out of Scope

<!-- Permanent buiten roadmap of expliciet later. -->

- **`emeq/exact-api`, `emeq/moneybird-api`, `emeq/ibanity-api`, `emeq/stripe-api`** — derde+ providers; wachten tot Mollie+Snelstart-pattern gevalideerd is in productie.
- **DTO-codegen vanuit OpenAPI specs** — Snelstart `Dto/` en `Resources/` blijven leeg; consumers gebruiken `RawSnelstartRequest` + OData QueryBuilder. Codegen pas wanneer `emeq/hub` typed responses nodig heeft.
- **Snelstart Saloon v3 → v4** — 3 ignored security advisories oplossen; v0.3-werk.
- **Commerciële Hub-features** (billing, public docs-site, self-service onboarding) — pas in latere milestone na v0.2.

## Current State (per 2026-05-14, na v0.1 ship + v0.2 kickoff)

- **Shipped:** v0.1 — `emeq/snelstart-api` SDK live op `github.com:yusufkaracaburun/emeq-snelstart-api` (`main` @ `16c9ecc`), Pest-suite groen (107/187), VCS-installeerbaar zonder auth.
- **Active milestone:** v0.2 — Mollie + Connect + Subscriptions + Hub-skeleton (~8-10 weken). Gestart 2026-05-14.
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

- **Tech stack**: PHP 8.4, Laravel 13.9, Saloon v3 (alleen relevant voor `emeq/snelstart-api`), Spatie laravel-data. Tests: PHPUnit 12 in de Hub, Pest in SDK-packages. Geen afwijking zonder approval.
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
*Last updated: 2026-05-14 after v0.1 milestone close (Snelstart-SDK shipped; v0.2 voorbereid)*
