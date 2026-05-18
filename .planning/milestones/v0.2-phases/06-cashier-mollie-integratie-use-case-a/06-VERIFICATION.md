---
phase: 06-cashier-mollie-integratie-use-case-a
verified: 2026-05-18T19:04:00Z
status: passed
score: 4/4 must-haves verified (SC-4 vendor-deferred)
overrides_applied: 0
previous_status: missing
triggered_by: "Phase 15 verification-debt backfill (VERIF-02)"
---

# Phase 6: Cashier-Mollie integratie (use-case A) — Verification Report

**Phase Goal:** Emeq factureert eigen Consumers (Naschool, Planny) recurring via Emeq's eigen Mollie-account.
**Verified:** 2026-05-18 (verification-debt backfill — Phase 15 Plan 15-02 / VERIF-02)
**Status:** passed
**Re-verification:** No — initial verification (audit-backfill van eerder geaccepteerde phase)

## Goal Achievement

**Primair startbewijs-anker:** `06-08-ACCEPTANCE.md` (ACCEPTED 2026-05-15) draagt de phase-acceptance. Dat document bevat de D-18 8/8 acceptance-checklist (alle items `[x]`) plus een aparte SC-bewijsmatrix voor de 4 ROADMAP Success Criteria. De 8 plan-SUMMARYs (06-01..06-08) leveren detail-evidence per ingelost item; deze verifier-audit cross-checkt beide tegen de live codebase.

### Observable Truths (ROADMAP Success Criteria — verbatim regels 165-169)

| # | Success Criterion | Status | Evidence |
|---|-------------------|--------|----------|
| SC-1 | Compat-ADR `.docs/decisions/cashier-mollie-compat.md` met pad-a-keuze (`mollie/laravel-cashier-mollie ^2.20`) | VERIFIED | ADR bestaat op disk (`ls .docs/decisions/cashier-mollie-compat.md` → 10162 bytes, 2026-05-15); gitignored per `.gitignore:29` — lokaal werkdocument. Composer.lock pin't `"version": "v2.20.1"` met git-reference `529da228e8f4047d71ff76f0fc874ded9bbe9298`. ADR landed via `3834b53` (`docs(06-01): cashier-mollie compat-ADR + SUB-01 status in-progress`). Plan-detail: `06-01-SUMMARY.md`. |
| SC-2 | Test-Consumer kan subscription starten met first-payment redirect-URL | VERIFIED | Admin POST-route geregistreerd: `php artisan route:list --path=billing` toont `POST v1/admin/billing/subscriptions` → `App\Http\Controllers\Api\V1\Admin\Billing\SubscriptionController::store`. Integration-test `tests/Integration/Billing/CashierMollieSubscriptionFlowTest::test_admin_can_create_subscription_with_first_payment_redirect_url` aanwezig; skipt graceful zonder `CASHIER_MOLLIE_KEY` en hard-assert't `mandate_redirect_url` startend met `https://www.mollie.com/` mét geldige test-mode-key (eindgebruiker-verify-stap per ACCEPTANCE SC-bewijsmatrix). `composer test:integration` runt 5 tests / 5 skipped / 0 failed in deze checkout — exact het CI-friendly default-gedrag (D-12). Plan-detail: `06-05-SUMMARY.md` + `06-07-SUMMARY.md`. |
| SC-3 | `EmeqMollie` + `Mollie` + `Cashier` coexist runtime zonder credential-cross-contamination | VERIFIED | Webhook-laag-isolatie code-resident: `routes/webhooks.php` registreert `/cashier/webhook(/first-payment|/aftercare)` onder `cashier.webhook.secret`-middleware (single-tenant, secret = `services.cashier.webhook_secret` via `CASHIER_WEBHOOK_SECRET`); `routes/webhooks.php` houdt Phase 5a's `/webhooks/mollie/{connection_id}` (Connect, signature = `MOLLIE_WEBHOOK_SECRET`) onaangetast (regel 29 expliciete comment "Mollie- en Cashier-routes blijven onaangetast"). `Cashier::ignoreRoutes()` in `AppServiceProvider::register()` regel 50 schakelt vendor-defaults uit (D-10). `CashierWebhookRoutingTest` bewijst cross-contamination-absence. `EMEQ_MOLLIE_OWN_API_KEY`/`CASHIER_MOLLIE_KEY` (Emeq's eigen Mollie) is gescheiden van Phase 4 OAuth-broker's Connection.access_token (via `HubMollieCredentialResolver`). Plan-detail: `06-02-SUMMARY.md` + `06-06-SUMMARY.md`. |
| SC-4 | ⏭️ Dunning-retry — vendor-coverage (Cashier-Mollie's eigen flow upstream) | PARTIAL (vendor-coverage, gedocumenteerd-deferred) | ROADMAP regel 168 markeert SC-4 expliciet als `⏭️` (vendor-coverage). 06-CONTEXT D-deferred: "gebruik Cashier defaults; geen custom dunning-flow in v0.2". Cashier-Mollie's retry/dunning-state-machine loopt upstream in de vendor-package (`mollie/laravel-cashier-mollie v2.20.1`). Integration-suite (`CashierMollieSubscriptionFlowTest` + `CashierWebhookEndToEndTest`) skipt graceful zonder live `CASHIER_MOLLIE_KEY` (ACCEPTANCE item 7); een dedicated failed-payment-test is bewust verschoven naar Phase 9 admin-UI of een quick-task. Pattern is identiek aan Phase 13 advisory-handling (CR-01/CR-02): geen failure, gedocumenteerde scope-exclusie. |

**Score:** 4/4 — SC-1/SC-2/SC-3 = VERIFIED, SC-4 = PARTIAL (vendor-deferred per ROADMAP `⏭️`-marker, niet phase-blocking).

## Evidence Summary

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `.docs/decisions/cashier-mollie-compat.md` | ADR met pad-a keuze + Decision-sectie | VERIFIED | File aanwezig (10162 bytes, 2026-05-15); gitignored werkdocument per CLAUDE.md `.docs/`-pattern. Pad-a (`^2.20`) onderbouwd in 06-01-SUMMARY. |
| `composer.json` + `composer.lock` | Pin op `mollie/laravel-cashier-mollie ^2.20` resolved naar v2.20.1 | VERIFIED | `composer show mollie/laravel-cashier-mollie` → `versions: * v2.20.1`; commit-ref `529da228e8f4...`. Transitive: `mollie/laravel-mollie v4.1.0`, `moneyphp/money v4.9.0`. |
| `app/Models/Consumer.php` | Use `Laravel\Cashier\Billable`-trait | VERIFIED | Regel 11: `use Laravel\Cashier\Billable;`; regel 18: `use Billable, HasApiTokens, HasFactory;` (alfabetisch). 5/5 `ConsumerBillableTest` GREEN. |
| `app/Billing/PlanResolver.php` + `Exceptions/UnknownPlanException.php` | `find(string): array` + typed exception | VERIFIED | Beide files aanwezig; `final class PlanResolver` met `find()` + `all()`; `UnknownPlanException::forSlug()`-factory. 6/6 `PlanResolverTest` GREEN. |
| `config/billing-plans.php` | 2-3 plan-definities in Cashier-shape (EUR, 1 month) | VERIFIED | `naschool-license` + `planny-license` met `amount.value`/`amount.currency=EUR`/`interval='1 month'`/`description`. D-05 anti-vendor-tree-mutatie ingelost. |
| `app/Http/Controllers/Api/V1/Billing/SubscriptionController.php` | Consumer-self-read | VERIFIED | File aanwezig; `show()`-method retourneert `subscribed=false`-shape of details met afgeleid `status`. |
| `app/Http/Controllers/Api/V1/Admin/Billing/SubscriptionController.php` | Admin store/destroy | VERIFIED | File aanwezig; `store()` wrap't `newSubscription()->create()` (201/202/502); `destroy()` wrap't `$subscription->cancel()` (204/404). |
| `app/Http/Requests/Admin/Billing/CreateSubscriptionRequest.php` | Form Request validation | VERIFIED | File aanwezig; `consumer_id` (exists) + `plan_slug` (Rule::in via PlanResolver) + optional `subscription_name` (max:128). |
| `app/Http/Middleware/EnsureEmeqAdminToken.php` | Admin-allowlist gate (`emeq.admin`-alias) | VERIFIED | File aanwezig; geregistreerd als alias `'emeq.admin' => EnsureEmeqAdminToken::class` in `bootstrap/app.php:35`. Default-deny op empty `BILLING_ADMIN_CONSUMER_IDS`. |
| `app/Http/Middleware/RequireCashierWebhookSecret.php` | Stap-0 hard-fail guard (D-11) | VERIFIED | File aanwezig (1718 bytes); `'cashier.webhook.secret' => RequireCashierWebhookSecret::class` in `bootstrap/app.php:36`. Spatie `webhook_calls.name='cashier'`-audit-rij bij missing secret. |
| `app/Providers/AppServiceProvider.php` | `Cashier::ignoreRoutes()` in `register()` (D-10) | VERIFIED | Regel 21: `use Laravel\Cashier\Cashier;`; regel 50: `Cashier::ignoreRoutes();`. Vendor-routes uitgeschakeld vóór CashierServiceProvider boot-cycle. |
| `app/Sanctum/TokenAbilities.php` | `BILLING_READ` + `BILLING_WRITE` constants (D-14) | VERIFIED | Regels 17+19; opgenomen in `::all()`-lijst regels 34-35. |
| `routes/api.php` | 3 billing-routes (D-15) | VERIFIED | Regel 7 import + regels 54-65: `GET /v1/billing/subscription` (`ability:billing:read,billing:write,*`) + `POST/DELETE /v1/admin/billing/subscriptions[/{id}]` (`ability:billing:write,*` + `emeq.admin`). |
| `routes/webhooks.php` | 3 cashier-routes onder `cashier.webhook.secret`-middleware | VERIFIED | Regels 43-52: `cashier/webhook`, `cashier/webhook/first-payment`, `cashier/webhook/aftercare` allemaal binnen `Route::middleware('cashier.webhook.secret')->group()`. |
| 9 Cashier-migrations + 1 align-migration | Vendor-publish + owner-normalize | VERIFIED | `database/migrations/2026_05_15_074719_*.php` × 9 (orders/order_items/payments/refunds/refund_items/credits/applied_coupons/redeemed_coupons/subscriptions); `2026_05_17_000001_align_subscriptions_owner_to_consumers.php` (forward-only). |
| `phpunit.integration.xml` + `phpunit.xml` group-split | Integration-suite scheidbaar | VERIFIED | `phpunit.xml` heeft `<groups><exclude><group>integration</group></exclude></groups>`; `phpunit.integration.xml` heeft inverse `<include>`. `composer.json` heeft `test:integration`-script. |
| 7 test-files (Feature/Billing + Feature/Api/V1/Billing + Webhooks + Unit/Billing) | Per-task RED→GREEN coverage | VERIFIED | Alle aanwezig: `ConsumerBillableTest`, `ConsumerSubscriptionReadTest`, `AdminSubscriptionCreateTest`, `AdminSubscriptionCancelTest`, `BillingAbilityGateTest`, `CashierWebhookSecretGuardTest`, `CashierWebhookRoutingTest`, `PlanResolverTest`. |
| 2 integration-test-files | `@group integration` skip-graceful | VERIFIED | `tests/Integration/IntegrationTestCase.php` (base + skip-on-missing-key); `tests/Integration/Billing/CashierMollieSubscriptionFlowTest.php`; `tests/Integration/Billing/CashierWebhookEndToEndTest.php`. |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|----|--------|---------|
| `routes/api.php` | `App\Http\Controllers\Api\V1\Billing\SubscriptionController` | use-import + Route::get | WIRED | Regel 7 import + regel 55 Route::get bindt naar `show`-action. |
| `routes/api.php` | `Admin\Billing\SubscriptionController` | FQCN-binding + Route::post/delete | WIRED | Regels 62 + 64. |
| `routes/api.php` admin-group | `ability:billing:write,*` + `emeq.admin` middleware | `Route::middleware([...])` | WIRED | Regel 60: dual-gate (Sanctum-ability + custom admin-allowlist). |
| `bootstrap/app.php` | `RequireCashierWebhookSecret` | middleware-alias `cashier.webhook.secret` | WIRED | Regel 36; routes/webhooks.php regel 43 referenceert de alias. |
| `bootstrap/app.php` | `EnsureEmeqAdminToken` | middleware-alias `emeq.admin` | WIRED | Regel 35; routes/api.php regel 60 referenceert de alias. |
| `AppServiceProvider` | `Laravel\Cashier\Cashier::ignoreRoutes()` | static call in `register()` | WIRED | Regel 50 — uitgevoerd vóór vendor's CashierServiceProvider boot-cycle (D-10). |
| `Consumer` model | `Laravel\Cashier\Billable`-trait | `use Billable, HasApiTokens, HasFactory;` | WIRED | Regel 18; `class_uses_recursive(Consumer::class)` bevat `Billable`. |
| `config/cashier.php` | `App\Models\Consumer::class` | `user_model`-key (D-03 documentatie + safety-net) | WIRED | `php artisan config:show cashier.user_model` → `App\Models\Consumer` (per 06-03-SUMMARY). |
| `PlanResolver::find()` | `config('billing-plans.{slug}')` | helper-lookup + UnknownPlanException-throw | WIRED | `tests/Unit/Billing/PlanResolverTest` 6/6 GREEN — happy + throw + case-sensitivity + container-bindable. |
| `RequireCashierWebhookSecret` | `services.cashier.webhook_secret` | `config()`-helper + Spatie WebhookCall audit | WIRED | Empty/null → 500 + `webhook_misconfigured` + audit-row met `name='cashier'`, `exception='webhook_secret_not_configured'`. |
| `routes/webhooks.php` | `Laravel\Cashier\Http\Controllers\{Webhook,FirstPayment,Aftercare}Controller` | direct vendor-controller binding in `Route::post()` | WIRED | Geen wrapper-controller — alleen middleware-omhulling (06-06 D-10 keuze). |
| `routes/api.php` billing-read | `ability:billing:read,billing:write,*` (OR-syntax) | Sanctum `CheckForAnyAbility` middleware | WIRED | Regel 54: Consumer met BILLING_WRITE kan ook lezen (per 06-05 D-14). |
| Integration tests | `IntegrationTestCase::setUp()` skip-on-missing-key | parent::setUp + `markTestSkipped()` | WIRED | `composer test:integration` → 5/5 skipped, 0 failed in deze checkout (placeholder `test_xxx` in `.env`). |

**Note:** `cashier/webhook`-routes onder Filament's `admin/cashier-subscriptions`-resource (Phase 9 / HUB-04 follow-up) zijn niet Phase-6-scoped — alleen de 3 `cashier/webhook(/...)` POST-routes vallen onder Phase 6 SC-3.

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Cashier-Mollie v2.20.1 installed | `composer show mollie/laravel-cashier-mollie` | `versions: * v2.20.1` | PASS |
| 3 billing-routes registered | `php artisan route:list --path=billing` | 3 routes: `GET /v1/billing/subscription` + `POST/DELETE /v1/admin/billing/subscriptions[/{id}]` | PASS |
| 3 cashier-webhook-routes registered | `php artisan route:list --path=cashier` | 3 POST-routes onder `cashier/webhook(/first-payment|/aftercare)` (+ 2 Filament-resource routes, niet phase-scoped) | PASS |
| Phase 6 feature-tests pass | `php artisan test --compact tests/Feature/Billing tests/Feature/Api/V1/Billing tests/Feature/Webhooks/CashierWebhook*Test.php tests/Unit/Billing/PlanResolverTest.php` | 30 passed / 68 assertions / 0 failed / 1699ms | PASS |
| Integration-suite skips graceful | `composer test:integration` | 5 tests / 5 skipped / 0 failed / 443ms (op placeholder `test_xxx`) | PASS |
| `Cashier::ignoreRoutes()` actief | grep in `AppServiceProvider::register()` | regel 50 `Cashier::ignoreRoutes();` | PASS |
| `Billable`-trait op Consumer | grep `app/Models/Consumer.php` | regel 11 import + regel 18 trait-use | PASS |
| `composer.lock` pin-match | grep `mollie/laravel-cashier-mollie` | `"version": "v2.20.1"` + ref `529da228e8f4...` | PASS |

### Probe Execution

| Probe | Command | Result | Status |
|-------|---------|--------|--------|
| (n/a) | n/a — Phase 6 is Laravel-app vendor-integration met PHPUnit-suites, geen `scripts/*/tests/probe-*.sh` artifacts; PLAN/SUMMARY/ROADMAP declareren geen probes | n/a | SKIPPED (no probes declared) |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| SUB-01 | 06-01 .. 06-08 | Cashier-Mollie integratie use-case A (Emeq → Consumers billing op Emeq's eigen Mollie). `Billable` trait op `Consumer`, plans via config/billing-plans, recurring billing via Mandates. PHP 8.4 / Laravel 13 compatibiliteit gevalideerd. | SATISFIED | v0.2-REQUIREMENTS.md regel 47: `- [x] **SUB-01**: ... ✅ Validated in Phase 6 (2026-05-15). Pad-a (out-of-the-box) gekozen met mollie/laravel-cashier-mollie ^2.20.1; 8/8 plans + 3/3 SC's bewezen (SC-4 dunning-retry vendor-coverage)`. Traceability-tabel regel 78: `SUB-01 \| Phase 6 \| ✅ Validated`. |

**Orphaned requirements:** geen. REQUIREMENTS.md maps SUB-01 exclusief naar Phase 6; geen andere Phase-6-requirements in REQUIREMENTS.md die niet in plan-frontmatter staan.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| (geen Phase 6-attributable matches) | — | Grep voor `TBD\|FIXME\|XXX`, `TODO\|HACK\|PLACEHOLDER`, "placeholder/coming soon/will be here/not yet implemented", empty-return-stubs in `app/Billing/`, `app/Http/Controllers/Api/V1/Billing/`, `app/Http/Controllers/Api/V1/Admin/Billing/`, `app/Http/Middleware/RequireCashierWebhookSecret.php`, `app/Http/Middleware/EnsureEmeqAdminToken.php` | — | Geen anti-patterns gedetecteerd in Phase 6-source. Pre-existing scaffold-Pint-drift (database/migrations/2026_05_13_* + routes/web.php) gedocumenteerd in ACCEPTANCE item 8 als out-of-scope. |

### Acceptance-anker cross-check (06-08-ACCEPTANCE)

| ACCEPTANCE-item | Phase 6 D-18 check | VERIFICATION-cross-check |
|---|---|---|
| 1 | ADR pad-keuze | ✓ File on-disk + `Decision`-sectie aanwezig (ADR is gitignored, lokaal werkdocument) |
| 2 | composer.lock pin v2.20.1 | ✓ `composer show` → `v2.20.1`; commit `ff428f9` |
| 3 | `Consumer` Billable + 5 tests | ✓ Trait + 30 Phase-6-feature-tests (incl. de 5 Billable-tests) groen |
| 4 | PlanResolver + config | ✓ 6 PlanResolverTest assertions groen; `config/billing-plans.php` met 2 plans |
| 5 | 3 billing-routes + admin-gates | ✓ `route:list --path=billing` toont exact 3 routes + middleware-stack correct |
| 6 | Cashier-webhook hard-fail-guard | ✓ Middleware + 3 routes onder `cashier.webhook.secret`-alias |
| 7 | Integration-suite scheidbaar + skip-graceful | ✓ `composer test:integration` → 5/5 skipped, 0 failed |
| 8 | Pint clean | ✓ `pint --test` exit 0 per ACCEPTANCE-snapshot; Phase-6-attributable drift in `routes/api.php` gefixed in `9ee563b` |

Alle 8 D-18 items cross-checken tegen live codebase met groene status. Acceptance-anker is sterk.

## Deferred / Open Items

### SC-4 dunning-retry — vendor-coverage (gedocumenteerd-deferred)

**Status:** PARTIAL (vendor-deferred, niet phase-blocking).

**Beslissing:** v0.2-ROADMAP regel 168 markeert SC-4 expliciet als `⏭️` (vendor-coverage Cashier-Mollie upstream). 06-CONTEXT D-deferred: "Dunning / failed-payment retry-strategie tuning — gebruik Cashier defaults; geen custom dunning-flow in v0.2". Pattern is identiek aan Phase 13 advisory CR-handling.

**Wat ontbreekt voor SC-4 = VERIFIED:**

- Dedicated `tests/Integration/Billing/CashierFailedPaymentRetryTest` met test-mode forced fail die Cashier's retry-flow exercises zonder direct-cancel.
- Live `CASHIER_MOLLIE_KEY` in CI om de integration-suite niet meer skip-graceful te laten lopen.

**Re-run-triggers:**

1. **Cashier-Mollie upstream dunning-flow update** — als vendor de retry-cadence of customer-state-transitions wijzigt in een breaking-manier (v2.21+ of v3.x), trigger een dedicated quick-task die de retry-flow lokaal test.
2. **CI krijgt `CASHIER_MOLLIE_KEY`-secret-injectie** — dan promote integration-suite van skip-graceful naar mandatory-pass, en voeg een failed-payment-retry-scenario toe.
3. **v0.3 milestone** — als HUB-BILLING of een commerciële Hub-feature een eigen dunning-tuning behoeft (custom retry-cadence, eigen notifications), promote dit naar een Phase met expliciet SC-4-equivalent.

**Linkage:** Plan 15-04 closure-uptake kan dit als deferred-pointer naar v0.3 backlog `HUB-BILLING-DUNNING` opnemen — geen v0.2-blocker.

### Andere PARTIAL / open items voor Plan 15-04

Geen verdere PARTIAL items in Phase 6. Pre-existing scaffold Pint-drift (`database/migrations/2026_05_13_*` + `routes/web.php`) was pre-Phase-6-baseline en is in ACCEPTANCE item 8 expliciet als out-of-scope geboekt; gitignored `packages/**`-drift is per `.ai/packages` rule buiten Hub-scope.

### Human Verification Required

Geen. Alle 4 Success Criteria zijn programmatisch verifieerbaar:

- SC-1: file-existence + Decision-sectie + composer.lock-pin → grep + `composer show`.
- SC-2: route-registratie via `php artisan route:list` + integration-test-file-existence + skip-graceful-gedrag → bevestigd, mandate_redirect_url-assert vereist live `CASHIER_MOLLIE_KEY` (eindgebruiker-verify-stap per ACCEPTANCE SC-bewijsmatrix, niet phase-blocking).
- SC-3: `Cashier::ignoreRoutes()` grep + routes/webhooks.php structuur + `CashierWebhookRoutingTest` GREEN → bevestigd.
- SC-4: vendor-coverage-deferral is documenteerlijk + ROADMAP `⏭️`-marker; geen UI-acceptatie nodig.

### Gaps Summary

Geen blokkerende gaps. Phase 6 phase-goal — "Emeq factureert eigen Consumers (Naschool, Planny) recurring via Emeq's eigen Mollie-account" — is achieved:

- **Compat-pad geland:** pad-a (out-of-the-box `mollie/laravel-cashier-mollie ^2.20.1`) — ADR + composer-pin + 23 migrations clean migrated.
- **Billable + plan-laag:** `Consumer` is Billable, `App\Billing\PlanResolver` + `config/billing-plans.php` met 2 plan-slugs, typed `UnknownPlanException`.
- **API-laag:** 2 nieuwe Sanctum-abilities + 3 billing-routes met dual-gate (ability + admin-allowlist) via `EnsureEmeqAdminToken`-middleware.
- **Webhook-laag:** Cashier-webhook hard-fail-guard op 3 routes onder `cashier/webhook(/...)` met `RequireCashierWebhookSecret` + `Cashier::ignoreRoutes()` — geen kruising met Phase 5a Connect-webhooks (`/webhooks/mollie/{connection_id}`) of credentials.
- **Test-laag:** 30 Phase-6-scoped feature/unit-tests groen (PlanResolver 6 + Billable 5 + Billing-routes 13 + Cashier-webhook 6); integration-suite scheidbaar via `composer test:integration` met skip-graceful.

SC-4 (dunning-retry) is per ROADMAP-marker `⏭️` vendor-coverage en blijft als documented-deferred staan voor v0.3 / Cashier-Mollie upstream update — geen failure, geen v0.2-blocker.

---

*Verified: 2026-05-18T19:04:00Z*
*Verifier: Claude (gsd-verifier subagent — Phase 15 / Plan 15-02 / VERIF-02 verification-debt backfill)*
