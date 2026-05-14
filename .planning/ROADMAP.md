# Roadmap: Emeq integration stack

**Project code:** EMEQ
**Granularity:** milestone-based archives (per-milestone ROADMAP in `.planning/milestones/`)
**Execution:** sequentieel per milestone

## Shipped Milestones

- **v0.1 (2026-05-14)** — Snelstart-SDK finale. Zie [`milestones/v0.1-ROADMAP.md`](milestones/v0.1-ROADMAP.md) · [`MILESTONES.md`](MILESTONES.md)

## Active Milestone

*Geen actieve milestone.*

Volgende milestone (v0.2 — Mollie + Connect + Subscriptions + Hub-skeleton) is voorbereid in [`.claude/plans/fancy-honking-spring.md`](../.claude/plans/fancy-honking-spring.md) maar nog niet formeel gestart. Start via `/gsd-new-milestone v0.2`.

## Backlog

Verzamelpunt voor ideeën die nog geen milestone hebben. Bij milestone-kickoff worden relevante items uit deze sectie naar de active milestone gepromoveerd.

### v0.2-gebonden (klaar voor `/gsd-new-milestone v0.2`)

Voorlopige fase-indicatie + requirements staan in `.claude/plans/fancy-honking-spring.md` sectie "Stap 2: v0.2-milestone kickoff":

- `emeq/mollie-api` foundation (wrap `mollie/mollie-api-php`, multi-tenant resolver, dual creds API-key + OAuth)
- Mollie Connect OAuth-broker (client_id/client_secret, redirect-handler, token-exchange, refresh-token-flow, `access_`-token storage encrypted)
- `emeq/mollie-api` Resources + DTOs: Payments, Customers, PaymentMethods, Refunds, Mandates, Subscriptions
- `MollieWebhookVerifier` voor Connect-webhooks (HMAC-signed)
- Hub-skeleton: `consumers`, `accounts`, `connections` tabellen + Sanctum-PAT-auth voor Consumer-routes
- OAuth-broker pattern (provider-agnostisch contract)
- Pass-through REST API `/v1/mollie/*` met Bearer-token-resolutie
- Cashier-Mollie integratie (use-case A: Emeq → Consumers billing) — compat-check PHP 8.4 / Laravel 13 nodig
- Account-level subscriptions via Connect (use-case B: Accounts → eindgebruikers)
- Naschool wiring: Stancl-tenancy resolver Snelstart, EnrollmentConfirmed → Snelstart-verkoopfactuur, vrijwillige-bijdrage-flow via Mollie Connect via Hub

Realistische timeline v0.2: ~8-10 weken vanaf milestone-start.

### v0.3+ (langere termijn)

- Snelstart Saloon v3 → v4 upgrade (3 ignored security advisories oplossen)
- Andere providers wanneer Mollie+Snelstart in productie gevalideerd: `emeq/moneybird-api`, `emeq/exact-api`, `emeq/ibanity-api`, `emeq/stripe-api`
- DTO-codegen vanuit OpenAPI specs voor providers die typed-response consumers nodig hebben

---

*Roadmap collapsed na v0.1-archivering op 2026-05-14. Phase-level details in milestone-archives onder `.planning/milestones/`.*
