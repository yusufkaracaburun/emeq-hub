# CLAUDE.md

Guidance voor Claude Code in deze repo. Framework- en package-guidelines staan in `AGENTS.md` (Boost-beheerd, onderaan geïmporteerd) — niet inline dupliceren.

## Project

`emeq-hub` is een multi-tenant integration platform: één Laravel-app die OAuth-koppelingen, webhook-routing en een uniforme pass-through REST-API exposeert naar Nederlandse boekhoud- en betaal-partner-API's. Consumers zijn Emeq's eigen SaaS-apps; v1.0+ ook betalende derden. Partner-specifieke wire-logica leeft in dunne, losse SDK-packages (`emeq/<provider>-api`), niet in de Hub.

Providers: **Exact Online** (live — OAuth2-lifecycle, division-aware pass-through, named read-resources, accounting-sync, webhooks) · **Snelstart** (OData/clientkey) · **Mollie** (Connect + Cashier-subscriptions) · **Moneybird** en **Ibanity** gepland.

## Stack

PHP 8.4 · Laravel 13.9 · Postgres 16 · Redis 7 (queue/cache/session via predis) · FrankenPHP + Octane v2 worker-mode (dev én prod — runtime-parity) · Sanctum v4 (consumer-PATs) · Horizon v5 · Filament v4 · Inertia v3 + React 19 · `spatie/laravel-settings` · `spatie/laravel-webhook-server` + `-client` · dedoc/scramble (`/docs/api`). SDK-laag: Saloon v4 in `emeq/{snelstart,exact}-api`; `emeq/mollie-api` wrapt `mollie/mollie-api-php` rechtstreeks (géén Saloon) en `mollie/laravel-cashier-mollie` draagt Subscriptions. Tests: PHPUnit 12 in de Hub, Pest in de SDK-packages.

De hele stack draait lokaal in Docker (`docker compose up -d --build` → `http://hub.emeq.test:8092`, health-check `/up`). Dev = FrankenPHP `watch` + Vite-HMR, geen rebuild bij code-changes. Prod: `docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build` (HTTP-origin op `:80` achter Cloudflare-TLS, `trustProxies` aan). Setup + doc-URLs: `docs/agents/dev-environment.md`. Services en `Dockerfile`-targets: `docs/agents/docker.md`. Lagen en componenten: `docs/agents/architecture.md`.

## Domeinmodel

| Entity | Rol |
|---|---|
| **Consumer** | Eén van Emeq's SaaS-apps, óf een betalende derde |
| **PersonalAccessToken** | Sanctum-token waarmee de Consumer authentiseert |
| **Account** | Eindgebruiker bij een Consumer, uniek op `consumer_id + external_id` |
| **Connection** | Eén koppeling tussen één Account en één Provider. Encrypted tokens + `expires_at` + scopes |
| **PassThroughCall** | Immutable audit-rij per Consumer→Hub→Partner-request. Zie `.docs/decisions/pass-through-calls-table.md` |
| **ProviderEntityLink** | Canoniek `external_id` ⇄ partner-entity per Connection, met payload-fingerprint. Houdt herboeking tegen wanneer de idempotency-key weg is; fundament voor sync-state. Zie `docs/unified-api-architecture.md` |
| **InboundWebhookEvent** | Metadata-only audit van partner→Hub-webhooks via `App\Integrations\Webhooks\InboundWebhookRecorder` — géén payload of headers (AVG: de Hub is processor). Outbound fan-out loopt via spatie webhook-server en persisteert geen rij |

## Invariants — niet doorbreken zonder approval

- **De keten Consumer → Account → Connection is strikt.** Connection-resolution loopt altijd via de Bearer-PAT. Nooit `?connection_id=`, nooit X-headers zonder Consumer-validatie. Cross-consumer-leakage is een security-incident.
- **Tokens encrypted at rest** (`'access_token' => 'encrypted'`-casts). Nooit raw in DB, logs of exception-messages; gebruik een fingerprint (sha256, eerste 12 chars) voor debugging. Webhook-secrets per Connection — de app-brede Exact-webhook-secret is de enige gedocumenteerde uitzondering.
- **Geen partner-business-logic in SDK-packages.** Een SDK is HTTP + auth + DTOs. Webhook-routing, multi-tenancy en audit leven in de Hub; Hub-domeinmodellen (`Connection`, `Account`) horen niet in een SDK.
- **Geen verzonnen partner-features.** Endpoints, veldnamen, foutcodes en rate-limits moeten kloppen met de officiële partner-docs (`packages/<sdk>/docs/partners/<provider>/`). Bij twijfel vragen, niet aannemen.
- **Migrations zijn forward-only in prod.** Geen `down()` na merge; schema-change = nieuwe migration.

## Subsysteem-pointers

Volledige versie met gotchas en `.docs/decisions`-links: `docs/agents/subsystems.md`.

- **Werkdocumentatie** — `.docs/{decisions,plans,todos,errors,stack,strategy}/` (lokaal, gitignored); indeling in `.docs/README.md`.
- **Consumer-documentatie** — `docs/consumer-onboarding.md` is het startpunt: deel A = wat wij in de Hub inrichten per nieuwe Consumer (`app_url`, PAT-preset, rooktest), deel B = het stack-onafhankelijke contract dat elke consumer-app moet nakomen. Endpoints, payloads en de agent-prompts staan in `docs/consumer-integration-guide.md`. Bij elke wijziging aan het `/v1/*`-contract: beide bijwerken.
- **Admin-paneel** — Filament v4 op `/admin`; Spatie-rollen `super-admin`/`staff`/`boekhouder`; 4 NL nav-groups; Books-module top-level, gated via `GatedToBoekhouding`.
- **Provider-credential-laag** — `config/hub-providers.php` + `ProviderCredentialDescriptor` is de single source voor credential-metadata; provider-identiteit getypeerd via `App\Enums\Provider`.
- **Provider-kill-switch** — `App\Support\ProviderGate::enabled()` leest `config('hub-providers.<naam>.enabled')`; de middleware-alias blijft `feature.provider:{provider}`. De operator schakelt een provider aan of uit in `/admin` → Beheer → Providers; die toggle staat in `ProviderSettings` en wint bij boot van de config-default. Onbekende provider of ontbrekende sleutel = **uit**. Alleen Exact staat standaard aan.
- **Accounting-sync** — canonical `FinancialDocument` op `POST /v1/accounting/documents` → `AccountingTargetRegistry` → provider-adapter (Exact: salesentry/purchaseentry, géén memoriaal). Mapping wordt na connect automatisch afgeleid uit de mirror; dry-run via `POST /v1/accounting/documents/validate` (findings-rapport zónder te boeken).
- **Idempotency** — `Idempotency-Key`-header via `EnsureIdempotency`; `idempotent:required` op accounting-documents. Tweede laag: `provider_entity_links` dedupliceert op `(connection, external_id)` ook nadat de key weg is.
- **Observability** — één `request_id` (ULID) via `AssignRequestId`, in `Context` → logs, queued jobs, `pass_through_calls`, `inbound_webhook_events` en de `X-Emeq-Request-Id`-header op consumer-webhooks.
- **Exact-tokenrotatie** — `ConnectionTokenStore` logt `exact.oauth.refresh_attempt_started` (verlopen bundel opgehaald) en `exact.oauth.refresh_token_rotated` (nieuwe bundel weggeschreven), beide met token-fingerprints. Een start zonder bijbehorende rotatie is een halverwege afgebroken refresh — de faalmodus uit #61. Afsluitgedrag van de containers staat in `docs/agents/docker.md`.
- **Exact pass-through** — `/v1/exact/*` via `ExactForwarder`; named read-resources staan vóór de `{path}`-catch-all; wire-details leven in de SDK.
- **Partner-credentials in DB** — `ExactSettings` (encrypted at rest) → `config('services.exact.*')`, niet `.env`.
- **Webhooks** — Exact op `POST /webhooks/exact`, HMAC-signature in **uppercase hex**; subscriptions beheerd in de OAuth-lifecycle. Alle inkomende webhooks ge-audit via `InboundWebhookRecorder`.
- **OAuth-flow** — `/v1/oauth/{provider}/init` auto-provisiont het Account (PAT-ability `integrations:manage`); return-to-consumer via `ReturnUrlResolver` + `consumers.app_url` met open-redirect-guard.
- **`/v1/*` error-contract** — alle fouten zijn JSON, ook zonder Accept-header; ontbrekende PAT → `401 {code:"unauthenticated"}`, geen login-redirect.
- **Publieke SEO/GEO-surface** — `App\Support\PublicPages` bepaalt wat indexeerbaar is; meta + JSON-LD worden server-side gebouwd in `App\Support\Seo\*`. Inertia-SSR is verplicht: zonder SSR zien crawlers een lege body.

## Routes

```
routes/web.php       /up; signed OAuth-landing /oauth/{connected/{connection},failed}; signed consumer-handoff /connect/{account}{,/{provider}} incl. beheerdrawer (payload, mapping, relatie herkoppelen/ontkoppelen/zoeken); indexeerbare marketing-surface / · /partners{,/{provider}} · /koppelen · /demo · /support · /privacy · /voorwaarden · /verwerkersovereenkomst; crawler-routes /sitemap.xml · /robots.txt · /llms.txt
routes/api.php       /v1/* — consumer-API (Bearer Sanctum + throttle:api)
routes/webhooks.php  /webhooks/{provider}/{...} + /cashier/webhook* — publiek, signature-verified
routes/console.php   artisan-only commands
```

## Commands

```bash
docker compose exec app php artisan test --compact     # tests draaien in de container
cd packages/snelstart-api && ./vendor/bin/pest         # SDK-tests (eigen vendor)
./vendor/bin/pint --dirty --format agent               # vóór commit
composer audit                                         # ignored advisories staan in composer.json
make ssr                                               # SSR lokaal; stopt Vite-HMR
```

## Packages-conventie

`packages/` is **gitignored** en enkel een lees-clone voor referentie en grep (`emeq-snelstart-api`, `emeq-mollie-api`, `emeq-exact-api`). Composer require't de SDKs via een **VCS repository**, niet via een path-symlink: `packages/` bestaat niet op de deploy-target, dus een path-dist in `composer.lock` breekt de deploy.

Een SDK-change is edit + commit + push in de SDK-repo zelf, daarna `composer update emeq/<name>` in de Hub met `composer.lock` mee gecommit. Itereer in de SDK-repo met `./vendor/bin/pest` en sync pas als de change stabiel is. Beslis-gids: skill `change-sdk`.

## Git policy — hard

- Nooit op `master` werken. De `branch-guard`-hook blokkeert daar alleen Edit/Write —
  bewerkingen via Bash (heredoc, `sed -i`) glippen erlangs, dus de regel geldt ook waar
  de hook niet vuurt.
- Nooit `git push` zonder expliciete toestemming.
- Nooit `--no-verify`, `--no-gpg-sign` of force-push tenzij expliciet gevraagd.
- Nooit secrets committen. Nooit `.env` aanpassen zonder approval.
- Nooit meer dan 3 files in één commit zonder approval.

## Agent skills en werkwijze

ai-kit draait als plugin (`.ai-kit-setup`: `tier=full`, `mode=project-only`). Lifecycle-fase **development** — schema-migraties vrij te wijzigen, geen backwards-compat-eis vóór productie. Solo-repo: feature-/fix-branch → tests groen → ff-merge naar `master`, geen PR-ceremonie. Open en forward-werk staat in GitHub-issues (`P*`/`area/*`-labels; `/ai:next` rankt ze). Entrypoints: `/ai:tdd`, `/ai:diagnose`, `/ai:to-issues`, `/ai:review`. Detail in `docs/agents/workflow.md`.

Project-skills: **docs-sync** (documentatie-drift; proactief na domein-wijzigingen en vóór commit) · **add-provider** (nieuwe partner-SDK bouwen + aan de Hub koppelen) · **change-sdk** (bestaande SDK wijzigen, met de tabel "raak ik de Hub aan?") · **unified-api-review** (architectuurreview van de Unified-API-kern door de provider-#2-lens; schrijft rapport, wijzigt geen code). Authoritative conventies staan in `.agents/rules/` (auto-loaded: taal, engineering, security); ai-kit canonical rules in `.claude/rules/` (gitignored, aanvullend).

Taal: code en identifiers Engels; commits, PRs, docs en conversatie Nederlands; partner-domeintermen volgen de partner-API (Snelstart Nederlands, Mollie Engels — niet vertalen).

<!-- Boost schrijft zijn guidelines naar AGENTS.md, niet hierheen — zie config/boost.php.
     Staat er hieronder tóch een letterlijk <laravel-boost-guidelines>-blok, dan is die
     override weg: weghalen en herstellen, want de import laadt dezelfde regels al. -->

@AGENTS.md
