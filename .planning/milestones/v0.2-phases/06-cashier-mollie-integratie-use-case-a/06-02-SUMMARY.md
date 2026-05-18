---
phase: 06-cashier-mollie-integratie-use-case-a
plan: 02
subsystem: cashier-mollie / subscriptions
tags: [cashier-mollie, composer, migrations, install, env-vars, services-config, sub-01]

# Dependency graph
requires:
  - phase: 06-cashier-mollie-integratie-use-case-a
    plan: 01
    provides: "ADR pad-(a) — `mollie/laravel-cashier-mollie ^2.20` officieel compat met PHP 8.4 / Laravel 13"
  - phase: 05a-mollie-connect-pass-through-api
    provides: "MollieWebhookController stap-0 hard-fail guard-pattern (D-08 stap 1) dat plan 06-06 hergebruikt"
  - phase: 02-mollie-sdk-build
    provides: "EmeqMollie facade-alias (SC-3) — bewijst dat Mollie + EmeqMollie kunnen coexisteren"
provides:
  - "Cashier-Mollie v2.20.1 installed + autodiscovered (Laravel\\Cashier\\CashierServiceProvider)"
  - "9 Cashier-tabellen op disk + smoke-migrate groen op SQLite :memory: (alle 23 migrations DONE)"
  - "config/cashier.php + config/cashier_plans.php + config/cashier_coupons.php gepubliceerd"
  - ".env.example krijgt CASHIER_MOLLIE_KEY + MOLLIE_KEY + CASHIER_WEBHOOK_SECRET met NL-comment"
  - "config/services.php heeft cashier.webhook_secret-binding voor plan 06-06's stap-0 guard"
  - "4 nieuwe Cashier-artisan-commands beschikbaar: cashier:install, cashier:run, cashier:sync-plans, cashier:update"
  - "3 nieuwe Cashier-webhook-routes: POST webhooks/mollie, POST webhooks/mollie/aftercare, POST webhooks/mollie/first-payment"
affects:
  - 06-03 (Billable trait op Consumer — leest config/cashier.php)
  - 06-04 (PlanResolver — leest config/cashier_plans.php als skeleton; eigen config/billing-plans.php is de business-laag)
  - 06-05 (billing API routes — bouwt op cashier-tabellen)
  - 06-06 (Cashier-webhook hard-fail guard — wrapt POST webhooks/mollie met services.cashier.webhook_secret check)
  - 06-07 (integration tests — gebruikt 9 tabellen + CASHIER_MOLLIE_KEY env)

# Tech tracking
tech-stack:
  added:
    - "mollie/laravel-cashier-mollie v2.20.1"
    - "mollie/laravel-mollie v4.1.0 (transitive)"
    - "moneyphp/money v4.9.0 (transitive — Cashier-Mollie value-object)"
    - "dompdf/dompdf v3.1.5 (transitive — Cashier invoice-PDF, niet gebruikt in v0.2)"
  patterns:
    - "Vendor-publish met --tag=cashier-migrations + --tag=cashier-configs"
    - "Services.cashier-config-block apart van services.mollie (om Connect vs eigen-account-credentials te scheiden)"
    - ".env.example krijgt twee env-aliases (CASHIER_MOLLIE_KEY + MOLLIE_KEY) met NL-comment dat ze identiek zijn"

key-files:
  created:
    - "config/cashier.php"
    - "config/cashier_plans.php"
    - "config/cashier_coupons.php"
    - "database/migrations/2026_05_15_074719_create_applied_coupons_table.php"
    - "database/migrations/2026_05_15_074719_create_credits_table.php"
    - "database/migrations/2026_05_15_074719_create_order_items_table.php"
    - "database/migrations/2026_05_15_074719_create_orders_table.php"
    - "database/migrations/2026_05_15_074719_create_payments_table.php"
    - "database/migrations/2026_05_15_074719_create_redeemed_coupons_table.php"
    - "database/migrations/2026_05_15_074719_create_refund_items_table.php"
    - "database/migrations/2026_05_15_074719_create_refunds_table.php"
    - "database/migrations/2026_05_15_074719_create_subscriptions_table.php"
    - ".planning/phases/06-cashier-mollie-integratie-use-case-a/deferred-items.md"
  modified:
    - "composer.json"
    - "composer.lock"
    - ".env.example"
    - "config/services.php"

key-decisions:
  - "Cashier-Mollie v2.20.1 resolved exact (Packagist `^2.20` → `v2.20.1`, commit `529da228e8f4`, 2026-04-23). Geen `-W` upgrade-flag nodig."
  - "9 Cashier-migrations gepubliceerd, NIET 10 — `subscription_items` bestaat niet in v2.20.1 (Cashier-Mollie gebruikt `orders` + `order_items` voor line-items; geen aparte subscription-items-tabel). Plan-acceptance-criteria was hierin onjuist; we aligneren op upstream-werkelijkheid."
  - "CASHIER_MOLLIE_KEY = MOLLIE_KEY (identieke waarde) — `laravel-mollie` ^4.0 leest `MOLLIE_KEY` env, Cashier-Mollie's docs verwijzen naar `CASHIER_MOLLIE_KEY`. We exposen beide aliases voor documentatie-helderheid."
  - "Cashier registreert 3 nieuwe routes onder `webhooks/mollie*` — geen botsing met Phase 5a's `webhooks/mollie/{connection_id}` omdat connection_id een numeric regex-pattern is dat `first-payment` / `aftercare` / exact-match niet matcht."
  - "Facade-isolation behouden: `Mollie` (uit laravel-mollie) + `EmeqMollie` (uit emeq/mollie-api) coexisteren — runtime-bewezen met `class_exists`-check."
  - "`config/mollie.php` (onze Phase 5a versie) NIET overschreven door Cashier-publish — bevat nog steeds `UuidV7IdempotencyKeyGenerator` + `EmeqMollie`-facade-alias."

patterns-established:
  - "Vendor-publish met `--tag=cashier-migrations`: alle 9 migrations krijgen dezelfde timestamp (`2026_05_15_074719_*`) — Laravel publiceert ze in één publish-call met identieke tijdstempel. Acceptabel: migrate-order is deterministisch door alfabetische volgorde binnen dezelfde timestamp."
  - "Twee-laagse webhook-secret-strategie: `services.mollie.webhook_secret` (Phase 5a Connect-platform) + `services.cashier.webhook_secret` (Phase 6 eigen-account) blijven gescheiden in config-tree."

requirements-completed: [SUB-01]  # In Progress — install-laag landed; trait + routes komen in 06-03+

# Metrics
duration: 18min
completed: 2026-05-15
---

# Phase 6 Plan 02: Cashier-Mollie install + scaffolding Summary

**`mollie/laravel-cashier-mollie v2.20.1` geïnstalleerd, 9 subscriptions/orders/payments-tabellen gepubliceerd, 3 configs gepubliceerd, en `services.cashier.webhook_secret`-binding voor plan 06-06's stap-0 hard-fail guard geland.**

## Performance

- **Duration:** ~18 min
- **Started:** 2026-05-15 (vóór composer-require)
- **Completed:** 2026-05-15 (na test-suite groen)
- **Tasks:** 3 (composer + publish + migrate-smoke, env-vars + services.cashier, route + suite-verify)
- **Files modified:** 14 (2 composer + 3 configs + 9 migrations) + 2 (.env.example + services.php) + 1 (deferred-items.md)

## Accomplishments

- Cashier-Mollie v2.20.1 (exact tag `529da228e8f4`, released 2026-04-23) draait via auto-discovered `Laravel\Cashier\CashierServiceProvider`. Composer-audit: 0 nieuwe security-advisories.
- 9 Cashier-tabellen gepubliceerd én smoke-gemigreerd op SQLite `:memory:` — alle 23 migrations DONE (14 bestaand + 9 nieuw).
- 3 config-files gepubliceerd: `config/cashier.php` (webhook-paths + defaults), `config/cashier_plans.php` (skeleton — vulling komt in 06-04 via `billing-plans.php`), `config/cashier_coupons.php` (out-of-scope v0.2).
- `.env.example` krijgt Emeq's-eigen-Mollie-block met NL-comment die `Connect` (Phase 4/5a) vs `eigen account` (Phase 6) expliciet onderscheidt — `CASHIER_MOLLIE_KEY=test_xxx`, `MOLLIE_KEY=test_xxx` (alias), `CASHIER_WEBHOOK_SECRET=` (leeg, hard-fail-by-design).
- `config/services.php` heeft `cashier.webhook_secret`-binding apart van `mollie.webhook_secret` — plan 06-06 wrap't Cashier's `POST webhooks/mollie` met stap-0 guard die deze key leest.
- **Géén regressie**: bestaande test-suite (207/207) blijft groen na install + migrate + config-publish.

## Task Commits

1. **Task 1: Composer require + publish migrations + configs + smoke-migrate** — `a9cce61` (feat)
2. **Task 2: `.env.example` + `config/services.php` — Emeq-eigen-Mollie env-vars + cashier.webhook_secret binding** — `a212240` (feat)
3. **Task 3: Smoke-verify suite + cashier artisan + route-list** — *geen commit (verificatie-only; 207/207 tests groen, 4 cashier-commands geregistreerd, 3 routes toegevoegd zonder Phase 5a-collision)*

**Plan metadata:** (volgt na deze SUMMARY-write — committed in dezelfde commit als deferred-items.md)

## Files Created/Modified

**Composer:**
- `composer.json` — require: `mollie/laravel-cashier-mollie: ^2.20`
- `composer.lock` — pin't v2.20.1 + transitive: `mollie/laravel-mollie v4.1.0`, `moneyphp/money v4.9.0`, `dompdf/dompdf v3.1.5`

**Configs (3 published):**
- `config/cashier.php` — Cashier defaults: `webhook_url='webhooks/mollie'`, `aftercare_webhook_url`, `first_payment` defaults, `order_number_generator`, `update_payment_method`
- `config/cashier_plans.php` — Skeleton met lege `plans`-array (06-04 vult via `billing-plans.php` + `PlanResolver`)
- `config/cashier_coupons.php` — Skeleton met lege `coupons`-array (out-of-scope v0.2)

**Migrations (9 published, all timestamp `2026_05_15_074719_`):**
- `create_applied_coupons_table.php`
- `create_credits_table.php`
- `create_order_items_table.php`
- `create_orders_table.php`
- `create_payments_table.php`
- `create_redeemed_coupons_table.php`
- `create_refund_items_table.php`
- `create_refunds_table.php`
- `create_subscriptions_table.php`

**Env + services (modified):**
- `.env.example` (+13 regels) — `CASHIER_MOLLIE_KEY`, `MOLLIE_KEY`, `CASHIER_WEBHOOK_SECRET` met NL-comment-blok
- `config/services.php` (+5 regels) — `'cashier' => ['webhook_secret' => env('CASHIER_WEBHOOK_SECRET')]`-block

**Planning:**
- `.planning/phases/06-cashier-mollie-integratie-use-case-a/deferred-items.md` (created) — log docs-sync hook-trigger als deferred follow-up

## .env.example block (verbatim)

```env
# Cashier-Mollie — Emeq's eigen Mollie test-account (use-case A, Phase 6)
# Dit is NIET een Connect-token (= Phase 4/5a/7) en NIET een Consumer-credential.
# Dit is de test-API-key uit Emeq's eigen Mollie-dashboard waar Naschool/Planny
# op factureren. Productiekey komt pas bij milestone-go-live, niet in v0.2.
# CASHIER_MOLLIE_KEY en MOLLIE_KEY moeten identiek zijn — laravel-mollie ^4.0
# leest `MOLLIE_KEY`, Cashier-Mollie's docs verwijzen naar `CASHIER_MOLLIE_KEY`.
CASHIER_MOLLIE_KEY=test_xxx
MOLLIE_KEY=test_xxx

# Webhook-secret voor Cashier-webhook hard-fail-guard (Phase 5a-pattern, plan 06-06).
# Genereer met `openssl rand -hex 32` en plak hier + in Mollie-dashboard's
# webhook-endpoint config. Leeg/null laat het Cashier-webhook hard-failen
# (500 + audit-row) — bewust, om open ingress te voorkomen.
CASHIER_WEBHOOK_SECRET=
```

## Route-snapshot — 3 nieuwe Cashier-routes (baseline voor plan 06-06)

| Method | Path                              | Name                            | Controller                                              |
| ------ | --------------------------------- | ------------------------------- | ------------------------------------------------------- |
| POST   | webhooks/mollie                   | webhooks.mollie.default         | `Laravel\Cashier\Http\Controllers\WebhookController`    |
| POST   | webhooks/mollie/aftercare         | webhooks.mollie.aftercare       | `Laravel\Cashier\Http\Controllers\AftercareWebhookController` |
| POST   | webhooks/mollie/first-payment     | webhooks.mollie.first_payment   | `Laravel\Cashier\Http\Controllers\FirstPaymentWebhookController` |

Phase 5a's `POST webhooks/mollie/{connection_id}` (numeric regex-pattern) blijft staan zonder collision. Plan 06-06 wrap't deze 3 routes met de stap-0 hard-fail guard (route-override via `bootstrap/app.php` of mid-stack-middleware).

## Test-suite baseline

- Pre-install: 207 passed (Phase 5a + 06-01 baseline)
- Post-install: **207 passed, 697 assertions, 3.9s** (`php artisan test --compact` — zelfde, geen regressie)
- 1 incomplete test (zelfde marker als pre-install; geen Cashier-relatie)

## Cashier-artisan-commands (4 nieuw)

```
cashier:install     Install Cashier Mollie
cashier:run         Process due order items
cashier:sync-plans  Update scheduled order items to reflect their current subscription plan's values
cashier:update      Update Cashier Mollie to v2
```

## Decisions Made

- **`subscription_items` bestaat NIET in Cashier-Mollie v2.20.1.** Plan-acceptance-criteria (≥10 migrations met `subscription_items`-tabel) week af van upstream-werkelijkheid. Aligneerd op upstream: 9 migrations is het correcte aantal (Cashier gebruikt `orders` + `order_items` voor subscription line-items, niet een aparte tabel).
- **Dual env-var-alias voor Mollie-key.** `CASHIER_MOLLIE_KEY` + `MOLLIE_KEY` documenteren beide names — `laravel-mollie` leest `MOLLIE_KEY`, Cashier-Mollie's docs verwijzen naar `CASHIER_MOLLIE_KEY`. NL-comment legt expliciet uit dat ze identiek moeten zijn.
- **`services.cashier.webhook_secret` apart van `services.mollie.webhook_secret`.** Plan 5a's `MOLLIE_WEBHOOK_SECRET` is voor Connect-platform-events; Phase 6's `CASHIER_WEBHOOK_SECRET` is voor Emeq's eigen-account Cashier-webhook. Aparte config-keys voorkomen verwarring + tonen dat de credentials gescheiden zijn.
- **`composer require` zonder `-W` flag.** Resolver loste de transitive dependency-tree clean op (geen conflicts met `mollie/mollie-api-php ^3.11` of `laravel/framework v13.9.0`). Geen ADR-pad-(b) escalatie nodig.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Plan acceptance-criteria sprak over `subscription_items`-tabel die in Cashier-Mollie v2.20.1 niet bestaat**
- **Found during:** Task 1, na `vendor:publish --tag=cashier-migrations` — uitkomst was 9 migrations, niet 10. Verificatie met `grep -r "subscription_items" vendor/mollie/laravel-cashier-mollie/` gaf 0 hits.
- **Issue:** Plan-frontmatter `files_modified` en plan-`interfaces` listten 10 migrations met `subscription_items_table`. Cashier-Mollie v2.x gebruikt `orders` + `order_items` voor subscription line-items, geen aparte `subscription_items`-tabel. Geen Cashier-Stripe-pattern.
- **Fix:** Acceptance-criteria aligned op upstream-werkelijkheid: 9 migrations is correct. SUMMARY documenteert de feitelijke published files.
- **Files modified:** Geen — alleen verwachte tabel-lijst aangepast.
- **Verification:** `composer show mollie/laravel-cashier-mollie` → v2.20.1; `ls vendor/mollie/laravel-cashier-mollie/database/migrations/*.stub | wc -l` = 10 (incl. `upgrade_to_cashier_v2.php.stub` die we NIET publishen op een fresh install).
- **Committed in:** `a9cce61` (Task 1 commit — bevat de feitelijke 9 migrations).

**2. [Rule 3 — Blocking] Dubbele publish bij eerste run gaf 9 extra migrations met andere timestamp**
- **Found during:** Task 1 — toen ik `vendor:publish` een tweede keer runde om de migration-count te onderzoeken, kreeg ik een tweede set 9 migrations met timestamp `074734` (1e set was `074719`). Beide sets aanwezig zou bij `migrate` een "table already exists" geven.
- **Issue:** Laravel's `vendor:publish` is idempotent op file-level (zelfde inhoud) maar gebruikt een fresh timestamp per publish-aanroep. Bij dubbele publish ontstaan duplicate migrations.
- **Fix:** `rm -f database/migrations/2026_05_15_074734_*.php` — verwijderde de duplicaten. Originele set (`074719`) bewaard. Smoke-migrate daarna groen.
- **Files modified:** 9 duplicate migrations verwijderd (nooit gecommit, alleen op disk).
- **Verification:** `ls database/migrations/ | grep "2026_05_15_07"` → 9 files (alleen `074719_*`).
- **Committed in:** `a9cce61` (alleen de gewenste set is gecommit).

**3. [Rule 3 — Blocking] Worktree-isolation slip: Edit-calls op `.env.example` + `config/services.php` raakten main repo i.p.v. worktree**
- **Found during:** Task 2 — na Edit-calls verifieerde grep dat de wijzigingen NIET in de worktree zaten. `git status` toonde geen dirty files in worktree.
- **Issue:** Eerste Edit-aanroepen gebruikten absolute pad `/Users/yusufkaracaburun/Sites/localhost/emeq-hub/.env.example` (= main repo) i.p.v. worktree-absolute pad `/Users/yusufkaracaburun/Sites/localhost/emeq-hub/.claude/worktrees/agent-a6d117ebe6b4cdd25/.env.example`. Main repo was op `feat/v02-mollie-subscriptions`-branch — wijzigingen zouden op verkeerde branch hebben geland. Identiek aan plan 06-01's deviation (zelfde lesson).
- **Fix:** Stray edits in main repo gereverteerd met `git -C /Users/yusufkaracaburun/Sites/localhost/emeq-hub checkout -- .env.example config/services.php` en de stray `deferred-items.md` verwijderd met `rm -f`. Daarna Edit-calls opnieuw gerund met expliciet worktree-absolute path. Worktree-isolation hersteld.
- **Files modified:** Geen extra files — alleen correctie van eerder verkeerd geplaatste edits.
- **Verification:** `git -C /Users/yusufkaracaburun/Sites/localhost/emeq-hub status --short` → alleen pre-existing dirty files (`CLAUDE.md`, `.planning/phases/05b-snelstart-pass-through-api/05b-REVIEW.md`, `api.json`). Main repo `.env.example` + `config/services.php` clean. Worktree wijzigingen correct gecommit als `a212240`.
- **Lesson:** Bij elke Edit/Write in worktree-context expliciet worktree-absolute pad gebruiken, ongeacht of `pwd` correct staat. Plan 06-01's SUMMARY documenteerde al deze lesson — herhaalt zich.
- **Committed in:** `a212240` (Task 2 commit — op de juiste branch).

---

**Total deviations:** 3 auto-fixed (1 Rule-1 bug in plan acceptance-criteria, 2 Rule-3 blocking).
**Impact on plan:** Alle 3 auto-fixes essentieel om Task 1 + Task 2 te kunnen voltooien. Geen scope-creep — alle wijzigingen blijven binnen plan-`files_modified`-whitelist. Plan-acceptance-criteria-mismatch (subscription_items) is een planning-bug, niet een implementation-issue; SUMMARY documenteert de upstream-werkelijkheid zodat plan 06-03+ niet op een non-existent tabel bouwt.

## Issues Encountered

- **`.env` ontbrak in worktree** bij eerste test-suite-run — gaf `MissingAppKeyException` op 5 OAuth-tests (false-positief, niet Cashier-gerelateerd). Opgelost door `cp /Users/yusufkaracaburun/Sites/localhost/emeq-hub/.env .env` (worktree-bootstrap). `.env` is gitignored, geen contamination. Daarna 207/207 groen.

## User Setup Required

**Zie plan-frontmatter `user_setup`-block:**

- **Emeq's eigen Mollie test-account** (NIET Connect — dat is Phase 4/5a/7). User moet:
  1. Naar Emeq's eigen Mollie test-dashboard gaan (`https://my.mollie.com`, switch naar test-mode).
  2. Developers → API keys → Test API key kopiëren (`test_…`-prefix).
  3. Plakken in `.env` als `CASHIER_MOLLIE_KEY=test_…` EN `MOLLIE_KEY=test_…` (identiek).
- **Webhook-signing-secret** voor `POST webhooks/mollie`:
  1. Run `openssl rand -hex 32` lokaal — kopieer output.
  2. Plak als `CASHIER_WEBHOOK_SECRET=…` in `.env`.
  3. In Mollie-dashboard's Developers → Webhooks: registreer `https://hub.emeq.test:8090/webhooks/mollie` als webhook-URL en plak dezelfde secret als signing-secret. (Mollie's reguliere webhooks zijn unsigned by default — de stap-0 guard die plan 06-06 implementeert is Emeq's eigen extra security-laag op de Cashier-webhook-route.)

Productiekey komt pas bij milestone-go-live (v0.3+), niet in v0.2.

## Next Phase Readiness

**Klaar voor plan 06-03 (Billable trait op `App\Models\Consumer`):**
- `config/cashier.php` bestaat — Cashier kan `cashier.user_model` lezen (mapping van `subscriptions.user_id` naar Consumer).
- `Laravel\Cashier\Billable`-trait beschikbaar in autoload.
- 9 tabellen op disk (na user `php artisan migrate` runt op pgsql dev-db).
- `services.cashier.webhook_secret` resolved naar `null` bij leeg → klaar voor plan 06-06's stap-0 guard.

**Blockers:** geen. Plan 06-03 t/m 06-08 zijn ontblokt.

## Deferred follow-ups (zie deferred-items.md)

- **`/docs-sync` skill-pass** — twee `PostToolUse:Edit`-hooks triggerden de skill (`config/services.php` provider-config + `.env.example` env-var toevoeging). Niet uitgevoerd in plan-scope (zelfde reasoning als plan 06-01: skill-pass kan `.docs/README.md`/`CLAUDE.md`/memory aanraken — buiten chirurgische `files_modified`-whitelist). Aanbeveling: user runt `/docs-sync` losse pass vóór `gsd-execute-phase 06-03+`.

---

*Phase: 06-cashier-mollie-integratie-use-case-a*
*Plan: 02*
*Completed: 2026-05-15*

## Self-Check: PASSED

- FOUND: `.planning/phases/06-cashier-mollie-integratie-use-case-a/06-02-SUMMARY.md` (this file)
- FOUND: `.planning/phases/06-cashier-mollie-integratie-use-case-a/deferred-items.md`
- FOUND: `config/cashier.php`, `config/cashier_plans.php`, `config/cashier_coupons.php`
- FOUND: 9 cashier migrations (`database/migrations/2026_05_15_074719_*.php`)
- FOUND: `CASHIER_MOLLIE_KEY=test_xxx` in `.env.example`
- FOUND: `'cashier' =>` block in `config/services.php`
- FOUND: commit `a9cce61` (Task 1 — composer + migrations + configs)
- FOUND: commit `a212240` (Task 2 — env + services.cashier)
- 207/207 tests passed (geen regressie)
- `composer audit` clean (geen nieuwe advisories)
- `class_exists("Mollie")` && `class_exists("EmeqMollie")` beide true (facade-isolation)
- `config/mollie.php` (Phase 5a) intact: `UuidV7IdempotencyKeyGenerator` + `EmeqMollie`-alias aanwezig
