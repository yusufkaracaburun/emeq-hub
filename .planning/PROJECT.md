# Emeq integration stack

> **Master-plans:** [v0.2 strategy](../.claude/plans/2026-05-14-emeq-integration-strategy.md) · [v0.2 fancy-honking-spring (kickoff)](../.claude/plans/fancy-honking-spring.md). Deze PROJECT.md is de operationele synthese voor de GSD-workflow.

## Current Milestone: v0.3 Productie-closure

**Goal:** Sluit de v0.2 carry-forward (Naschool live E2E + Snelstart productie-cert) en reduceer risico (Saloon v4 / 3 security advisories + Mollie Connect productie-blocker) voordat nieuwe providers worden toegevoegd in v0.4+.

**Target features:**

- Naschool-repo wiring + live E2E door test-ouder (`NSCH-LIVE-E2E`) — Hub-side substrate is compleet per D-03; v0.3 sluit het lus.
- Snelstart-SDK Saloon v3 → v4 upgrade (`SNEL-V4`) — security advisories oplossen.
- Snelstart productie-certificering afsluiten (`SNEL-CERT-CLOSE`) — partner-respons handelen.
- Mollie Connect partner-resources via pass-through (`MOLL-CONNECT-RES`) — productie-blocker voor Connect-merchant onboarding.
- VERIFICATION.md backfill voor v0.2 Phases 4/6/7 (`VERIF-01`) — verification-debt closure.

**Key context:** Geen nieuwe provider-SDKs in v0.3 (Moneybird/Exact/Bizcuit/Ibanity/Stripe schuiven naar v0.4+). Geen commerciële Hub-features (HUB-BILLING/DOCS/ONBOARDING blijven backlog tot 2+ derde-partij Consumers concreet zijn). Phase-nummering continueert vanaf 11 (v0.2 eindigde op Phase 10).

## What This Is

Een Hub-platform en losse Laravel SDK-packages (`emeq/snelstart-api`, `emeq/mollie-api`) voor Nederlandse boekhoud- en betaal-partner-API's. De Hub (`emeq-hub`) host multi-tenant OAuth-koppelingen, webhook-routing, pass-through REST-API's en een Filament admin-paneel; SDKs leveren partner-specifieke wrapping. Na v0.2 zijn twee fundamenteel verschillende providers (Snelstart OData/clientkey + Mollie REST/OAuth) productie-gevalideerd via één SDK-pattern, beide via dezelfde Hub geconsumeerd. Doelgroep nu: Emeq's eigen SaaS-apps die ad-hoc partner-integraties vervangen door Hub-routing. Doelgroep v1.0+ (later): commercieel beschikbaar voor andere NL dev-shops.

## Core Value

**Twee fundamenteel verschillende providers (OData/clientkey + REST/OAuth2) productie-gevalideerd via één SDK-pattern, beide via één Hub geconsumeerd, multi-tenant + encrypted-at-rest + audit-logged + admin-managed.** v0.1 + v0.2 hebben dit Hub-side bewezen; v0.3 sluit met `NSCH-LIVE-E2E` de eerste concrete consumer-feature (Naschool) end-to-end.

## Requirements

### Validated

<!-- Shipped and confirmed valuable. -->

**v0.1:**

- [x] **SNEL-01** — Snelstart-SDK Pest-suite groen (107 passed / 187 assertions). Validated in Phase 1.
- [x] **SNEL-02** — Snelstart-SDK publiek op `github.com:yusufkaracaburun/emeq-snelstart-api`, VCS-installeerbaar zonder auth.

**v0.2 (15/15 ge-shipped, 3 hub-side per D-03 scope-fence):**

- [x] **MOLL-01** — `emeq/mollie-api` SDK skeleton + multi-tenant resolver + dual creds. Validated in Phase 2.
- [x] **MOLL-02** — Mollie Connect OAuth-broker via provider-agnostisch `OAuthFlow`-contract + lazy-refresh. Validated in Phase 4 (129/129 tests).
- [x] **MOLL-03** — `emeq/mollie-api` Resources (7 totaal, 22 routes) + Idempotency-Key auto-forward. Validated in Phase 5a.
- [x] **MOLL-04** — `MollieWebhookVerifier` voor Connect-webhooks + fan-out via spatie/laravel-webhook-server + secret-hard-fail-guard. Validated in Phase 5a.
- [x] **HUB-01** — `consumers`/`accounts`/`connections`-tabellen + Sanctum-PAT + encrypted credentials + multi-tenant scoping. Validated in Phase 3.
- [x] **HUB-02** — Provider-agnostisch `OAuthFlow`-contract met `FakeOAuthFlow` als pattern-portability-bewijs. Validated in Phase 4.
- [x] **HUB-03** — Pass-through REST API `/v1/mollie/*` met error-mapping + Scramble OpenAPI. Validated in Phase 5a.
- [x] **HUB-04** — Filament v4 admin-paneel `/admin` met 7 resources + Spatie laravel-permission 2-rol-model + `ProviderCredentialDescriptor` + Pennant feature-flag kill-switch. Validated in Phase 9 + Phase 10 polish.
- [x] **HUB-05** — Pass-through REST API `/v1/snelstart/{path}` met `HubSnelstartCredentialResolver` + eigen `pass_through_calls`-tabel. Validated in Phase 5b (8/8 verifier must-haves, 86 tests).
- [x] **HUB-06** — Snelstart webhook-handler `POST /webhooks/snelstart` met HMAC + Connection-resolutie + async fan-out. Validated in Phase 5c (SC-1..5 via E2E-test). HMAC-verifier verplaatst naar SDK per ADR `sdk-redistributability-boundary.md`. **Productie-cert wacht op partner-respons** (Gmail draft `r-8836998535038336548` ≤2026-05-26).
- [x] **SUB-01** — Cashier-Mollie integratie use-case A (Emeq→Consumers). Validated in Phase 6 (`mollie/laravel-cashier-mollie ^2.20.1` pad-a out-of-the-box).
- [x] **SUB-02** — Account-level subscriptions use-case B met multi-tenant `AccountSubscription`-state-machine. Validated in Phase 7.
- [x] **NSCH-01** — Hub-side substrate (`ConsumerOnboarding` atomic flow). Validated in Phase 8 per D-03. Naschool-repo werk → v0.3 (`NSCH-LIVE-E2E`).
- [x] **NSCH-02** — Hub-side substrate (Snelstart job-pattern + resolver). Validated in Phase 8 per D-03. Live `EnrollmentConfirmed`-listener → v0.3.
- [x] **NSCH-03** — Hub-side substrate (`StartOAuthFlowAction` + partner-pages + onboard-wizard). Validated in Phase 8 per D-03. Live E2E door test-ouder → v0.3.
- [x] **MOLL-05** (`MOLL-CONNECT-RES`) — Mollie Connect partner-resources via pass-through-API: 9 routes onder `/v1/mollie/connect/*` (Onboarding, Organizations, Profiles, Permissions, ClientLinks) met partner-access-token via `MollieAccessTokenResolver`. Validated in Phase 13 (verifier 4/4, 184 tests).
- [x] **MOLL-06** — `MollieAccessTokenResolver` met dual-path (partner-env-token voor `/v1/mollie/connect/*`, Connection-token voor merchant-routes); `TokenResolverIntegrationTest` bewijst beide paden. Validated in Phase 13.

### Active

**v0.3 — Productie-closure (Naschool live + risk-reductie):**

- [ ] **NSCH-04** (`NSCH-LIVE-E2E`) — Naschool-repo composer-VCS-entries voor `emeq/snelstart-api` + `emeq/mollie-api`, `StancltenancyCredentialResolver` voor Snelstart, `EnrollmentConfirmed`-listener-wiring met `SyncEnrollmentToSnelstartJob`, live Mollie checkout-flow walkthrough door test-ouder met webhook → enrollment-status update. Sluit Hub-side substrate (D-03) + 3 deferred Phase 5a human-UAT items af.
- [ ] **SNEL-03** (`SNEL-V4`) — Snelstart-SDK Saloon v3 → v4 upgrade. Lost 3 ignored security advisories op (o.a. SSRF via endpoint-override). Hub composer-update + regressie-suite.
- [ ] **SNEL-04** (`SNEL-CERT-CLOSE`) — Snelstart productie-certificering afsluiten: partner-respons verwerken (Gmail draft `r-8836998535038336548`, deadline ≤2026-05-26), vraag #4 (retry-policy) beantwoorden, Hub-config voor cert-headers/endpoint indien vereist.
<!-- MOLL-05 + MOLL-06 validated in Phase 13 — moved to Validated section above. -->

- [ ] **VERIF-01** — VERIFICATION.md backfill voor v0.2 Phases 4, 6, 7. Goal-backward audits per phase via `/gsd-verify-work` met ACCEPTANCE-files als startbewijs. Sluit verification-debt zonder code-changes.

### Next Milestone Backlog (v0.3+)

Carry-forward kandidaten (bron-van-waarheid: [`ROADMAP.md`](ROADMAP.md) Backlog-sectie):

- **NSCH-LIVE-E2E** — Naschool-repo wiring + live E2E door test-ouder (Hub-side compleet per D-03)
- **SNEL-V4** — Snelstart-SDK Saloon v3 → v4 (3 security advisories)
- **PROV-MONEYBIRD / PROV-EXACT / PROV-IBANITY / PROV-STRIPE / PROV-BIZCUIT** — extra provider-SDKs
- **MOLL-CONNECT-RES** — Mollie Connect partner-resources via pass-through (blokkerend voor host-app productie-go-live met Connect-merchants)
- **HUB-BILLING / HUB-DOCS / HUB-ONBOARDING / HUB-AUDIT** — commerciële Hub-features
- **SCRAMBLE-NESTED-GROUPS** — Echte hiërarchische groepering in `/docs/api` (trigger: 5+ providers)
- **BRAIN-AUDIT-CI** — Laravel-Brain in CI (trigger: 3+ SDKs of v1.0 commercieel)

### Out of Scope

<!-- Permanent buiten roadmap of expliciet later. -->

- **DTO-codegen vanuit OpenAPI specs** — Snelstart `Dto/` en `Resources/` blijven leeg; consumers gebruiken `RawSnelstartRequest` + OData QueryBuilder. Codegen pas wanneer `emeq/hub` typed responses nodig heeft.
- **Cashier-Mollie upstream-PR** — Als compat-issues optreden: fork-and-update of zelf bouwen; geen upstream-PR-pad.
- **Naschool's volledige financiële module** — Alleen vrijwillige-bijdrage checkout-flow + Snelstart-verkoopfactuur-flow als POC; geen full ledger, multi-currency, of tax-rule-engine.
- **Commerciële Hub-features in pre-v1.0** — Billing, public docs-site, self-service onboarding pas wanneer minimaal 2 derde-partij Consumers actief willen integreren.

## Current State (per 2026-05-17 na v0.2-ship)

- **Shipped:**
  - **v0.1 (2026-05-14)** — `emeq/snelstart-api` SDK live op `github.com:yusufkaracaburun/emeq-snelstart-api`, Pest 107/187, VCS-installeerbaar zonder auth.
  - **v0.2 (2026-05-17)** — Mollie + Connect + Subscriptions + Hub-skeleton + Filament admin-paneel. 11 phases, 67 plans, ~498 tests, ~100k LOC over 4 dagen. Hub-side substrate voor Naschool compleet per D-03; live E2E naar v0.3.
- **Active milestone:** geen — v0.3 nog niet gestart.
- **Architectuur-vision** (gevalideerd v0.1 + v0.2): Emeq = Mollie Connect Partner. Consumers (Naschool, Planny, derde-partij SaaS) routeren door Hub. Accounts (klanten van die SaaS-apps) koppelen eigen partner-credentials via OAuth (Mollie Connect) of credential-form (Snelstart clientkey). Subscriptions in twee patronen: Emeq→Consumers (Cashier-Mollie) en Accounts→eindgebruikers (Connect + eigen multi-tenant subscription-laag).

## Constraints

- **Tech stack**: PHP 8.4, Laravel 13.9, Saloon v4 (in `emeq/snelstart-api`; `emeq/mollie-api` wrapt `mollie/mollie-api-php` direct), Spatie laravel-data. Tests: PHPUnit 12 in de Hub, Pest in SDK-packages. Geen afwijking zonder approval.
- **Repo-grenzen**: SDK-packages krijgen géén Hub-domeinmodellen (`Connection`, `Account`, etc.) — invariant uit CLAUDE.md. Hub-tabellen leven in `emeq-hub` zelf.
- **Tokens encrypted at rest**: gevoelige credentials (clientkey, subscription-key, API-key, OAuth access/refresh tokens) nooit raw in DB of logs. Fingerprint-only voor debugging.
- **Geen verzonnen partner-features**: code moet exact kloppen met officiële Snelstart/Mollie docs (`.docs/partners/<provider>/` of de SDK-eigen `packages/<sdk>/docs/partners/<provider>/`).
- **Git-policy**: nooit op `master` werken voor feature-werk (milestone-archive-commits uitgezonderd), nooit pushen zonder approval, geen `--no-verify`.

## Key Decisions

| Decision | Rationale | Status |
|----------|-----------|--------|
| SDK-first, geen Hub-platform in v0.1 | Eerst SDK-pattern valideren | ✅ Validated v0.1 (Snelstart-SDK shipped, pattern bewezen) |
| `Dto/` + `Resources/` leeg laten in Snelstart-SDK | `RawSnelstartRequest` + OData QueryBuilder dekt alle 96 endpoints | ✅ Validated v0.1 |
| ~~Eigen Saloon-wrapper voor Mollie~~ | ~~Consistency met snelstart-api~~ | ❌ **Reversed 2026-05-14** — `emeq/mollie-api` wrapt `mollie/mollie-api-php` direct. Reden: laravel-mollie multi-tenant afgewezen; eigen Saloon ~70% overhead. |
| ~~API-key auth voor Mollie~~ | ~~SaaS-apps werken in eigen Mollie-account~~ | ❌ **Reversed 2026-05-14** — Mollie Connect dag 1 (Emeq = Mollie Partner). API-key fallback blijft voor Emeq's eigen Mollie. |
| `.planning/` in emeq-hub committen | Hub is canonical coordinatie-repo | ✅ Validated |
| Subscriptions in twee use-cases | Cashier voor Emeq→Consumers; eigen laag voor Accounts→eindgebruikers via Connect | ✅ Validated v0.2 (Phases 6 + 7) |
| `EmeqMollie`-facade naast `Mollie` | Cashier-Mollie hangt af van `laravel-mollie` (Mollie-alias); coexist runtime mogelijk | ✅ Validated v0.2 (Phase 2 SC#3 → bewezen in Phase 6 met Cashier naast EmeqMollie) |
| Provider-agnostisch `OAuthFlow`-contract | Pattern toekomst-bestendig voor Snelstart-OAuth / Exact-OAuth / Ibanity-OAuth in v0.3+ | ✅ Validated v0.2 (Phase 4 SC-4 via `FakeOAuthFlow`) |
| `pass_through_calls` als eigen tabel (afgesplitst van `webhook_calls`) | Pass-through ≠ fan-out; verschillende schema's | ✅ Validated v0.2 (Phase 5b, ADR `pass-through-calls-table.md`) |
| Cashier-Mollie pad-a (out-of-the-box) i.p.v. fork | `mollie/laravel-cashier-mollie ^2.20.1` werkt op PHP 8.4 / Laravel 13 | ✅ Validated v0.2 (Phase 6 compat-ADR) |
| Spatie laravel-permission ^6 met 2-rol-model | `super-admin`/`staff` + 6 permissions schaalt beter dan `is_emeq_staff` boolean | ✅ Validated v0.2 (Phase 9 D-05) |
| `ProviderCredentialDescriptor` als single source of truth | Nieuwe provider = config-row + factory-state, geen nieuwe Resource-class | ✅ Validated v0.2 (Phase 9 D-04) |
| Pennant feature-flag voor provider kill-switch | `feature.provider:{provider}` middleware-alias auto-gedefinieerd op `config('hub-providers')` keys | ✅ Validated v0.2 (Phase 8, ADR `feature-flags-pennant-kill-switch.md`) |
| Hub-eigen `WebhookCall` model extending Spatie's | N+1-fix + `consumer()` belongs-to + cross-Consumer-isolation test-bewijs | ✅ Validated v0.2 (Phase 10) |
| HMAC-verifier naar SDK (Snelstart) | SDK-redistributability boundary; consumers buiten Hub kunnen ook verifiëren | ✅ Validated v0.2 (Phase 5c, ADR `sdk-redistributability-boundary.md`) |
| D-03 scope-fence Phase 8 | Hub-side only; Naschool-repo werk + live E2E naar v0.3 | ✅ Validated v0.2 (Phase 8 status `human_needed`) |

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
*Last updated: 2026-05-18 — Phase 13 complete (Mollie Connect partner-resources). MOLL-05 + MOLL-06 verplaatst van Active → Validated. v0.3 staat op 2/5 phases shipped (Phase 11 Saloon v4 + Phase 13 Connect-resources); 3 phases open (12 Snelstart-cert, 14 Naschool live E2E, 15 VERIFICATION backfill).*
