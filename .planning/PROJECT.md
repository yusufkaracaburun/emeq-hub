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

(None yet — ship to validate)

### Active

<!-- v0.1 scope (~4 weken). Mapped naar GSD-fases via .planning/REQUIREMENTS.md. -->

- [ ] **SNEL-01** — Snelstart-SDK fase-4 Pest-crash opgelost; tests groen lokaal
- [ ] **SNEL-02** — Snelstart-SDK gepusht naar `github.com:yusufkaracaburun/emeq-snelstart-api`
- [ ] **MOLL-01** — Mollie-SDK skeleton + ServiceProvider + `MollieCredentialResolver`-contract
- [ ] **MOLL-02** — Mollie API-key auth + Saloon `MollieConnector` + error mapping
- [ ] **MOLL-03** — Resources: Payments, Customers, PaymentMethods, Refunds
- [ ] **MOLL-04** — `MollieWebhookVerifier` met signature-validation
- [ ] **NSCH-01** — Composer-wiring beide SDKs in Naschool + Stancl-tenancy credential resolvers
- [ ] **NSCH-02** — `SyncEnrollmentToSnelstartJob` werkend in lokale school1 demo-seed
- [ ] **NSCH-03** — Mollie checkout-flow op één activiteit + webhook → enrollment-status update

### Out of Scope

<!-- v0.1 boundaries. Reden expliciet om re-adding te voorkomen. -->

- **`emeq/hub`-platform-app** — pas na v0.1 in productie. Plan: alleen SDKs valideren eerst (twee providers in productie). Code-skeleton staat al in deze repo maar wordt niet uitgebreid in v0.1.
- **`emeq/exact-api`, `emeq/moneybird-api`, `emeq/ibanity-api`, `emeq/stripe-api`** — derde+ providers; wachten tot Mollie+Snelstart-pattern gevalideerd is.
- **DTO-codegen vanuit OpenAPI specs** — Snelstart `Dto/` en `Resources/` blijven leeg; consumers gebruiken `RawSnelstartRequest` + OData QueryBuilder. Codegen pas wanneer `emeq/hub` typed responses nodig heeft.
- **Mollie OAuth2 Connect-flow** — v0.1 = API-key-auth (SaaS-apps in eigen Mollie-account). Connect (3rd-party access) verschuift naar v0.2.
- **Snelstart fases 6-9** (resource-classes, webhook-handler, host-app-wiring binnen de SDK) — webhook-handling verhuist naar de toekomstige Hub.
- **`emeq/hub` H0-H5 roadmap** (in `.docs/todos/hub-roadmap.md`) — apart v0.2-werk, niet vermengen met v0.1.

## Context

- **Multi-repo workspace**: `.planning/` leeft in `emeq-hub` als coordinatie. Code-fases werken in 3 repos:
  - `packages/snelstart-api/` ↔ `github.com:yusufkaracaburun/emeq-snelstart-api` (sub-repo, geregistreerd in `planning.sub_repos`)
  - `packages/mollie-api/` ↔ nieuw te maken `github.com:yusufkaracaburun/emeq-mollie-api`
  - `/Users/yusufkaracaburun/Sites/localhost/school-activities-hub/` — Naschool app, **buiten** deze workspace
- **Snelstart-SDK staat**: fases 1-5 lokaal **gecommit** maar **niet gepusht** (`no upstream configured`). `OData/{Filter,Guid,QueryBuilder}.php` aanwezig. `Dto/` en `Resources/` leeg by design.
- **Snelstart fase-4 Pest-crash** — root-cause onbekend; plan: `MockClient`-pipeline droppen, exceptions direct unit-testen.
- **Naschool integratie-pattern**: Stancl-tenancy met per-tenant credentials in `Tenant->settings()` (central `tenant_admin` DB).
- **Verplicht: feature-validatie per SDK**. Niet "code in productie", maar "één concrete Naschool-feature draait erop". Snelstart: `EnrollmentConfirmed` → verkoopfactuur. Mollie: vrijwillige-bijdrage checkout-flow.

## Constraints

- **Tech stack**: PHP 8.4, Laravel 13.9, Saloon v3 (met openstaande v4-upgrade-overweging), Spatie laravel-data, Pest. Geen afwijking zonder approval.
- **Timeline**: v0.1-deadline ~4 weken vanaf 2026-05-14.
- **Repo-grenzen**: SDK-packages krijgen géén Hub-domeinmodellen (`Connection`, `Account`, etc.) — invariant uit CLAUDE.md.
- **Tokens encrypted at rest**: gevoelige credentials (clientkey, subscription-key, API-key) nooit raw in DB of logs. Fingerprint-only voor debugging.
- **Geen verzonnen partner-features**: code moet exact kloppen met officiële Snelstart/Mollie docs (zie `.docs/partners/snelstart/`).
- **Git-policy**: nooit op `main` werken, nooit pushen zonder approval, geen `--no-verify`.

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| SDK-first, geen Hub-platform in v0.1 | Twee providers gevalideerd in productie = genoeg signal of Hub-investering zinnig is | — Pending |
| Eigen Saloon-wrapper voor Mollie ipv `mollie/mollie-api-php` als dependency | Officiële SDK is niet Laravel-package-vormig (geen SP, geen tenant-credential-pattern); consistency met snelstart-api belangrijker | — Pending |
| `Dto/` + `Resources/` leeg laten in Snelstart-SDK | `RawSnelstartRequest` + OData QueryBuilder dekt alle 96 endpoints zonder 32 resource-classes te genereren | — Pending |
| API-key auth voor Mollie (geen OAuth2 Connect) in v0.1 | SaaS-apps werken in eigen Mollie-account; Connect-flow is voor 3rd-party access en kan wachten | — Pending |
| `.planning/` in emeq-hub committen (commit_docs: true) | Hub is canonical coordinatie-repo; planning artefacten moeten overleven across sessies en machines | — Pending |
| Sequential execution (geen parallel) | Cross-repo werk laat zich slecht parallelliseren; Track C (Naschool) heeft Track A+B nodig | — Pending |

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
*Last updated: 2026-05-14 after initialization*
