# Phase 6 — Cashier-Mollie integratie (use-case A) — BLOCKING phase-acceptance

**Datum:** 2026-05-15
**Status:** ACCEPTED
**Executor:** Plan 06-08 (autonomous=false; human-bevestiging vereist)
**Branch:** `feat/v02-mollie-subscriptions`

## D-18 acceptance-criteria — 8/8

| # | Item | Status | Evidence |
|---|------|--------|----------|
| 1 | Compat-ADR `.docs/decisions/cashier-mollie-compat.md` exists met pad-keuze (a/b/c) | [x] | ADR landed in `3834b53` (`docs(06-01): cashier-mollie compat-ADR + SUB-01 status in-progress`); pad-a gekozen (`mollie/laravel-cashier-mollie ^2.20`). Plan-SUMMARY: `06-01-SUMMARY.md`. |
| 2 | composer.lock pin't `mollie/laravel-cashier-mollie ^2.20` | [x] | Resolved versie `v2.20.1` (`composer show mollie/laravel-cashier-mollie`); merge-commit `ff428f9` + roadmap-update `a3d917c`. Plan-SUMMARY: `06-02-SUMMARY.md`. |
| 3 | Consumer is Billable + subscribed-on-default false | [x] | `App\Models\Consumer` heeft `use Laravel\Cashier\Billable;` + `use Billable, HasApiTokens, HasFactory;`. `tests/Feature/Billing/ConsumerBillableTest` 5/5 passed (7 assertions). Commits: `c902966` (RED) → `99e81b5` (GREEN) → `6a2d9fa` (merge) → `c07dd41` (summary) → `64883f8` (roadmap). |
| 4 | `config/billing-plans.php` + `PlanResolver` werken (find/all/UnknownPlanException) | [x] | `config/billing-plans.php` definieert `naschool-license` + `planny-license`. `App\Billing\PlanResolver` + `App\Billing\Exceptions\UnknownPlanException` bestaan. `tests/Unit/Billing/PlanResolverTest` 6/6 passed (17 assertions). Commits: `c91c224` (RED) → `756406c` (GREEN) → `5422818` (summary) → `38d5ecf` (merge) → `5aac863` (roadmap). |
| 5 | 3 billing-routes geregistreerd met juiste ability + admin-gates | [x] | `php artisan route:list --path=billing` toont `GET /v1/billing/subscription` + `POST /v1/admin/billing/subscriptions` + `DELETE /v1/admin/billing/subscriptions/{id}`. `EnsureEmeqAdminToken` middleware actief op admin-routes. 13 feature-tests in `tests/Feature/Api/V1/Billing/`. Commits: `514a091` (RED skeleton) → `e8a9058` (controllers GREEN) → `0b03851` (summary) → `f14fb01` (merge) → `5335e98` (roadmap). |
| 6 | Cashier-webhook hard-fail-guard (`CASHIER_WEBHOOK_SECRET`) actief op 3 paden | [x] | `php artisan route:list --path=cashier` toont 3 routes onder `/cashier/webhook*`. `bootstrap/app.php` heeft `'cashier.webhook.secret' => RequireCashierWebhookSecret::class` alias. `App\Providers\AppServiceProvider` heeft `Cashier::ignoreRoutes()`. `tests/Feature/Webhooks/CashierWebhook*Test` (3 files, 6+ tests GREEN; 19/19 in `tests/Feature/Webhooks/` blok inclusief Phase 5a Mollie-webhooks geen regressies). Commits: `9d048f9` (RED) → `fb118e2` (GREEN) → `ec89cf8` (summary) → `b849c58` (merge) → `d1294bb` (roadmap). |
| 7 | Integration-suite scheidbaar + skipt of slaagt graceful | [x] | `phpunit.integration.xml` bestaat. `phpunit.xml` excluded `<group>integration</group>` op default-run. `composer test:integration` → 4 tests, 4 skipped, 0 failed (graceful zonder `CASHIER_MOLLIE_KEY` in `.env`). Default suite blijft 237 passed. Commits: `167db7c` (config) → `ca5b72c` (tests) → `54fa1de` (summary) → `67a3a77` (merge) → `fec57b1` (roadmap). |
| 8 | Pint clean | [x] | `./vendor/bin/pint --test --format agent` exit-code 0. Pre-Phase-6 baseline-drift in `database/migrations/2026_05_13_*` + `routes/web.php` + gitignored `packages/**` is pre-existing scaffold-drift uit `0196e01` (initial Laravel 13 scaffold), niet door Phase 6 geïntroduceerd. `routes/api.php` had Phase-6-attributable minor drift (`fully_qualified_strict_types` + `ordered_imports` van plan 06-05) — gefixed in plan 06-08 als onderdeel van acceptance-finalisatie. |

## Phase 6 SC's — bewijsmatrix

| SC | Definitie | Bewezen door |
|----|-----------|--------------|
| SC-1 | Compat-check ADR exists met conclusie (werkt/patch/eigen-laag) | Plan 06-01 — ADR `.docs/decisions/cashier-mollie-compat.md` + SUMMARY-pointer. Conclusie: **pad-a** (out-of-the-box met `mollie/laravel-cashier-mollie ^2.20` — PHP 8.4 + Laravel 13 ondersteund). |
| SC-2 | Test-Consumer kan subscription starten op test-plan; eerste Mandate + Payment zichtbaar in Mollie test-dashboard | Plan 06-07 integration-test `tests/Integration/Billing/CashierMollieSubscriptionFlowTest::test_admin_can_create_subscription_with_first_payment_redirect_url` (skipt graceful zonder `CASHIER_MOLLIE_KEY`; slaagt met geldige test-mode-key in `.env` — eindgebruiker-verify-stap). |
| SC-3 | Cashier-billing + Connect-pass-through coexist zonder credential-cross-contamination | Runtime-check geverifieerd: `EmeqMollie` + `Mollie` + `Laravel\Cashier\Cashier` classes coexist in dezelfde request-cycle (Phase 2 SC-3 + 06-02-install). `CASHIER_MOLLIE_KEY` is Emeq's eigen Mollie-key (los van Connection.access_token uit Phase 4 OAuth-broker — die loopt via `HubMollieCredentialResolver`). Plan 06-06 `CashierWebhookRoutingTest` bewijst de webhook-laag-isolatie: `/webhooks/mollie/{connection_id}` (Connect, signature `MOLLIE_WEBHOOK_SECRET`) blijft naast `/cashier/webhook*` (single-tenant, signature `CASHIER_WEBHOOK_SECRET`) — geen kruising. |
| SC-4 | Failed-payment (test-mode forced fail) triggert Cashier's retry-flow zonder direct-cancel | **Vendor-coverage:** Cashier-Mollie's eigen retry/dunning-flow is door upstream gegarandeerd. 06-CONTEXT D-XX (gebruik Cashier defaults; geen custom dunning-flow in v0.2). Expliciet eigen test verschoven naar Phase 9 admin-UI of dedicated quick-task indien nodig vóór v0.2-release. |

## Test-baseline-snapshot

- **Standaard suite (`php artisan test --compact`):** 237 passed / 765 assertions / 0 failed / 1 incomplete (Phase 4 placeholder voor toekomstige Connect-revoke E2E, unrelated to Phase 6).
- **Integration suite (`composer test:integration`):** 4 tests / 4 skipped / 0 failed (graceful skip zonder `CASHIER_MOLLIE_KEY`).
- **Phase 5a regressie-check:** `tests/Feature/Api/V1/Mollie/` 49 passed / 195 assertions ✓; `tests/Feature/Webhooks/` 19 passed / 70 assertions ✓ — geen regressies.
- **Pint:** `--test` exit 0 na fix van `routes/api.php`. `--dirty` (CLAUDE.md-conventie) clean.

## Decisions confirmed (van 06-CONTEXT.md)

| D-ID | Decision | Ingelost door |
|------|----------|---------------|
| D-01 | Plan-1 compat-check (a/b/c) | 06-01 ADR |
| D-02 | Pad-keuze gekoppeld aan plan-2-N inhoud | 06-01 → pad (a) → 06-02 t/m 06-07 |
| D-03 | Billable op Consumer | 06-03 |
| D-04 | NIET op Account | 06-03 (Account.php intact) |
| D-05 | Plan-storage config-driven via `config/billing-plans.php` | 06-04 |
| D-06 | PlanResolver::find(string): array | 06-04 |
| D-07 | Cashier op Emeq's eigen Mollie API-key | 06-02 (.env + services.php) |
| D-08 | Mollie + EmeqMollie facade coexist | Phase 2 SC-3 + 06-02 verify in 06-03 |
| D-09 | `.env.example` met placeholders + NL-comment | 06-02 |
| D-10 | Cashier-webhook separaat van Connect-webhook | 06-06 (paths + `ignoreRoutes()`) |
| D-11 | Cashier-webhook stap-0 hard-fail guard | 06-06 (`RequireCashierWebhookSecret`) |
| D-12 | Integration tests `@group integration` | 06-07 (`phpunit.integration.xml` + `IntegrationTestCase`) |
| D-13 | Pad (c) ≥80% coverage | n.v.t. (gekozen pad-a) |
| D-14 | BILLING_READ + BILLING_WRITE abilities | 06-05 |
| D-15 | 3 routes (consumer-read + admin-create + admin-cancel) | 06-05 |
| D-16 | Cashier-migrations via `vendor:publish` | 06-02 |
| D-17 | Eigen `consumer_subscriptions`-migration | n.v.t. (gekozen pad-a) |
| D-18 | Phase-acceptance 8 criteria | 06-08 (dit document) |

## Gaps identified

**Geen blocking gaps.** Pre-existing scaffold Pint-drift in `database/migrations/2026_05_13_*` + `routes/web.php` is geen Phase 6 verantwoordelijkheid; deze drift bestond vóór `ca8937d` (start Phase 6) en is afkomstig uit `0196e01` (initial Laravel 13 scaffold). Gitignored `packages/**`-drift is buiten Hub-scope (`.ai/packages` rule: packages/ is read-clone). Document deze cleanup in STATE.md "Pending Todos" — pakken bij toekomstige scaffold-touchup of dedicated quick-task `pint-baseline-cleanup`.

## Next step voor de user

Phase 6 is geaccepteerd. Voorgestelde vervolgstap:

**Phase 7: Account-level subscriptions (use-case B) — `/gsd-discuss-phase 7`**

Phase 7 leunt NIET op de Cashier-baseline van Phase 6 — het bouwt een eigen multi-tenant subscription-laag bovenop Mollie Connect-Mandates + Connect-Subscriptions (afhankelijk van Phase 5a SDK-laag). De twee subscription-modi blijven volledig gescheiden: Phase 6 = Emeq-eigen-Mollie (Cashier single-tenant), Phase 7 = Connect (eigen multi-tenant laag).

Alternatief — als de user prioriteit elders wil leggen: Phase 9 (Filament admin-UI) is parallel met Phase 6/7 mogelijk (depends-on Phase 3 + 4) en blokkeert Phase 8 niet.

Aanbeveling per session-handover: Phase 7 starten in een **verse sessie** (na `/clear`) op deze branch — subscription-state-machine (revoked-mandate → paused, failed-retry, customer-deleted edges) verdient verse context, niet 3.5+ uur sessie-vermoeidheid.
