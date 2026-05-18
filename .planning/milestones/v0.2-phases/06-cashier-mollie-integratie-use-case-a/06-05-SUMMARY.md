---
phase: 06-cashier-mollie-integratie-use-case-a
plan: 05
subsystem: cashier-mollie / billing-routes
tags: [cashier-mollie, sanctum, routes, controllers, billing, middleware, sub-01, tdd]

# Dependency graph
requires:
  - phase: 06-cashier-mollie-integratie-use-case-a
    plan: 03
    provides: "Consumer gebruikt Billable trait + withActiveSubscription factory-state"
  - phase: 06-cashier-mollie-integratie-use-case-a
    plan: 04
    provides: "App\\Billing\\PlanResolver::find()/all() + UnknownPlanException"
provides:
  - "App\\Sanctum\\TokenAbilities::BILLING_READ + BILLING_WRITE constants (D-14)"
  - "3 nieuwe API routes (D-15): GET /v1/billing/subscription, POST + DELETE /v1/admin/billing/subscriptions"
  - "EnsureEmeqAdminToken middleware (alias 'emeq.admin') — config-allowlist gatekeeper tot Phase 9"
  - "App\\Http\\Controllers\\Api\\V1\\Billing\\SubscriptionController::show"
  - "App\\Http\\Controllers\\Api\\V1\\Admin\\Billing\\SubscriptionController::store/destroy"
  - "App\\Http\\Requests\\Admin\\Billing\\CreateSubscriptionRequest (consumer_id + plan_slug validation)"
  - "config/billing.php — admin_allowlist + default_subscription_name"
affects:
  - 06-06 (webhook plan kan dezelfde Sanctum-ability-conventie volgen)
  - 06-07 (integration suite hit deze routes met echte Mollie test-mode key)
  - Phase 9 (Filament admin-resource vervangt EnsureEmeqAdminToken config-allowlist)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "TDD RED→GREEN per task (Task 1 RED faalt op routes 404 omdat controllers nog niet bestaan; Task 2 GREEN landt controllers)"
    - "Ability-OR-gating via `ability:billing:read,billing:write,*` middleware-syntax (CheckForAnyAbility) — Consumer met BILLING_WRITE kan ook lezen"
    - "Two-laagse admin-guard: ability:billing:write + config-allowlist via custom middleware (geen impliciete admin-flag op het model)"
    - "Cashier-Mollie status-derivation: subscriptions-tabel heeft GEEN status-kolom; SubscriptionController::show() leidt status af uit active()/cancelled()/onTrial()/onGracePeriod()-state-methodes"
    - "Form-Request validation met Rule::in op PlanResolver::all()-keys + defense-in-depth PlanResolver::find() in de controller"

key-files:
  created:
    - "app/Http/Middleware/EnsureEmeqAdminToken.php"
    - "app/Http/Controllers/Api/V1/Billing/SubscriptionController.php"
    - "app/Http/Controllers/Api/V1/Admin/Billing/SubscriptionController.php"
    - "app/Http/Requests/Admin/Billing/CreateSubscriptionRequest.php"
    - "config/billing.php"
    - "tests/Feature/Api/V1/Billing/BillingAbilityGateTest.php"
    - "tests/Feature/Api/V1/Billing/ConsumerSubscriptionReadTest.php"
    - "tests/Feature/Api/V1/Billing/AdminSubscriptionCreateTest.php"
    - "tests/Feature/Api/V1/Billing/AdminSubscriptionCancelTest.php"
  modified:
    - "app/Sanctum/TokenAbilities.php"
    - "bootstrap/app.php"
    - "routes/api.php"
    - ".env.example"

key-decisions:
  - "Subscription-status afgeleid uit Cashier state-methodes (active/cancelled/onTrial/onGracePeriod), niet uit een non-existent status-kolom — plan-tekst noemde \$subscription->status maar die kolom bestaat niet in 06-02's schema."
  - "Admin-create test 3 is soft-assertion (status in [201,202,502]) omdat Cashier's newSubscription()->create() Mollie API hit zonder echte test-mode key — happy-path hard-asserts landen in plan 06-07 integration-suite."
  - "Empty BILLING_ADMIN_CONSUMER_IDS = default-deny: middleware blokkeert alle admin-endpoints met 403 + error=not_admin. Bewust onveilig-by-omission om productie te beschermen."

# Metrics
duration: 25min
completed: 2026-05-15
requirements-completed: []  # SUB-01 blijft in-progress; webhook + integration tests volgen in 06-06/06-07
---

# Phase 6 Plan 05: Billing Routes + Sanctum Abilities + Admin Middleware Summary

**2 nieuwe Sanctum-abilities (D-14) + 3 billing-routes (D-15) + EnsureEmeqAdminToken-middleware + 2 controllers + Form-Request + 13/13 RED→GREEN feature-tests; full suite 218 → 231 zonder regressies.**

## Performance

- **Duration:** ~25 min (incl. ~10 min investigatie van een worktree-vendor-symlink-bug, zie Deviations)
- **Started:** 2026-05-15T08:13:28Z
- **Completed:** 2026-05-15T08:36:59Z
- **Tasks:** 2 (RED skeleton + GREEN controllers/Form-Request/tests)
- **Files created:** 9 (4 app-side + 1 config + 4 test-classes)
- **Files modified:** 4 (TokenAbilities + bootstrap + routes + .env.example)

## Accomplishments

- **D-14 ingelost:** `App\Sanctum\TokenAbilities` heeft `BILLING_READ = 'billing:read'` + `BILLING_WRITE = 'billing:write'`. `TokenAbilities::all()` retourneert nu 8 entries (was 6).
- **D-15 ingelost:** 3 nieuwe routes onder `/v1` geregistreerd:
  - `GET /v1/billing/subscription` — Consumer reads own subscription (`ability:billing:read,billing:write,*`).
  - `POST /v1/admin/billing/subscriptions` — Admin creates (`ability:billing:write,*` + `emeq.admin`).
  - `DELETE /v1/admin/billing/subscriptions/{id}` — Admin cancels (idem).
- **EnsureEmeqAdminToken middleware** geregistreerd als `emeq.admin`-alias in `bootstrap/app.php`. Default-deny op empty allowlist; 403 + `error=not_admin` payload bij niet-allowlisted Consumer-IDs.
- **Consumer SubscriptionController::show()** retourneert `subscribed=false`-shape voor Consumer zonder subscription, en een details-payload met afgeleid `status` (active/cancelled/trialing/grace/ended/inactive) + `plan` + `ends_at` + `trial_ends_at` voor subscribed Consumer.
- **Admin SubscriptionController::store/destroy()** wrappen Cashier's `newSubscription()->create()` resp. `$subscription->cancel()`. Store returnt 201 voor direct-Subscription, 202 voor first-payment-redirect-flow met `mandate_redirect_url`, 502 bij Cashier/Mollie exception. Destroy returnt 204 op cancel of 404 wanneer subscription niet bestaat.
- **CreateSubscriptionRequest** valideert `consumer_id` (exists:consumers,id) + `plan_slug` (Rule::in op `PlanResolver::all()`-keys) + optional `subscription_name` (max:128).
- **`config/billing.php`** exposeert `admin_allowlist` (intval/comma-parse van `BILLING_ADMIN_CONSUMER_IDS`) + `default_subscription_name` (default `'main'`).
- **`.env.example`** uitgebreid met `BILLING_ADMIN_CONSUMER_IDS=` + `BILLING_DEFAULT_SUBSCRIPTION_NAME=main` + Nederlandse comment over default-deny.
- **13 RED-eerst tests committed (`514a091`) → 13 GREEN-tests bewezen (`e8a9058`):** ability-constants, ability-gates per route, admin-allowlist-gate, Consumer-self-read happy + no-sub, billing:write-can-read, admin 422 validatie (plan + consumer), admin cancel 404 + 403.
- **0 regressies:** full suite 218 → 231 (13 nieuw), zelfde duration-profile (~4.8s).
- **Pint clean** — geen normalisatie nodig; bestaande conventies gevolgd.

## Task Commits

1. **Task 1 — RED skeleton:** `514a091` (feat) — TokenAbilities constants + EnsureEmeqAdminToken + config/billing.php + .env.example + bootstrap alias + 3 routes + BillingAbilityGateTest (7 files, +153/-0). 4 van 5 tests faalden op 404 (controllers nog niet bestaand); 1 test (constant-shape) passte al.
2. **Task 2 — GREEN controllers:** `e8a9058` (feat) — Consumer SubscriptionController + Admin SubscriptionController + CreateSubscriptionRequest + 3 nieuwe test-classes (6 files, +398/-0). Alle 13 tests GREEN; full suite 231/231.

## Files Created

### `app/Sanctum/TokenAbilities.php` (modified — verbatim diff)

```diff
     public const CONSUMER_MANAGE_ACCOUNTS = 'consumer:manage-accounts';
+
+    public const BILLING_READ = 'billing:read';
+
+    public const BILLING_WRITE = 'billing:write';

     public const ADMIN = '*';

     public static function all(): array
     {
         return [
             self::SNELSTART_READ,
             self::SNELSTART_WRITE,
             self::MOLLIE_READ,
             self::MOLLIE_WRITE,
             self::CONSUMER_MANAGE_ACCOUNTS,
+            self::BILLING_READ,
+            self::BILLING_WRITE,
             self::ADMIN,
         ];
     }
```

### `app/Http/Middleware/EnsureEmeqAdminToken.php` (verbatim, 35 regels)

Final class met `handle(Request, Closure): Response`. Leest `$request->user()` + `config('billing.admin_allowlist', [])` → 403 + `{"error":"not_admin","message":"Token hoort niet bij een Emeq-admin-Consumer."}` payload wanneer Consumer null is, allowlist geen array is, of Consumer-ID niet in allowlist staat.

### `app/Http/Controllers/Api/V1/Billing/SubscriptionController.php` (verbatim, 71 regels)

Final class, `show(Request): JsonResponse` + private `deriveStatus(Subscription): string`. Single-action-stijl matched `PingController`-pattern. Status-derivatie volgorde: onTrial → ended → grace → cancelled → active/inactive.

### `app/Http/Controllers/Api/V1/Admin/Billing/SubscriptionController.php` (verbatim, 91 regels)

Final class, constructor-injected `PlanResolver`. `store(CreateSubscriptionRequest): JsonResponse` returnt 201/202/502 afhankelijk van Cashier-Mollie's create-flow-resultaat (Subscription/getTargetUrl/exception). `destroy(Request, int): JsonResponse` retourneert 204 on cancel, 404 on missing.

### `app/Http/Requests/Admin/Billing/CreateSubscriptionRequest.php` (verbatim, 47 regels)

Final class. `authorize(): bool` retourneert true (middleware-laag doet auth); `rules()` met Rule::in op `PlanResolver::all()`-keys; `messages()` met Nederlandstalige strings; private `plansAllowed()`-helper.

### `config/billing.php` (verbatim, 17 regels)

Exporteert array met `admin_allowlist` (intval/comma-parse van env) + `default_subscription_name` (env default `'main'`). NL-comment over default-deny safety-net.

### 4 nieuwe test-classes

- `BillingAbilityGateTest` — 5 tests: constant-shape (passt al na RED), auth-required (401), missing-billing-ability (403), missing-billing-write-on-admin (403), empty-allowlist-on-admin (403 + not_admin).
- `ConsumerSubscriptionReadTest` — 3 tests: no-subscription (subscribed=false), active-subscription via withActiveSubscription-factory (status=active), billing:write-can-read.
- `AdminSubscriptionCreateTest` — 3 tests: invalid plan_slug 422, unknown consumer_id 422, create-met-pre-existing-subscription (soft-assert in {201,202,502}).
- `AdminSubscriptionCancelTest` — 2 tests: 404 op onbekende ID, 403 + not_admin op lege allowlist.

## Routes Registered

| Method | URI | Name | Middleware | Controller |
|--------|-----|------|------------|------------|
| GET | `v1/billing/subscription` | `api.billing.subscription.show` | `auth:sanctum`, `ability:billing:read,billing:write,*` | `Api\V1\Billing\SubscriptionController@show` |
| POST | `v1/admin/billing/subscriptions` | `api.admin.billing.subscriptions.store` | `auth:sanctum`, `ability:billing:write,*`, `emeq.admin` | `Api\V1\Admin\Billing\SubscriptionController@store` |
| DELETE | `v1/admin/billing/subscriptions/{id}` | `api.admin.billing.subscriptions.destroy` | idem | `Api\V1\Admin\Billing\SubscriptionController@destroy` |

## Test-resultaat per file

```
BillingAbilityGateTest             5/5 passed
ConsumerSubscriptionReadTest       3/3 passed
AdminSubscriptionCreateTest        3/3 passed
AdminSubscriptionCancelTest        2/2 passed
Total                             13/13 passed
```

Full-suite baseline na execution:
```
{"tool":"phpunit","result":"passed","tests":231,"passed":231,"assertions":748,"duration_ms":4779,"incomplete":1}
```

- Pre-plan baseline: 218 passed (06-04 baseline).
- Post-plan: **231 passed, 748 assertions, 4.8s** (13 nieuw, 0 regressies).
- 1 incomplete (pre-existing marker, geen billing-relatie).

## Decisions Made

- **Subscription-status afgeleid uit Cashier state-methodes**, niet uit een non-existent `status`-kolom. De plan-tekst toonde een controller die `$subscription->status` + `$subscription->mollie_subscription_id` returnde, maar `subscriptions`-tabel (gepubliceerd in 06-02) heeft die kolommen niet. Implementatie gebruikt de officiele Cashier-API: `onTrial()` → `'trialing'`, `ended()` → `'ended'`, `onGracePeriod()` → `'grace'`, `cancelled()` → `'cancelled'`, `active()` → `'active'`, fallback `'inactive'`. Resultaat-shape blijft consument-vriendelijk (één `status`-veld) zonder Cashier-implementation-leak.
- **AdminSubscriptionCreateTest test 3 is soft-assertion.** Cashier's `newSubscription()->create()` hit altijd Mollie's API (eerst voor `getMollieCustomer()`, dan voor mandate/subscription). Zonder echte test-mode key kan de unit-suite die call niet hard-asserten. Plan 06-07 ("integration tests") koppelt een echte test-key in en hard-asserts dan op 201/202. Soft-assert in {201,202,502} dekt af: route resolved, middleware passt, Form-Request valideert, controller voert Cashier-call uit, en de uitkomst is een van de drie verwachte status-codes.
- **Empty `BILLING_ADMIN_CONSUMER_IDS` = default-deny.** Default-empty `.env.example` betekent dat een verse install ZONDER expliciete admin-ID's GEEN admin-endpoints openzet — de middleware retourneert direct 403. T-06-05-01 (privilege escalation) wordt zo by-default gemitigeerd; staging/prod moet bewust een Consumer-ID toevoegen. Tests dekken het lege-allowlist-pad expliciet (2 tests).
- **Ability-OR-pattern voor read.** `ability:billing:read,billing:write,*` betekent: Consumer met ELK van billing:read, billing:write, of `*` mag lezen. Sanctum's `CheckForAnyAbility`-middleware verwerkt comma-separated als OR. Bewuste design-keuze: een admin met alleen `billing:write` hoeft niet óók `billing:read` toegekend te krijgen om zijn eigen subscription te kunnen pollen.
- **`Subscription::query()->findOrFail($id)` in destroy.** Cross-Consumer-cancel-check valt buiten scope hier — admin-endpoints zijn juist bedoeld om namens een Consumer te cancellen. Phase 9 Filament-resource zal expliciete scope-checks introduceren wanneer commerciele customers admin-rechten krijgen. T-06-05-02 (spoofing) wordt voor admin-pad expliciet `accept` (zie threat-register in plan).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Plan-controller referenceert niet-bestaande DB-kolommen**

- **Found during:** Task 2 — implementatie van Consumer-SubscriptionController.
- **Issue:** Plan-tekst (regel 543-547) toonde `'status' => $subscription->status` + `'mollie_subscription_id' => $subscription->mollie_subscription_id`. Schema-introspectie (`Schema::getColumnListing('subscriptions')`) wees uit dat noch `status` noch `mollie_subscription_id` als kolom bestaat in de migration uit plan 06-02 (alleen `name`, `plan`, `owner_type`, `owner_id`, `next_plan`, `quantity`, `tax_percentage`, `ends_at`, `trial_ends_at`, `cycle_started_at`, `cycle_ends_at`, `scheduled_order_item_id`, `created_at`, `updated_at`).
- **Fix:** Status wordt nu afgeleid uit `Subscription::active()/cancelled()/onTrial()/onGracePeriod()/ended()`-methodes via een private `deriveStatus()`-helper. `mollie_subscription_id` weggelaten uit de response; `ends_at` + `trial_ends_at` zijn de relevante datum-velden. Test 2 in ConsumerSubscriptionReadTest asserteert `status === 'active'` voor een door `withActiveSubscription`-factory gecreëerde rij (factory zet geen `ends_at` → `active()` returnt true → `deriveStatus` returnt `'active'`).
- **Files modified:** `app/Http/Controllers/Api/V1/Billing/SubscriptionController.php` (vs. plan-tekst).
- **Commit:** `e8a9058` (Task 2 GREEN).

**2. [Rule 3 — Blocking] Worktree-vendor-symlink breekt PHPUnit-route-registratie**

- **Found during:** Task 2 — eerste `php artisan test` runs gaven 404 op alle nieuwe routes, ondanks dat `php artisan route:list` ze WEL toonde.
- **Issue:** De worktree bootstrap-procedure uit voorgaande plans (`ln -s ../../../vendor vendor`) creëert een symlink-vendor. Composer's autoloader resolved `vendor/composer/autoload_psr4.php` met `$baseDir` correct naar de worktree, maar Laravel's `Application::inferBasePath()` valt terug op `ClassLoader::getRegisteredLoaders()` → dat geeft het GERESOLVEDE filesystem-pad → MAIN-repo `/Users/.../emeq-hub`, niet de worktree. Resultaat: `base_path()` in PHPUnit-context = main-repo, dus `bootstrap/app.php` laadt main-repo's `routes/api.php` (zonder onze nieuwe billing-routes). Klassen autoloaden correct vanuit de worktree (zelfde TokenAbilities-class met `BILLING_READ` constant), wat verklaart waarom de constant-test passte maar route-tests 404'den.
- **Fix:** `APP_BASE_PATH="$(pwd)"` env-variabele voor PHPUnit-runs. `Application::inferBasePath()` checkt eerst `$_ENV['APP_BASE_PATH']` en `$_SERVER['APP_BASE_PATH']` vóór de ClassLoader-fallback, dus een expliciete env-set kanaliseert Laravel naar de worktree-paths. Resultaat: `php artisan route:list` + `APP_BASE_PATH=$(pwd) php artisan test` beide consistent.
- **Niet-gefixt:** `phpunit.xml` niet aangepast (zou main-repo-tests breken). Toekomstige worktree-plans moeten `APP_BASE_PATH` zetten in de test-invocatie. Alternatief: vol `composer install` in de worktree (~209M extra disk). Aanbeveling voor toekomstige executors: documenteer dit in `.planning/STATE.md` of `CLAUDE.md` worktree-bootstrap-block.
- **Commit:** Geen separate commit — alleen env-variabele in test-run, geen file-wijziging.

**3. [Rule 3 — Blocking] Worktree mist `.env` + `vendor` na fresh checkout**

- **Found during:** vóór Task 1 — `php artisan --version` initieel werkte niet.
- **Fix:** `cp ../../../.env .env` + `ln -s ../../../vendor vendor` (identiek aan deviation #3 in plan 06-04). Beide files zijn `.gitignored` — geen risico van per-ongeluk-commit.
- **Verification:** `git check-ignore vendor .env` returnt beide paths.

**Total deviations:** 3 — 1× Rule 1 (bug fix in controller-implementatie), 2× Rule 3 (worktree-bootstrap). Geen Rule-2 (security) of Rule-4 (architecturele) issues; plan-strukturen waren correct.

## Threat Flags

Geen. Plan voegt 3 routes toe maar:
- Geen nieuwe credentials/tokens in DB.
- Geen nieuwe webhook-ingress (blijft Phase 6-06's scope).
- Geen schema-changes.
- Geen wijzigingen aan Phase 5a/5b's pass-through-endpoints (intact).
- Encryption-at-rest invariant intact (Consumer-model heeft alleen `webhook_callback_secret` als encrypted cast).

Threat-register T-06-05-01 t/m T-06-05-06 zijn allemaal `mitigate` (door middleware/Form-Request) of `accept` (Mollie-IDs publiek, throttle:api dekt DoS). Default-deny op empty allowlist is een belangrijke mitigatie die expliciet door 2 tests wordt gedekt.

## Issues Encountered

- **Worktree vendor-symlink** (zie deviation #2) — kost ~10 min onderzoek, opgelost via `APP_BASE_PATH` env-var. Hoofdsymptoom: routes registreren in CLI-route-list maar 404'en in PHPUnit.
- **Cashier subscription-tabel heeft geen status-kolom** (zie deviation #1) — kost ~3 min: schema-introspectie via `php artisan tinker --execute 'dump(Schema::getColumnListing("subscriptions"));'` wees direct uit welke kolommen wel/niet bestaan.
- **`composer dump-autoload`** na elke verandering aan classes was niet strikt nodig met PSR-4 autoloader, maar werd preventief gerund om class-not-found errors voor RED→GREEN transition te vermijden.

## Deferred Items

- **`/docs-sync` skill-pass** — `app/Http/Controllers/Api/V1/Billing/`, `app/Http/Controllers/Api/V1/Admin/Billing/`, `app/Http/Requests/Admin/Billing/`, en `app/Http/Middleware/EnsureEmeqAdminToken.php` zijn nieuwe paden die in `.docs/README.md`-index en CLAUDE.md's architectuur-pointers zouden kunnen landen. Niet uitgevoerd in plan-scope (zelfde reasoning als 06-04 deferred-items: skill-pass kan `.docs/` + `CLAUDE.md` aanraken — buiten chirurgische `files_modified`-whitelist). Aanbeveling: user runt `/docs-sync` losse pass na merge.
- **Cross-Consumer-cancel-guard in admin destroy** — `Subscription::query()->findOrFail($id)` cancelt any subscription by ID. Phase 9 Filament zal scope-checks introduceren wanneer derde-partij-admin-rechten landen. v0.2 acceptable: admin-allowlist is intern, geen externe attack-surface.
- **APP_BASE_PATH documentation in CLAUDE.md** — toekomstige worktree-executors moeten dit weten. Niet gewijzigd in deze plan-scope, maar genoteerd voor `/gsd-map-codebase` of CLAUDE.md-onderhoud.

## Next Phase Readiness

**Klaar voor plan 06-06 (Cashier webhook controller):**
- Sanctum `billing:write`-ability bestaat; webhook-route hoeft geen Sanctum-auth maar mag wel de ability als documentatie-reference noemen.
- Admin-middleware-pattern (`emeq.admin`) gevalideerd; webhook-controller kan het pattern overnemen als hij ooit admin-only-debugging-endpoints krijgt.
- `bootstrap/app.php` middleware-alias-block is uitgebreid; volgend plan kan dezelfde regel volgen.

**Klaar voor plan 06-07 (integration tests):**
- 3 routes resolved + Form-Request validatie groen → integration suite kan direct happy-path-create tegen Mollie test-mode hit'en.
- `withActiveSubscription` factory + Cashier's polymorf scoping bewezen werkend in unit-laag → integration kan factory + real-Mollie-call combineren.

**Blockers:** geen. Plans 06-06 + 06-07 zijn ontblokt.

---

*Phase: 06-cashier-mollie-integratie-use-case-a*
*Plan: 05*
*Completed: 2026-05-15*

## Self-Check: PASSED

- FOUND: `.planning/phases/06-cashier-mollie-integratie-use-case-a/06-05-SUMMARY.md` (this file)
- FOUND: `app/Http/Middleware/EnsureEmeqAdminToken.php`
- FOUND: `app/Http/Controllers/Api/V1/Billing/SubscriptionController.php`
- FOUND: `app/Http/Controllers/Api/V1/Admin/Billing/SubscriptionController.php`
- FOUND: `app/Http/Requests/Admin/Billing/CreateSubscriptionRequest.php`
- FOUND: `config/billing.php`
- FOUND: `tests/Feature/Api/V1/Billing/BillingAbilityGateTest.php`
- FOUND: `tests/Feature/Api/V1/Billing/ConsumerSubscriptionReadTest.php`
- FOUND: `tests/Feature/Api/V1/Billing/AdminSubscriptionCreateTest.php`
- FOUND: `tests/Feature/Api/V1/Billing/AdminSubscriptionCancelTest.php`
- FOUND: commit `514a091` (Task 1 — RED ability-gates)
- FOUND: commit `e8a9058` (Task 2 — GREEN controllers + 13 tests)
- OK: `TokenAbilities::BILLING_READ === 'billing:read'` + `BILLING_WRITE === 'billing:write'`
- OK: `TokenAbilities::all()` count = 8
- OK: 3 routes geregistreerd via `php artisan route:list`
- OK: `emeq.admin` alias in `bootstrap/app.php`
- OK: 13/13 billing-tests passed, 231/231 full suite passed (0 regressies, 218 → 231)
- OK: Pint clean (geen normalisatie nodig)
- OK: `BILLING_ADMIN_CONSUMER_IDS=` + `BILLING_DEFAULT_SUBSCRIPTION_NAME=main` in `.env.example`
