# Phase 6 + Phase 7 — Session Hand-over

**Sessie gestopt:** 2026-05-15 (mid-session, na plan 06-07)
**Branch:** `feat/v02-mollie-subscriptions` (lokaal, niet gepusht)
**Reden:** Plan 06-08 is human-verify checkpoint (`autonomous: false`) — natuurlijk pauze-moment. Phase 7 vergt eigen sessie.

## Wat staat er

**Phase 6 — Cashier-Mollie (use-case A):** 7/8 plans done

| Plan | Status | What it landed |
|------|--------|----------------|
| 06-01 | ✓ Done | ADR — pad (a) Cashier-Mollie ^2.20 out-of-box (PHP 8.4 + Laravel 13 supported). REQUIREMENTS.md SUB-01 = In Progress |
| 06-02 | ✓ Done | `composer require mollie/laravel-cashier-mollie ^2.20.1`, 9 cashier-migrations, 3 configs, env-vars. 4 nieuwe artisan cmds. 207→207 tests |
| 06-03 | ✓ Done | `Billable` trait op `App\Models\Consumer` + factory state + 5 RED→GREEN tests. 207→212 |
| 06-04 | ✓ Done | `App\Billing\PlanResolver` + `config/billing-plans.php` + `UnknownPlanException` + 6 RED→GREEN tests. 212→218 |
| 06-05 | ✓ Done | Sanctum `billing:read` + `billing:write` abilities, `EnsureEmeqAdminToken` middleware, 3 routes (`GET /v1/billing/subscription` + `POST/DELETE /v1/admin/billing/subscriptions`), Form-Request, 13 RED→GREEN tests. 218→231 |
| 06-06 | ✓ Done | `RequireCashierWebhookSecret` middleware (stap-0 hard-fail guard pattern from Phase 5a D-08 stap 1), `Cashier::ignoreRoutes()` in AppServiceProvider, custom `/cashier/webhook` route binding, 6 RED→GREEN tests. 231→237 |
| 06-07 | ✓ Done | `phpunit.integration.xml` + `@group integration` separation + 4 integration tests (skip cleanly zonder `CASHIER_MOLLIE_KEY`). Default suite blijft 237 |
| 06-08 | ⏸ Pending | **BLOCKING acceptance gate (autonomous: false)** — 3 SC's bewijzen + 0 regressies + Pint clean + tracking-updates |

**Test-suite:** 237 passed / 765 assertions / 0 failed / 1 incomplete (Phase 4 placeholder, unrelated). Pint clean. Geen regressies op Phase 5a.

**Phase 7 — Account-level subscriptions (use-case B):** niet gestart. 0/N plans.

## Branch-state

```
* feat/v02-mollie-subscriptions  (fec57b1)        ← all session work
  chore/v02-roadmap-split-and-scramble (fcf64b7)  ← rewound to pre-session state
  master                        (84455c7)
```

Working tree heeft 3 pre-existing items van session-start (CLAUDE.md M, .planning/phases/05b-snelstart-pass-through-api/05b-REVIEW.md ??, api.json ??) — niet door deze sessie geraakt.

**Niet gepusht.** User-policy: nooit pushen zonder approval.

## Wat MOET nog gebeuren

### Direct opvolgen (15-30 min, kan inline)

**Plan 06-08 — BLOCKING phase-acceptance.** Autonomous=false omdat het 3 SC's tegen de echte codebase verifieert + ROADMAP/REQUIREMENTS/STATE finaliseert. Bevat 3 tasks (per planner-output): auto-grep-gates, auto-test-run, en een `checkpoint:human-verify` voor de eindgebruiker.

**Aanroep:**
```
/gsd-execute-phase 6
```

Wave 7 zal alleen 06-08 spawnen (06-01..06-07 hebben SUMMARY.md = skipped). Executor runt de twee auto-tasks, dan een checkpoint. User bevestigt "approved" → orchestrator markeert Phase 6 complete via `phase.complete`.

**Optioneel (advisory):**
```
/gsd-code-review 6           # full-phase code review (verwacht 5-10 nits, geen criticals)
/gsd-verify-phase 6          # goal-backward verification (catch hidden gaps)
```

### Phase 7 — verse sessie (4-6 uur wallclock)

Phase 7 is qua scope vergelijkbaar met Phase 6 (multi-tenant subscription-laag boven Mollie's Subscriptions+Mandates API via Connect — eigen `AccountSubscription` model + service-laag i.p.v. Cashier). Niet doable in restant van deze sessie zonder context-rot.

**Recommended flow (start in `/clear`-verse sessie op deze branch):**

```bash
# 1. Switch terug naar de branch
git switch feat/v02-mollie-subscriptions

# 2. Phase 7 boot-up
/gsd-discuss-phase 7         # captures CONTEXT.md (use --auto if no questions needed)
/gsd-plan-phase 7            # 5-7 plans verwacht
/gsd-execute-phase 7         # wave-based execution
/gsd-verify-phase 7
```

**Phase 7 key design-decisions die discuss-phase moet locken:**
- `AccountSubscription` model (NIET Cashier's `Subscription` — die is single-tenant) op `account_id + connection_id + mollie_subscription_id`
- Service-laag in `app/Subscriptions/` (parallel namespace aan `app/Billing/` voor v0.2 separatie van concerns)
- Webhook-routing: Mollie Connect webhooks komen al binnen op `/webhooks/mollie/{connection_id}` (Phase 5a). Phase 7 voegt een handler toe die `subscription.*` events naar de `AccountSubscription` state-machine routeert (status transitions: active/paused/cancelled).
- Edge cases die plan moet dekken: revoked mandate → subscription paused, failed retry, customer-deleted op Mollie's kant.
- Geen Cashier nodig — gebruik `Emeq\MollieApi\Facades\Mollie` direct via per-Account Connection-resolutie (Phase 5a's `AbstractMolliePassThroughController` pattern).

## Bekende issues / follow-ups

### Tracking-drift (klein)

- ROADMAP.md Phase 6 progress-tabel: aanwezig op feat-branch. `phase.complete` voor Phase 6 wacht op plan 06-08.
- REQUIREMENTS.md SUB-01: status = "In Progress (06-01 done — compat-check landed)". Update naar "Complete" gebeurt automatisch via plan 06-08 OR handmatig na verify-phase.

### Worktree-bootstrap-pattern (recurring)

5 van de 6 executor-spawns hadden dezelfde Rule-3 deviation:
- Worktree wordt vers gemaakt door Claude Code's `isolation="worktree"` — heeft géén `.env` en géén `vendor/`.
- Executor moet `cp ../../.env .env` + `ln -sf ../../vendor vendor` (of `composer install`) doen vóór tests kunnen draaien.
- Soms slip-Write-naar-main-repo, dan revert + redo met worktree-absolute pad.

**Voorgesteld voor backlog:** een `.claude/hooks/worktree-bootstrap.sh` die automatisch `.env` cp't en `vendor` symlinkt bij nieuwe worktree-creatie. Niet in scope van deze sessie maar kost de executor steeds ~5-10 min per plan.

### Composer autoload cache

Na elke worktree-merge moet je `composer dump-autoload` in de main repo runnen, anders pakt PHP de worktree-tmp-paths uit `vendor/composer/autoload_classmap.php`. De wave-merge-scripts in deze sessie deden dat handmatig. Geautomatiseerd in `.claude/hooks/post-merge.sh` zou prettig zijn.

### Cashier vendor `subscription_items` discrepancy

Plan 06-02 was geschreven met de aanname dat Cashier-Mollie 10 migrations publishes inclusief `subscription_items`. Werkelijke v2.20.1 heeft 9 migrations zonder `subscription_items` (uses `orders`+`order_items`). Executor signaleerde en aligned met upstream. Geen action item; SUMMARY-mention.

### Cashier `MollieMandateId` etc. status

Cashier v2.x heeft enkele schema-velden NIET die plan 06-03 verwachtte: `status`, `mollie_subscription_id`, `mollie_mandate_id` op de `subscriptions` table. Executor afgevangen — status wordt nu derived via Cashier's `active()`/`cancelled()`/`onTrial()`/`onGracePeriod()`/`ended()` accessors. Geen blocker.

### .docs/ sync pending

Hooks signaleerden documentation-drift na elke plan (config/services.php, routes/webhooks.php, namespace-additions). Niet in plan-scope opgepakt. Recommend: `/docs-sync` in verse sessie vóór Phase 7 start.

## Lijst van bestaande artefacten (voor toekomstige sessies)

**Phase 6 directory:**
- `06-CONTEXT.md` — 18 LOCKED D-decisions (compat-strategie, Billable target, plan storage, etc.)
- `06-01..06-07-PLAN.md` + `06-01..06-07-SUMMARY.md` — alle plans + uitvoer-rapporten
- `06-08-PLAN.md` — pending acceptance gate
- `06-DEFERRED-PLANS.md` — resolved/index (alle 7 plans nu geland)
- `.docs/decisions/cashier-mollie-compat.md` — ADR (gitignored, op disk)

**Belangrijke source-files toegevoegd in Phase 6:**
- `app/Billing/PlanResolver.php` + `app/Billing/Exceptions/UnknownPlanException.php`
- `app/Http/Controllers/Api/V1/Billing/SubscriptionController.php`
- `app/Http/Controllers/Api/V1/Admin/Billing/SubscriptionController.php`
- `app/Http/Requests/Admin/Billing/CreateSubscriptionRequest.php`
- `app/Http/Middleware/EnsureEmeqAdminToken.php`
- `app/Http/Middleware/RequireCashierWebhookSecret.php`
- `config/billing-plans.php` + `config/billing.php` + `config/cashier.php` + `config/cashier_plans.php` + `config/cashier_coupons.php`
- `routes/api.php` (modified) + `routes/webhooks.php` (modified)
- `bootstrap/app.php` (modified — middleware aliases)
- `app/Sanctum/TokenAbilities.php` (modified — BILLING_READ + BILLING_WRITE)
- `app/Models/Consumer.php` (modified — Billable trait)
- `app/Providers/AppServiceProvider.php` (modified — Cashier::ignoreRoutes())
- 9 Cashier-migrations + 1 align-migration
- `phpunit.integration.xml` + `tests/Integration/*` (4 integration tests)
- 7 new test classes in `tests/Feature/Billing/`, `tests/Unit/Billing/`, `tests/Feature/Webhooks/`

## Run-state commands voor verse sessie

```bash
# Verify clean state
git switch feat/v02-mollie-subscriptions
git status                                       # should be the 3 pre-existing items
composer install                                 # sync vendor (Cashier-Mollie + deps)
composer dump-autoload                           # refresh autoload after worktree merges
php artisan migrate                              # apply any pending migrations
php artisan test --compact                       # expect: 237 passed / 0 failed

# Phase 6 finalization
/gsd-execute-phase 6                             # runs 06-08 only (others skipped via SUMMARY.md)

# Phase 7 boot
/gsd-discuss-phase 7
/gsd-plan-phase 7
/gsd-execute-phase 7
/gsd-verify-phase 7
```

## Wat ik voorstel

Run eerst `/gsd-execute-phase 6` om 06-08 te executen — dat sluit Phase 6 cleanly af met de 3 SC-checks en zet REQUIREMENTS.md SUB-01 op Complete. Pas dan Phase 7 oppakken. Phase 7 in een verse sessie (na `/clear`) is essentieel: anders dragen we 3.5 uur context-vermoeidheid de volgende fase in, en de subscription-state-machine in Phase 7 verdient verse aandacht (edge cases: revoked mandate → paused, failed retry, customer-deleted bij Mollie).
