---
phase: 06-cashier-mollie-integratie-use-case-a
plan: 06
subsystem: cashier-mollie / webhook-ingress
tags: [cashier-mollie, webhook, security, hard-fail-guard, middleware, sub-01, tdd, d-10, d-11]

# Dependency graph
requires:
  - phase: 06-cashier-mollie-integratie-use-case-a
    plan: 02
    provides: "config/cashier.php (webhook_url defaults) + services.cashier.webhook_secret-binding + Cashier-Mollie vendor-package geïnstalleerd"
  - phase: 06-cashier-mollie-integratie-use-case-a
    plan: 05
    provides: "bootstrap/app.php middleware-alias-block uitgebreid + Phase 5a webhook-pattern reference"
  - phase: 05a-mollie-connect-pass-through-api
    plan: 06
    provides: "MollieWebhookController stap-0 hard-fail guard pattern (regel 37-46) waar wij verbatim van clonen in middleware-vorm"
provides:
  - "App\\Http\\Middleware\\RequireCashierWebhookSecret — stap-0 hard-fail guard met Spatie WebhookCall audit-write (name='cashier')"
  - "bootstrap/app.php alias 'cashier.webhook.secret' → RequireCashierWebhookSecret::class"
  - "AppServiceProvider::register() Cashier::ignoreRoutes()-call — schakelt Cashier's auto-route-registratie uit"
  - "routes/webhooks.php: 3 nieuwe POST-routes onder /cashier/webhook(/first-payment|/aftercare) → vendor's Laravel\\Cashier\\Http\\Controllers\\* handlers"
  - "config/cashier.php webhook_url-keys herwijzen van webhooks/mollie* naar cashier/webhook*"
affects:
  - 06-07 (integration suite hit /cashier/webhook met echte Mollie test-mode key voor end-to-end happy-path)
  - Phase 9 (Filament BillingResource kan webhook_calls met name='cashier' apart filteren van name='mollie')

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "TDD RED→GREEN: RED-commit faalt op 404 (route niet geregistreerd); GREEN-commit landt middleware + Cashier::ignoreRoutes + routes"
    - "Cashier::ignoreRoutes() in AppServiceProvider::register() — vóór CashierServiceProvider::boot() leest static \$registersRoutes-flag"
    - "Hard-fail-guard als MIDDLEWARE i.p.v. inline-in-controller — vendor-class (Laravel\\Cashier\\Http\\Controllers\\WebhookController) wordt niet gepatcht maar omwikkeld"
    - "Spatie WebhookCall.exception is cast als 'array' — test-assertions via model-attribute (\$row->exception === 'foo'), NIET via assertDatabaseHas (matcht raw JSON-string \"\\\"foo\\\"\")"
    - "Audit-naam-namespacing: webhook_calls.name = 'cashier' (use-case A billing) vs 'mollie' (Phase 5a Connect) onderscheid"

key-files:
  created:
    - "app/Http/Middleware/RequireCashierWebhookSecret.php"
    - "tests/Feature/Webhooks/CashierWebhookSecretGuardTest.php"
    - "tests/Feature/Webhooks/CashierWebhookRoutingTest.php"
  modified:
    - "app/Providers/AppServiceProvider.php"
    - "bootstrap/app.php"
    - "config/cashier.php"
    - "routes/webhooks.php"

key-decisions:
  - "Cashier::ignoreRoutes() in register() (niet boot()) — CashierServiceProvider::boot() checkt de static flag, dus onze flag-flip moet vóór die boot-cycle plaatsvinden."
  - "Test 3 (set-secret-passes-guard) asserteert GEEN response-status maar GEEN webhook_misconfigured-audit-rij — Cashier's vendor-handler hit Mollie API zonder echte test-key en geeft UnauthorizedException → 500; dat is geen guard-failure maar downstream-gedrag. Plan 06-07 integration-suite covert dit met echte test-key."
  - "Geen wrapper-controller — Cashier's eigen Laravel\\Cashier\\Http\\Controllers\\WebhookController + FirstPaymentWebhookController + AftercareWebhookController worden direct in route-array geregistreerd, alleen omhuld door cashier.webhook.secret-middleware."

# Metrics
duration: 25min
completed: 2026-05-15
requirements-completed: []  # SUB-01 blijft in-progress; volgende plan 06-07 (integration) sluit SUB-01 af
---

# Phase 6 Plan 06: Cashier Webhook Hard-Fail Guard + Route Override Summary

**RequireCashierWebhookSecret middleware (D-11 stap-0 guard) + Cashier::ignoreRoutes() (D-10) + 3 routes onder /cashier/webhook + 6/6 RED→GREEN tests; full suite 231 → 237 zonder regressies.**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-05-15T08:42:00Z (na worktree-bootstrap + branch-reset)
- **Completed:** 2026-05-15T08:53:00Z
- **Tasks:** 2 (RED tests + GREEN implementation)
- **Files created:** 3 (1 middleware + 2 test-classes)
- **Files modified:** 4 (AppServiceProvider + bootstrap + config/cashier + routes/webhooks)

## Accomplishments

- **D-10 ingelost:** Cashier-webhook draait op `/cashier/webhook` — separaat van Phase 5a's `/webhooks/mollie/{connection_id}` Connect-route. Cashier's eigen default-routes (`webhooks/mollie`, `webhooks/mollie/aftercare`, `webhooks/mollie/first-payment`) zijn niet meer geregistreerd dankzij `Cashier::ignoreRoutes()` in `AppServiceProvider::register()`.
- **D-11 ingelost:** `App\Http\Middleware\RequireCashierWebhookSecret` valideert `config('services.cashier.webhook_secret')` op `is_string && !== ''`; falen → 500 + JSON `{"error":"webhook_misconfigured"}` + Spatie `webhook_calls`-audit-rij met `name='cashier'` + `exception='webhook_secret_not_configured'`. Pattern identiek aan `MollieWebhookController::__invoke` regel 37-46.
- **Middleware-alias `'cashier.webhook.secret' => RequireCashierWebhookSecret::class`** geregistreerd in `bootstrap/app.php` middleware-alias-block (alfabetisch tussen `emeq.admin` en `abilities`).
- **3 nieuwe routes** in `routes/webhooks.php`, alle binnen een `Route::middleware('cashier.webhook.secret')->group()`-blok:
  - `POST /cashier/webhook` → `Laravel\Cashier\Http\Controllers\WebhookController@handleWebhook` (naam: `webhooks.cashier.default`)
  - `POST /cashier/webhook/first-payment` → `FirstPaymentWebhookController@handleWebhook` (naam: `webhooks.cashier.first_payment`)
  - `POST /cashier/webhook/aftercare` → `AftercareWebhookController@handleWebhook` (naam: `webhooks.cashier.aftercare`)
- **`config/cashier.php`-paden herwezen:**
  - `webhook_url`: `webhooks/mollie` → `cashier/webhook`
  - `aftercare_webhook_url`: `webhooks/mollie/aftercare` → `cashier/webhook/aftercare`
  - `first_payment.webhook_url`: `webhooks/mollie/first-payment` → `cashier/webhook/first-payment`
  - Dit zorgt dat Cashier's `Cashier::webhookUrl()` / `aftercareWebhookUrl()` / `firstPaymentWebhookUrl()`-helpers (used bij subscription-create om Mollie te vertellen welke callback-URL te gebruiken) onze paden retourneren — niet de vendor-defaults.
- **6 RED-eerst tests committed (`9d048f9`) → 6 GREEN-tests bewezen (`fb118e2`):**
  - `CashierWebhookSecretGuardTest` (4 tests): empty-secret → 500 + audit; null-secret → 500 + audit; set-secret → guard passeert (geen misconfig-audit); audit-naam is 'cashier' niet 'mollie'.
  - `CashierWebhookRoutingTest` (2 tests): Phase 5a-route blijft op `MOLLIE_WEBHOOK_SECRET`-guard (cross-contamination-check); cashier-route geregistreerd op distinct path.
- **0 regressies:** full suite 231 → 237 (6 nieuw), Phase 5a `MollieWebhookSignatureTest`/`MollieWebhookFanOutTest`/`MollieWebhookAntiSpoofingTest` blijven 13/13 groen.
- **Pint clean** — geen normalisatie nodig na GREEN-commit.

## Task Commits

1. **Task 1 — RED tests:** `9d048f9` (test) — 2 nieuwe test-classes met 6 tests (4 + 2). 4 falen op 404 (cashier-route bestaat nog niet); 2 passen al (Phase 5a-route blijft 500 op empty mollie-secret + assertNotSame-check).
2. **Task 2 — GREEN implementation:** `fb118e2` (feat) — middleware + Cashier::ignoreRoutes-call + middleware-alias + 3 routes + 3 config-overrides + 1 test-refinement (test 3 omschreven naar audit-row-check ipv response-status, zie Deviations). Alle 6 tests GREEN; full suite 237/237.

## Files Created

### `app/Http/Middleware/RequireCashierWebhookSecret.php` (verbatim, 51 regels)

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\WebhookClient\Models\WebhookCall;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-11: stap-0 hard-fail guard voor /cashier/webhook-routes.
 * Analoog aan Phase 5a's MollieWebhookController regel 37-46.
 *
 * Reden voor middleware-vorm (ipv inline-in-controller): Cashier's eigen
 * Laravel\Cashier\Http\Controllers\WebhookController is een vendor-class
 * die wij niet patchen; een middleware wrapt 'm wel.
 *
 * NIET een signature-verify: reguliere Mollie-webhooks (NIET Connect) zijn
 * UNSIGNED en gebruiken een obscured URL als auth. Onze CASHIER_WEBHOOK_SECRET
 * is dus een aanvullende laag bovenop Mollie's URL-obscurity, niet een HMAC-key.
 *
 * Audit-rij krijgt name='cashier' (onderscheidbaar van Phase 5a name='mollie').
 */
class RequireCashierWebhookSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.cashier.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            $this->auditFailedWebhook($request, 'webhook_secret_not_configured');

            return response()->json([
                'error' => 'webhook_misconfigured',
            ], 500);
        }

        return $next($request);
    }

    private function auditFailedWebhook(Request $request, string $exception): void
    {
        WebhookCall::create([
            'name' => 'cashier',
            'url' => $request->fullUrl(),
            'headers' => $request->headers->all(),
            'payload' => $request->json()->all() ?: ['_raw' => substr($request->getContent(), 0, 1000)],
            'exception' => $exception,
        ]);
    }
}
```

### `routes/webhooks.php` — toegevoegd na bestaande Phase 5a-route

```php
use Laravel\Cashier\Http\Controllers\AftercareWebhookController;
use Laravel\Cashier\Http\Controllers\FirstPaymentWebhookController;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;

/*
 * Cashier-Mollie webhook-ingress (D-10/D-11). Separaat van Phase 5a's
 * /webhooks/mollie/{connection_id} Connect-route. Hard-fail guard via
 * cashier.webhook.secret-middleware. Geen fan-out — Cashier handle't
 * subscription-state-machine intern. Cashier's eigen default-routes zijn
 * uitgezet via Cashier::ignoreRoutes() in AppServiceProvider::register().
 */
Route::middleware('cashier.webhook.secret')->group(function (): void {
    Route::post('/cashier/webhook', [CashierWebhookController::class, 'handleWebhook'])
        ->name('webhooks.cashier.default');

    Route::post('/cashier/webhook/first-payment', [FirstPaymentWebhookController::class, 'handleWebhook'])
        ->name('webhooks.cashier.first_payment');

    Route::post('/cashier/webhook/aftercare', [AftercareWebhookController::class, 'handleWebhook'])
        ->name('webhooks.cashier.aftercare');
});
```

### `app/Providers/AppServiceProvider.php` — toegevoegd in `register()` na de credential-resolver-bind

```php
// D-10: Cashier's default-routes (webhooks/mollie*) uitzetten zodat wij ze
// zelf onder /cashier/webhook* registreren achter RequireCashierWebhookSecret.
// Moet in register() staan — CashierServiceProvider::boot() leest deze flag.
Cashier::ignoreRoutes();
```

### `bootstrap/app.php` middleware-alias-block

```php
'resolve.snelstart.account' => ResolveSnelstartAccount::class,
'resolve.mollie.account' => ResolveMollieAccount::class,
'emeq.admin' => EnsureEmeqAdminToken::class,
'cashier.webhook.secret' => RequireCashierWebhookSecret::class,   // ← nieuw
'abilities' => CheckAbilities::class,
'ability' => CheckForAnyAbility::class,
```

### `config/cashier.php` diff

```diff
+    /*
+     * Phase 6 D-10: Cashier's default webhook-paden zijn `webhooks/mollie*` —
+     * overschreven naar `cashier/webhook*` zodat ze niet botsen met Phase 5a's
+     * `/webhooks/mollie/{connection_id}` Connect-route en altijd achter onze
+     * RequireCashierWebhookSecret-guard hangen (route-binding in routes/webhooks.php).
+     * Cashier's `Cashier::webhookUrl()`-helper leest deze keys bij subscription-create.
+     */
+
     /**
      * The default webhook url is called by Mollie on payment status updates. ...
      */
-    'webhook_url' => 'webhooks/mollie',
+    'webhook_url' => 'cashier/webhook',

     /**
      * The default aftercare webhook url is called by Mollie on refunds and chargebacks. ...
      */
-    'aftercare_webhook_url' => 'webhooks/mollie/aftercare',
+    'aftercare_webhook_url' => 'cashier/webhook/aftercare',

     /* ... */
     'first_payment' => [
         /**
          * The first payment webhook url is called by Mollie on first payment status updates. ...
          */
-        'webhook_url' => 'webhooks/mollie/first-payment',
+        'webhook_url' => 'cashier/webhook/first-payment',
```

## Routes Registered

| Method | URI | Name | Middleware | Controller |
|--------|-----|------|------------|------------|
| POST | `cashier/webhook` | `webhooks.cashier.default` | `api`, `cashier.webhook.secret` | `Laravel\Cashier\Http\Controllers\WebhookController@handleWebhook` |
| POST | `cashier/webhook/first-payment` | `webhooks.cashier.first_payment` | `api`, `cashier.webhook.secret` | `Laravel\Cashier\Http\Controllers\FirstPaymentWebhookController@handleWebhook` |
| POST | `cashier/webhook/aftercare` | `webhooks.cashier.aftercare` | `api`, `cashier.webhook.secret` | `Laravel\Cashier\Http\Controllers\AftercareWebhookController@handleWebhook` |

Cashier's eigen `webhooks/mollie*`-default-routes zijn AFWEZIG (`Cashier::ignoreRoutes()` actief). Verificatie `php artisan route:list --path=webhooks/mollie` toont alleen Phase 5a's `webhooks/mollie/{connection_id}` Connect-route.

## Test-resultaat per file

```
CashierWebhookSecretGuardTest      4/4 passed
CashierWebhookRoutingTest          2/2 passed
Total                              6/6 passed
```

Full-suite baseline na execution:
```
{"tool":"phpunit","result":"passed","tests":237,"passed":237,"assertions":765,"duration_ms":5560,"incomplete":1}
```

- Pre-plan baseline: 231 passed (06-05 baseline).
- Post-plan: **237 passed, 765 assertions, 5.6s** (6 nieuw, 0 regressies).
- 1 incomplete (pre-existing marker, geen webhook-relatie).
- Phase 5a webhook-tests (13/13): GREEN — geen cross-contamination.

## Decisions Made

- **`Cashier::ignoreRoutes()` in `register()` (niet `boot()`).** Vendor-inspection (`vendor/mollie/laravel-cashier-mollie/src/CashierServiceProvider.php` regel 92-93): `if (Cashier::$registersRoutes) { $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php'); }` — die check zit in `CashierServiceProvider::boot()`. Service-provider-volgorde in Laravel: alle `register()`'s draaien vóór alle `boot()`'s. Dus moet onze flag-flip in **register()** staan, niet in `boot()`. Anders is Cashier al klaar met routes laden vóór wij ze "uit" zetten.
- **Test 3 omschreven naar audit-row-assertion.** Initiële RED-test asserteerde `assertNotSame(500, $response->status())`. In GREEN bleek: Cashier's eigen `WebhookController::handleWebhook` roept `getMolliePaymentById` aan op een Mollie-client die zonder echte test-key faalt met `UnauthorizedException` → ConvertToResponse middleware geeft 500. Onze guard is OK (de request bereikt downstream), maar Laravel's exception-handler geeft 500. Twee opties:
  1. Mollie-API mocken in deze unit-test (zoals Phase 5a's `fakeMolliePaymentGet`-helper) — maar dat dupliceert wat plan 06-07 met echte test-key gaat doen.
  2. Test verfijnen: asserteer wat WIJ leveren (geen `webhook_misconfigured`-audit-rij) i.p.v. wat de vendor levert (status-code).
  
  Optie 2 gekozen omdat het de scope-grens respecteert: dit plan levert alleen de guard-laag; Cashier-vendor-gedrag is buiten scope tot 06-07.
- **Geen wrapper-controller.** Plan-interfaces-block bood twee paden (a) eigen wrapper-controller met audit-write, of (b) directe vendor-controller-binding met audit in middleware. Optie (b) gekozen — minder code (geen vendor-controller-API te onderhouden) + audit-laag is al in middleware aanwezig voor de fail-path. Success-path-audit (= Cashier's eigen subscriptions/orders-tabel-writes) is buiten `webhook_calls`-scope; Phase 9 Filament zal die tonen.
- **Cashier vendor-routes blijven volledig vendor-controlled.** Wij wijzigen geen vendor-code, geen sub-classing van `WebhookController` etc. Bij eventuele Cashier-upgrades blijven onze 3 routes werken zolang de vendor-controllers `handleWebhook(Request)`-signature behouden (stable v2.20-API). Bij major breaking changes documenteren we in plan 06-07 of latere upgrades.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Spatie WebhookCall.exception JSON-cast breekt `assertDatabaseHas`**

- **Found during:** Task 2 GREEN-run — `assertDatabaseHas(['exception' => 'webhook_secret_not_configured'])` faalde met `Found similar results: ["\"webhook_secret_not_configured\""]`.
- **Issue:** Spatie's `WebhookCall`-model heeft `protected $casts = ['exception' => 'array']`. Wanneer je een string toewijst, JSON-encodeert Eloquent het bij save → de raw column-waarde wordt `"\"foo\""`. `assertDatabaseHas` raadpleegt de raw column → mismatch met `"foo"`-needle. Bij read via `$row->exception` (Eloquent's accessor) wordt het terug-decoded naar `"foo"`. Phase 5a `MollieWebhookSignatureTest` gebruikt model-attribute-assertions (`$row->exception` met `assertSame`), niet `assertDatabaseHas`.
- **Fix:** Tests 1 + 2 omgeschreven naar `WebhookCall::query()->latest('id')->first()` + `$this->assertSame('webhook_secret_not_configured', $row->exception)`. Test 4 (audit-naam-check) gebruikte al model-attribute-pattern.
- **Files modified:** `tests/Feature/Webhooks/CashierWebhookSecretGuardTest.php` (test 1 + 2).
- **Commit:** `fb118e2` (Task 2 GREEN bevat de test-refinement in dezelfde commit als de implementation).

**2. [Rule 1 — Bug] Test 3 (set-secret-passes) faalde op `assertNotSame(500)` — Cashier's vendor-handler 500't bij ontbrekende test-key**

- **Found during:** Task 2 GREEN-run — test 3 status was 500 (UnauthorizedException uit Mollie API), niet 200/422 zoals RED-test verwachtte.
- **Issue:** RED-test asserteerde `assertNotSame(500, $response->status())` — de aanname was dat Cashier's controller met een unknown payment-id een 400/422 zou geven. Realiteit: Cashier's `BaseWebhookController::getMolliePaymentById` hit Mollie's API om de payment te resolven (regel 33), maar zonder geldige `MOLLIE_KEY` env-variabele krijg je een 401 UnauthorizedException die door de framework-exception-handler tot 500 wordt vertaald. Dat is downstream-vendor-gedrag, niet ons guard-pad.
- **Fix:** Test 3 omschreven (zie ook Decisions Made) naar audit-row-assertion: `WebhookCall` voor `name='cashier'` met `exception='webhook_secret_not_configured'` mag NIET bestaan bij gezette secret. Naam vernieuwd naar `test_set_secret_passes_guard_and_does_not_write_misconfigured_audit` om duidelijker te tonen wat het asserteert.
- **Niet-gefixt:** echte happy-path 200-assertion blijft uitgesteld tot plan 06-07 (integration-suite met echte Mollie test-mode key, zoals plan 06-07 al voorzag in CONTEXT.md D-12).
- **Files modified:** `tests/Feature/Webhooks/CashierWebhookSecretGuardTest.php` (test 3).
- **Commit:** `fb118e2`.

**3. [Rule 3 — Blocking] Worktree mist `.env` + `vendor` na fresh checkout (identiek 06-04 + 06-05)**

- **Found during:** vóór Task 1 — `php artisan` initieel niet werkbaar.
- **Fix:** `cp ../../../.env .env` + `ln -s ../../../vendor vendor` + `git reset --hard 5335e989` (HEAD stond initieel op Phase 2 base; reset zoals het worktree-branch-check-script al voorschreef bij branch-check). Beide files zijn gitignored (`git check-ignore` verified).

**4. [Rule 3 — Blocking] Eerste Write-calls landden in main repo i.p.v. worktree**

- **Found during:** Task 2 — eerste runs van `php artisan route:list --path=cashier` returnden niets, terwijl `git status` schoon was in worktree. Bleek: 4 file-Edits via Write-tool aangeroepen met `/Users/yusufkaracaburun/Sites/localhost/emeq-hub/...`-pad waren in de **main repo** geland, niet in `/Users/.../.claude/worktrees/agent-a95cf92a8b046b25f/...`.
- **Issue:** Tool-call-pad-resolution buiten worktree-CWD landt bestanden op de letterlijke absolute pad. `tests/`-files via `Write` op het korte main-repo-pad eindigden in main-repo's tests-dir; vervolgens `git reset --hard` in worktree wiste daar niets (immers buiten worktree). De middleware-Write op het volledige worktree-pad WERKTE wel.
- **Fix:** Main-repo dirty files reverted (`git checkout -- ...` + `rm -f` voor untracked test-files), daarna alle Edits opnieuw aangeroepen met **volledige worktree-absolute paden** (`/Users/.../.claude/worktrees/agent-a95cf92a8b046b25f/app/...`). Tweede ronde werkte vanuit cwd=worktree zonder mismatches.
- **Niet-gefixt:** geen tool-side-fix in deze plan-scope. Toekomstige executors moeten weten dat absolute paden buiten cwd interpretatie krijgen die de Edit/Write-tool letterlijk respecteert. Aanbeveling: documenteer in CLAUDE.md worktree-bootstrap-block dat `pwd` cwd moet zijn en relatieve paden te prefereren zijn waar mogelijk.
- **Commit:** geen separate commit — geen file-wijziging buiten de eindstand-Edits.

**5. [Rule 3 — Blocking] Composer dump-autoload moet vanuit worktree-CWD draaien**

- **Found during:** Task 2 GREEN-run — `Target class [App\Http\Middleware\RequireCashierWebhookSecret] does not exist` ondanks dat het bestand bestond in `app/Http/Middleware/`.
- **Issue:** `composer dump-autoload -o` vanuit main-repo CWD scant main-repo's `app/`-tree — de worktree's nieuwe middleware-class komt niet in `vendor/composer/autoload_classmap.php` te staan.
- **Fix:** `composer dump-autoload -o` vanuit worktree-CWD (`/Users/.../agent-a95cf92a8b046b25f`). Dat scant de worktree-`app/`-tree (via PSR-4 namespace-map die overal hetzelfde `App\\` blijft) en voegt 1 extra class toe (10185 → 10186).
- **Niet-gefixt:** dezelfde APP_BASE_PATH-discussie als 06-05 SUMMARY deviation #2. Aanbeveling: documenteer in worktree-bootstrap-block dat `composer dump-autoload` na elke nieuwe class vanuit worktree-CWD MOET draaien, niet vanuit main-repo.

**Total deviations:** 5 — 2× Rule 1 (test-assertion-bug fixes), 3× Rule 3 (worktree-bootstrap-blocking). Geen Rule 2 (security) of Rule 4 (architectuur) issues; plan-strukturen waren correct, alleen test-expectations en environment-setup vroegen om aanpassing.

## Threat Flags

Geen nieuwe threat-surface buiten plan's `<threat_model>`. Plan voegt 3 publieke routes toe maar:
- Allemaal achter `RequireCashierWebhookSecret`-middleware (default-deny op empty secret — mitigation T-06-06-01).
- Audit-rij distinct van Phase 5a (mitigation T-06-06-04).
- Throttle:api blijft actief (DoS-mitigation T-06-06-05, hergebruikt).
- Geen nieuwe credential-storage; geen schema-changes.
- Geen wijzigingen aan Phase 5a/5b's pass-through-endpoints (intact).

T-06-06-01 t/m T-06-06-05 zijn allemaal afgedekt zoals het plan voorzag.

## Known Stubs

Geen UI-stubs of placeholder-data. Cashier's vendor-controllers leveren de subscription-state-machine; de happy-path tot Mollie-callback wordt in plan 06-07 (integration) ge-end-to-end-test. Geen reden om dit plan-resultaat als "stub" te markeren — guard-laag is volledig functioneel en getest.

## Issues Encountered

- **Worktree-bootstrap-cascade** (deviations #3 + #4 + #5) — kost ~10 min totaal. Hoofdoorzaak: drie samenwerkende problemen (HEAD-reset, file-write-routing, autoload-cache) die allemaal vanuit een schone main-repo-CWD-aanname opduiken. 06-05 SUMMARY documenteerde #3 + #5 al; deze plan-uitvoering oppervlakkt #4 als toevoeging.
- **Spatie WebhookCall JSON-cast verrassing** (deviation #1) — kost ~3 min. Hoofdsymptoom: `assertDatabaseHas`-failure-message toonde direct het JSON-encoded pattern; oplossing was om naar Phase 5a-stijl model-attribute-assertions over te stappen.
- **Cashier vendor downstream 500 zonder echte Mollie-key** (deviation #2) — kost ~5 min. Hoofdsymptoom: trace toont `UnauthorizedException` uit Mollie-API; oplossing was om de test-scope te verfijnen tot wat de middleware-laag verantwoordelijk voor is.

## Deferred Items

- **`/docs-sync` skill-pass** — `app/Http/Middleware/RequireCashierWebhookSecret.php` is een nieuwe middleware, `routes/webhooks.php` heeft 3 nieuwe routes, `config/cashier.php` heeft custom keys met D-10-comment. `CLAUDE.md` "Routes" sectie zegt nog "routes/webhooks.php is nog gepland" — verouderd sinds Phase 5a. `.docs/README.md` index zou Cashier-webhook-paden kunnen vermelden. Niet uitgevoerd in plan-scope (skill-pass kan `.docs/` + `CLAUDE.md` aanraken — buiten chirurgische `files_modified`-whitelist; zelfde reasoning als 06-04 + 06-05 deferred-items). Aanbeveling: user runt `/docs-sync` losse pass na merge.
- **`APP_BASE_PATH` + worktree-bootstrap CLAUDE.md-block** — toekomstige worktree-executors blijven tegen dezelfde drie deviation-patronen oplopen tot dit gedocumenteerd is. Niet gewijzigd in deze plan-scope (verschuift docs-architecture, niet plan-scope), maar genoteerd voor `/gsd-map-codebase` of CLAUDE.md-onderhoud. 06-05 SUMMARY had hetzelfde deferred-item.
- **Integration-test happy-path met echte Mollie test-mode key** — plan 06-07 takes this. Onze test 3 dekt nu alleen de guard-passage; een 2xx-response-assert met echte Mollie-test-customer + valide payment-id is `@group integration`-territory.

## Next Phase Readiness

**Klaar voor plan 06-07 (integration tests):**
- `/cashier/webhook` is publiek bereikbaar achter `cashier.webhook.secret`-guard — 06-07 kan met valide `CASHIER_WEBHOOK_SECRET` + echte Mollie test-mode payment-id de happy-path triggeren en hard-asserten op 200 + Cashier's subscriptions-tabel-update.
- `Cashier::webhookUrl()` retourneert nu `cashier/webhook` — 06-07's admin-create-subscription-flow (uit 06-05) zal Mollie de juiste callback-URL doorgeven.
- Phase 5a-route en Phase 6-routes zijn aantoonbaar gescheiden — 06-07 kan parallel test-scenario's voor Connect (via `/webhooks/mollie/{connection_id}`) en Billing (via `/cashier/webhook`) draaien zonder cross-test-contamination.

**Klaar voor Phase 9 (Filament admin-UI):**
- `webhook_calls`-tabel heeft nu twee distincte `name`-waarden (`'mollie'` voor Connect-pass-through, `'cashier'` voor Cashier-billing) — Filament's `BillingResource` kan op `where('name', 'cashier')` filteren voor billing-only audit-history.

**Blockers:** geen. Plan 06-07 is ontblokt.

---

*Phase: 06-cashier-mollie-integratie-use-case-a*
*Plan: 06*
*Completed: 2026-05-15*

## Self-Check: PASSED

- FOUND: `.planning/phases/06-cashier-mollie-integratie-use-case-a/06-06-SUMMARY.md` (this file)
- FOUND: `app/Http/Middleware/RequireCashierWebhookSecret.php`
- FOUND: `tests/Feature/Webhooks/CashierWebhookSecretGuardTest.php`
- FOUND: `tests/Feature/Webhooks/CashierWebhookRoutingTest.php`
- FOUND: commit `9d048f9` (Task 1 — RED tests, 6 tests, 4 falen op 404)
- FOUND: commit `fb118e2` (Task 2 — GREEN: middleware + Cashier::ignoreRoutes + routes + config + test-refinement)
- OK: `Cashier::ignoreRoutes()` in `AppServiceProvider::register()`
- OK: `'cashier.webhook.secret' => RequireCashierWebhookSecret::class` alias in `bootstrap/app.php`
- OK: 3 nieuwe POST-routes onder `/cashier/webhook*` in `routes/webhooks.php`
- OK: `config/cashier.php` webhook_url + aftercare_webhook_url + first_payment.webhook_url alle 3 herwezen naar `cashier/webhook*`
- OK: `php artisan route:list --path=cashier` toont 3 routes
- OK: `php artisan route:list --path=webhooks/mollie` toont alleen Phase 5a `webhooks/mollie/{connection_id}` (Cashier-defaults afwezig)
- OK: 6/6 nieuwe tests passed (CashierWebhookSecretGuardTest 4/4 + CashierWebhookRoutingTest 2/2)
- OK: full suite 231 → 237 (0 regressies, 6 nieuw)
- OK: Phase 5a 13/13 webhook-tests blijven GREEN
- OK: Pint clean
- OK: `services.cashier.webhook_secret`-binding hergebruikt uit plan 06-02
