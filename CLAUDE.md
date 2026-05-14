# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Wat dit project is

`emeq/hub` — multi-tenant integration platform. Eén centrale Laravel-app die OAuth-koppelingen, webhook-routing en een uniforme REST-API exposeert naar boekhoud-/betaal-partner-API's:

- **Snelstart** (boekhouden, NL) — via lokale SDK `emeq/snelstart-api` in `packages/`
- **Mollie** (betalingen, NL/EU) — via officiële `mollie/mollie-api-php`
- **Moneybird** (boekhouden, NL) — gepland, via toekomstige `emeq/moneybird-api` SDK
- **Ibanity** (PSD2/banking) — gepland
- **Exact Online** (boekhouden, NL/BE) — gepland

**Doelgroep v0.1**: Emeq's eigen 3 SaaS-apps die nu allemaal hun eigen Mollie/Ibanity-integratie hebben. v1.0+: commercieel beschikbaar voor andere dev-shops.

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
                              │  - webhook_calls (in+out)│
                              └──────────────────────────┘
```

**Domeinmodel:**

| Entity | Rol |
|---|---|
| **Consumer** | Eén van Emeq's 3 SaaS-apps, óf een betalende derde |
| **PersonalAccessToken** | Sanctum-token waarmee Consumer authentiseert |
| **Account** | Eindgebruiker bij een Consumer (= klant van die SaaS-app) — opgeslagen by `consumer_id + external_id` |
| **Connection** | Eén OAuth-koppeling tussen één Account en één Provider (Mollie/Snelstart/…). Encrypted tokens + expires_at + scopes |
| **WebhookCall** | Audit-log: inkomend van partner ↔ outgoing naar consumer-callback |

## Packages-conventie

**`packages/` is gitignored.** Lokale dev-workspace voor SDK-packages die elk een eigen GitHub-repo hebben:

- `packages/snelstart-api/` ← `github.com:yusufkaracaburun/emeq-snelstart-api`

Composer require's de SDKs via **path repository** in `composer.json` (`symlink: true`). Live code-edits in `packages/<name>/src/` zijn direct actief — geen composer-update nodig. CI/prod hint: `composer require` zou hetzelfde pad via VCS-fallback kunnen ondersteunen, maar voor nu zijn we lokaal-only.

**SDKs ontwikkel je in hun eigen repo's** (clonen in `packages/`), commit en push je naar hun repo, en je laat de Hub gewoon naar het symlink-pad wijzen.

## Lokale dev — eerste keer

```bash
# 0. Eenmalig: /etc/hosts toevoegen
echo "127.0.0.1 hub.emeq.test" | sudo tee -a /etc/hosts

# 1. .env van .env.example
cp .env.example .env
php artisan key:generate

# 2. Stack omhoog (postgres + redis + caddy)
docker compose up -d

# 3. SDK clonen in packages/ (voor live-edit; anders volstaat composer install vanaf VCS)
mkdir -p packages
git clone git@github.com:yusufkaracaburun/emeq-snelstart-api.git packages/snelstart-api

# 4. Composer + migraties
composer install
php artisan migrate

# 5. Laravel + Horizon op host
php artisan serve --port=8001
php artisan horizon  # in 2e terminal
```

Open `http://hub.emeq.test:8090/up` → moet `{"status":"up","database":"ok","redis":"ok"}` teruggeven.

## Veelgebruikte commando's

```bash
# DB
php artisan migrate
php artisan migrate:fresh --seed

# Tests
./vendor/bin/pest --parallel
./vendor/bin/pint --dirty --format agent   # voor commit

# Horizon
php artisan horizon
php artisan horizon:status

# Routes
php artisan route:list --except-vendor

# Composer audit
composer audit                              # zie ignored advisories in composer.json
```

## Routes

```
routes/web.php       smoke: GET /, GET /up
routes/api.php       /v1/* — consumer-API (Bearer Sanctum)
routes/webhooks.php  /webhooks/{provider} — inkomend van partners (no auth, signature-verified per provider)
```

## Architectuur-invariants — niet zonder approval doorbreken

- **Consumer ↔ Account ↔ Connection chain is strict.** Een endpoint dat een Connection resolved doet dat altijd via `Bearer-token → Consumer → Account → Connection`. Nooit query-string `?connection_id=`, nooit X-headers zonder Consumer-validatie.
- **Tokens zijn versleuteld at rest.** `access_token`, `refresh_token`, `client_key` etc. op het Connection-model krijgen `protected $casts = ['access_token' => 'encrypted', …]`. Geen rauwe tokens in DB.
- **Geen partner-business-logic in SDK-packages.** SDKs zijn dun: HTTP-laag, auth-laag, DTOs. Webhook-routing, multi-tenancy, audit — leeft in de Hub.
- **Migrations zijn forward-only in prod.** Geen `down()` aanroepen na merge; voor schema-changes nieuwe migration.

## Documentatie

- **`CLAUDE.md`** (dit bestand) — entry-point voor Claude Code.
- **`.claude/rules/`** — altijd-actieve principes (`global.md`, `engineering.md`, `claude-mem-context.md`). `.cursor/rules/` heeft een onafhankelijke kopie voor Cursor.
- **`.claude/plans/`** — gitignored scratchpads voor multi-step werk.
- **`.claude/skills/`** — project-specifieke skills (`docs-sync`).
- **`.claude/hooks/`** — PostToolUse hooks (`docs-drift-check.sh`).

## Git policy — harde regels

- Nooit op `main` werken.
- Nooit `git push` zonder expliciete user-toestemming.
- Nooit `--no-verify`, `--no-gpg-sign`, of force-push tenzij user expliciet vraagt.
- Nooit secrets committen. Nooit `.env` aanpassen zonder approval.
- Nooit >3 files wijzigen in één commit zonder approval.

## Wat NIET in deze CLAUDE.md hoort

- Codepatronen die uit het lezen van sibling-files volgen.
- File-paden die met `find` / `grep` triviaal te vinden zijn.
- Generieke development-clichés ("schrijf goede tests") — die staan in `.claude/rules/` of skills.

<!-- GSD:project-start source:PROJECT.md -->
## Project

**Emeq integration stack (v0.1)**

Een set van losse, Saloon-gebaseerde Laravel SDK-packages (`emeq/snelstart-api`, `emeq/mollie-api`) voor Nederlandse boekhoud- en betaal-partner-API's, plus integratie-wiring in Emeq's eerste consumer-app (Naschool). Doelgroep v0.1: Emeq's eigen SaaS-apps die nu ad-hoc partner-integraties hebben. Doelgroep v1.0+ (later): commercieel beschikbaar voor andere NL dev-shops.

**Core Value:** **Twee fundamenteel verschillende providers (OData/clientkey + REST/OAuth2) productie-gevalideerd via één SDK-pattern, en beide live in één concrete Naschool-feature.** Dat valideert het pattern voor toekomstige SDKs en levert directe DRY-winst in Naschool.

### Constraints

- **Tech stack**: PHP 8.4, Laravel 13.9, Saloon v3 (met openstaande v4-upgrade-overweging), Spatie laravel-data, Pest. Geen afwijking zonder approval.
- **Timeline**: v0.1-deadline ~4 weken vanaf 2026-05-14.
- **Repo-grenzen**: SDK-packages krijgen géén Hub-domeinmodellen (`Connection`, `Account`, etc.) — invariant uit CLAUDE.md.
- **Tokens encrypted at rest**: gevoelige credentials (clientkey, subscription-key, API-key) nooit raw in DB of logs. Fingerprint-only voor debugging.
- **Geen verzonnen partner-features**: code moet exact kloppen met officiële Snelstart/Mollie docs (zie `.docs/partners/snelstart/`).
- **Git-policy**: nooit op `main` werken, nooit pushen zonder approval, geen `--no-verify`.
<!-- GSD:project-end -->

<!-- GSD:stack-start source:STACK.md -->
## Technology Stack

Technology stack not yet documented. Will populate after codebase mapping or first phase.
<!-- GSD:stack-end -->

<!-- GSD:conventions-start source:CONVENTIONS.md -->
## Conventions

Conventions not yet established. Will populate as patterns emerge during development.
<!-- GSD:conventions-end -->

<!-- GSD:architecture-start source:ARCHITECTURE.md -->
## Architecture

Architecture not yet mapped. Follow existing patterns found in the codebase.
<!-- GSD:architecture-end -->

<!-- GSD:skills-start source:skills/ -->
## Project Skills

| Skill | Description | Path |
|-------|-------------|------|
| docs-sync | Detecteert en herstelt documentatie-drift én organisatie-issues in `.docs/`, `CLAUDE.md` en memory voor de emeq-hub repo. Triggert proactief na domein-wijzigingen — niet wachten op merge: model/entity hernoemd, kolom verplaatst, nieuwe migration, nieuwe Sanctum-ability of Connection-provider, OAuth-flow gewijzigd, SDK-package toegevoegd of verwijderd uit `packages/`, route toegevoegd of verwijderd. Triggert ook bij doc-toevoegingen of -verplaatsingen in `.docs/`. Reactief op vragen als "check de docs", "update de docs", "klopt de documentatie nog", "synchroniseer docs", "klaar voor commit?", "ruim de docs op". Vangt zes problemen af: (1) stale class-/file-references, (2) ontbrekende ADR voor architecturele wijzigingen, (3) completed TODOs die niet als ✅ zijn gemarkeerd, (4) structuur-drift (nieuwe folders/files niet in `.docs/README.md` index, files op verkeerde plek), (5) verweesde docs (gemergde plans nog in `plans/`, lange ongewijzigde files), en (6) dode links (markdown-links naar non-existing files of code-paden). Use proactively whenever the user wraps up a domein-wijziging, just merged a branch, ran a refactor, added/moved a doc, or before any commit/push. | `.claude/skills/docs-sync/SKILL.md` |
<!-- GSD:skills-end -->

<!-- GSD:workflow-start source:GSD defaults -->
## GSD Workflow Enforcement

Before using Edit, Write, or other file-changing tools, start work through a GSD command so planning artifacts and execution context stay in sync.

Use these entry points:
- `/gsd-quick` for small fixes, doc updates, and ad-hoc tasks
- `/gsd-debug` for investigation and bug fixing
- `/gsd-execute-phase` for planned phase work

Do not make direct repo edits outside a GSD workflow unless the user explicitly asks to bypass it.
<!-- GSD:workflow-end -->

<!-- GSD:profile-start -->
## Developer Profile

> Profile not yet configured. Run `/gsd-profile-user` to generate your developer profile.
> This section is managed by `generate-claude-profile` -- do not edit manually.
<!-- GSD:profile-end -->
