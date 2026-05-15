## Wat dit project is

`emeq/hub` — multi-tenant integration platform. Eén centrale Laravel-app die OAuth-koppelingen, webhook-routing en een uniforme REST-API exposeert naar boekhoud-/betaal-partner-API's:

- **Snelstart** (boekhouden, NL) — via eigen SDK `emeq/snelstart-api` (VCS-repo, zie packages-conventie)
- **Mollie** (betalingen, NL/EU) — via eigen SDK `emeq/mollie-api` (VCS-repo) bovenop officiële `mollie/mollie-api-php` + `mollie/laravel-cashier-mollie` voor Subscriptions
- **Moneybird** (boekhouden, NL) — gepland, via toekomstige `emeq/moneybird-api` SDK
- **Ibanity** (PSD2/banking) — gepland
- **Exact Online** (boekhouden, NL/BE) — gepland

**Doelgroep v0.2**: Emeq's eigen SaaS-apps die nu ad-hoc partner-integraties hebben (Snelstart-pattern in v0.1 gevalideerd, Mollie + Connect + Subscriptions + Hub-skeleton in v0.2). v1.0+: commercieel beschikbaar voor andere NL dev-shops.

## Stack

- **PHP 8.4**, **Laravel 13.9**
- **Postgres 16** (eigen credentials + connections + audit-tabellen)
- **Redis 7** (queue + cache + session via predis)
- **Caddy 2** (reverse-proxy → host's `php artisan serve` op port 8001)
- **Sanctum** v4 — consumer-app auth (Personal Access Tokens)
- **Horizon** v5 — queue-dashboard + supervisor
- **Spatie webhook-server/client** — partner-event-fan-out naar consumer-callback-URLs
- **dedoc/scramble** — auto-OpenAPI op `/docs/api`

Lokaal: `php artisan serve --port=8001` op host, `docker compose up -d` voor db+redis+caddy → `http://hub.emeq.test:8090`.

## Architectuur

```
┌─────────────┐  HTTP/REST    ┌──────────────────────────┐  SDK calls   ┌─────────────┐
│ Consumer    │ ─────────────►│   emeq/hub (this app)    │ ───────────► │ Partner API │
│ (= SaaS app │               │                          │              │  (Snelstart,│
│  van Emeq   │ ◄─────────────│  Routes Bearer + ConnID  │              │   Mollie,   │
│  of derde)  │  webhooks     │  → right Connection →    │  webhooks    │   Moneybird,│
└─────────────┘               │  right SDK + tokens      │ ◄─────────── │   …)        │
                              │  → forward + audit       │              └─────────────┘
                              │                          │
                              │  Tables:                 │
                              │  - consumers             │
                              │  - personal_access_tokens│
                              │  - accounts              │
                              │  - connections           │
                              │  - pass_through_calls    │
                              │  - webhook_calls (Spatie)│
                              └──────────────────────────┘
```

**Domeinmodel:**

| Entity | Rol |
|---|---|
| **Consumer** | Eén van Emeq's 3 SaaS-apps, óf een betalende derde |
| **PersonalAccessToken** | Sanctum-token waarmee Consumer authentiseert |
| **Account** | Eindgebruiker bij een Consumer (= klant van die SaaS-app) — opgeslagen by `consumer_id + external_id` |
| **Connection** | Eén OAuth-koppeling tussen één Account en één Provider (Mollie/Snelstart/…). Encrypted tokens + expires_at + scopes |
| **PassThroughCall** | Audit-log voor Hub-pass-through-calls (Consumer → Hub → Partner → Consumer). Eén rij per request, immutable. Zie `.docs/decisions/pass-through-calls-table.md`. |
| **WebhookCall** (Spatie) | Fan-out-audit voor inkomende partner-webhooks en uitgaande consumer-callbacks via `spatie/laravel-webhook-client` + `…-server`. |

## Architectuur-invariants — niet zonder approval doorbreken

- **Consumer ↔ Account ↔ Connection chain is strict.** Een endpoint dat een Connection resolved doet dat altijd via `Bearer-token → Consumer → Account → Connection`. Nooit query-string `?connection_id=`, nooit X-headers zonder Consumer-validatie.
- **Tokens zijn versleuteld at rest.** `access_token`, `refresh_token`, `client_key` etc. op het Connection-model krijgen `protected $casts = ['access_token' => 'encrypted', …]`. Geen rauwe tokens in DB.
- **Geen partner-business-logic in SDK-packages.** SDKs zijn dun: HTTP-laag, auth-laag, DTOs. Webhook-routing, multi-tenancy, audit — leeft in de Hub.
- **Migrations zijn forward-only in prod.** Geen `down()` aanroepen na merge; voor schema-changes nieuwe migration.
