---
phase: 03-hub-skeleton
plan: 03
subsystem: hub-api-smoke
tags:
  - laravel
  - sanctum
  - api
  - routing
  - phpunit
requirements:
  - HUB-01
dependency-graph:
  requires:
    - "03-01: App\\Models\\Consumer met HasApiTokens-trait + ConsumerFactory"
    - "03-02: auth:sanctum-guard + consumers-provider + routes/api.php-skeleton + App\\Sanctum\\TokenAbilities"
  provides:
    - "GET /v1/ping route achter auth:sanctum + throttle:api (Laravel api-group default)"
    - "App\\Http\\Controllers\\Api\\V1\\PingController invokable, retourneert {pong, consumer, abilities}"
    - "Tests\\Feature\\Api\\PingTest (3 tests: happy + unauth + abilities surfaced)"
    - "Tests\\Feature\\Api\\SanctumAbilityTest (3 tests: admin-wildcard + specific-ability + Phase-5b-placeholder)"
  affects:
    - "Phase 5a (/v1/mollie/* kan dezelfde auth:sanctum-middleware pattern hergebruiken)"
    - "Phase 5b (/v1/snelstart/* + /v1/accounts + /v1/connections — PingController-template + ability-middleware-laag bovenop)"
    - "Phase 3 plan 03-05 (hub:consumer:create produceert PAT die op /v1/ping smoke-tested kan worden)"
tech-stack:
  added: []
  patterns:
    - "Single-action __invoke-controller voor één-route-endpoints (PATTERNS.md regel 251-289)"
    - "Invokable-route via PingController::class (geen tuple-syntax)"
    - "PHPUnit Feature-test in sub-namespace Tests\\Feature\\Api + PSR-4-directory tests/Feature/Api/"
    - "markTestIncomplete() als placeholder voor future-phase ability-middleware-coverage (groen-blijvende suite)"
key-files:
  created:
    - "app/Http/Controllers/Api/V1/PingController.php"
    - "tests/Feature/Api/PingTest.php"
    - "tests/Feature/Api/SanctumAbilityTest.php"
  modified:
    - "routes/api.php"
decisions:
  - "PingController is single-action __invoke (CONTEXT.md Claude's Discretion regel 274) — leesbaarder dan resourceful controller voor één smoke-route"
  - "PingController retourneert plain array (Laravel cast't naar JSON-response) i.p.v. expliciete JsonResponse — matched bestaande routes/web.php closure-stijl"
  - "Tests\\Feature\\Api sub-namespace (eigen directory) — encryption + scoping tests blijven op Tests\\Feature-root; HTTP-tests krijgen Api-prefix"
  - "SanctumAbilityTest test_token_without_required_ability_is_rejected blijft markTestIncomplete tot Phase 5b een route met ->middleware('ability:snelstart:read') heeft — Phase 3 levert geen ability-checked route"
  - "abilities-key in pong-payload accepteert null-token (currentAccessToken kan null zijn bij Sanctum::actingAs zonder ability-array) via ?? [] fallback"
metrics:
  duration_minutes: 5
  tasks_completed: 3
  tasks_total: 3
  files_created: 3
  files_modified: 1
  commits: 3
  completed_at: "2026-05-14"
---

# Phase 3 Plan 03: PingController + /v1/ping + Sanctum-smoke-tests Summary

Eerste werkende `/v1/*`-route geland: `GET /v1/ping` achter `auth:sanctum`, gebruikt PingController dat Consumer-slug + token-abilities in een pong-payload teruggeeft. Zes tests bewijzen happy-path, unauth-path, abilities-surfacing, admin-wildcard, specifieke ability-acceptatie en een placeholder voor Phase 5b's scherpe ability-middleware-check.

## What Was Built

### Task 1 — PingController + routes/api.php (commit `948dcb6`)

`app/Http/Controllers/Api/V1/PingController.php` (nieuw, via `php artisan make:controller --invokable`):

```php
public function __invoke(Request $request): array
{
    /** @var Consumer $consumer */
    $consumer = $request->user();

    return [
        'pong' => true,
        'consumer' => $consumer->slug,
        'abilities' => $consumer->currentAccessToken()?->abilities ?? [],
    ];
}
```

`extends App\Http\Controllers\Controller` (lege abstract base bestond al), PHPDoc `@var Consumer` om Sanctum's `User|Authenticatable`-return-type te narrowen, null-safe `currentAccessToken()?->abilities ?? []` voor `Sanctum::actingAs()`-paden.

`routes/api.php` — `use App\Http\Controllers\Api\V1\PingController;` + `use Illuminate\Support\Facades\Route;` toegevoegd (Pint had ze in 03-02 gestript bij lege skeleton). Inhoud:

```php
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/ping', PingController::class)->name('api.ping');
});
```

Geen `Route::prefix('v1')` — `apiPrefix: 'v1'` wordt door `bootstrap/app.php` toegepast (geland in 03-02).

### Task 2 — PingTest (commit `b8b125e`)

`tests/Feature/Api/PingTest.php` met 3 tests:

| # | Test | Bewijst |
|---|------|---------|
| 1 | `test_authenticated_consumer_receives_pong_payload` | Bearer-PAT met `['snelstart:read']` op Consumer met slug `naschool` → 200 + JSON `{pong: true, consumer: 'naschool'}` |
| 2 | `test_unauthenticated_request_returns_401` | Geen Authorization-header → `assertUnauthorized` (Sanctum default) |
| 3 | `test_abilities_are_surfaced_in_response` | Token met `['snelstart:read', 'mollie:write']` → `abilities.0 === 'snelstart:read'` en `abilities.1 === 'mollie:write'` |

`RefreshDatabase` actief (SQLite `:memory:` per `phpunit.xml`). Naming-convention `test_<scenario>_<expected>` snake_case, conform `NoIndexHeaderTest`. Geen Pest-syntax (`it`/`expect`) — pure PHPUnit per CLAUDE.md.

### Task 3 — SanctumAbilityTest (commit `57afe39`)

`tests/Feature/Api/SanctumAbilityTest.php` met 3 tests:

| # | Test | Status | Bewijst |
|---|------|--------|---------|
| 1 | `test_admin_wildcard_grants_access_to_any_route` | passed | `TokenAbilities::ADMIN` (`'*'`) passeert `auth:sanctum` op `/v1/ping` |
| 2 | `test_token_with_specific_ability_can_reach_ping` | passed | `TokenAbilities::SNELSTART_READ`-token bereikt `/v1/ping` en surfacet via `abilities.0` (refactor-safe koppeling 03-02 ↔ 03-03) |
| 3 | `test_token_without_required_ability_is_rejected` | incomplete | `markTestIncomplete('Wacht op /v1/snelstart/* met ability:snelstart:read in Phase 5b')` — Phase 3 heeft nog geen route met `->middleware('ability:...')` |

`App\Sanctum\TokenAbilities` expliciet geïmporteerd zodat ability-strings refactor-safe blijven: een latere rename van `SNELSTART_READ`-value zou hier direct stuk gaan.

## Verification Results

| Acceptance criterion | Status |
|---|---|
| `app/Http/Controllers/Api/V1/PingController.php` bestaat | OK |
| `grep -c "public function __invoke" PingController.php == 1` | OK |
| `grep -c "extends Controller" PingController.php == 1` | OK |
| `grep -c "auth:sanctum" routes/api.php == 1` | OK |
| `php artisan route:list --path=v1/ping` toont route met `Authenticate:sanctum`-middleware + `api`-group (`throttle:api` impliciet) | OK |
| `tests/Feature/Api/PingTest.php` bestaat | OK |
| `grep -c "use RefreshDatabase" PingTest.php == 1` | OK |
| `grep -c "public function test_" PingTest.php == 3` | OK |
| Geen Pest-syntax in PingTest (`it(` + `expect(` == 0) | OK |
| `php artisan test --compact --filter=PingTest` (3 tests, 6 assertions) | OK (groen, 344ms) |
| `tests/Feature/Api/SanctumAbilityTest.php` bestaat | OK |
| `grep -c "use App.Sanctum.TokenAbilities" SanctumAbilityTest.php == 1` | OK |
| `grep -c "markTestIncomplete" SanctumAbilityTest.php == 1` | OK |
| `grep -c "public function test_" SanctumAbilityTest.php == 3` | OK |
| `php artisan test --compact --filter=SanctumAbilityTest` exit 0 (2 passed + 1 incomplete + 0 failed) | OK (3 tests, 3 assertions, incomplete:1) |
| Pint clean op alle gewijzigde files (`pint --dirty --format agent`) | OK |
| Volledige testsuite groen (regressie-check) | OK (22/22 passed, 46 assertions, 466ms, 1 incomplete) |

## Threat-mitigaties bewezen (uit plan-frontmatter threat_model)

- **T-03-10** (Brute-force op `/v1/ping` zonder geldige PAT) — `routes/api.php` valt onder Laravel's default `api`-middleware-group (verified via `php artisan route:list --path=v1/ping --json` → `middleware: ["api", "Illuminate\\Auth\\Middleware\\Authenticate:sanctum"]`). De `api`-group bevat `throttle:api` (60 req/min/IP default). Effectief: ongeauthenticeerde requests halen rate-limiter vóór auth-check.
- **T-03-11** (Slug-leak via response) — `consumer.slug` is bewust de publieke identifier (PATTERNS.md regel 60 — "URL-safe identifier"). Geen PII in response, geen risk-surface. Test 1 bewijst de slug-shape.
- **T-03-12** (Stack-trace bij Auth-exception) — Laravel default `APP_DEBUG=false`-pad in productie geeft generic 401-response, niet stack-trace. Test 2 bewijst dat unauth-pad een nette `assertUnauthorized`-response retourneert.
- **T-03-13** (Hash-collision bij Sanctum-PAT-lookup) — Sanctum hasht via sha256, 256-bit collision-resistant; out-of-the-box mitigatie zonder Hub-config. Geaccepteerd risico, niet realistisch attack-vector.

## Welke HUB-01-claims zijn nu bewezen

**Volledig bewezen door dit plan:**

- SC-2 ("Consumer kan PAT verkrijgen en authenticeren tegen /v1/ping") — end-to-end aangetoond via `Consumer::factory()->create()->createToken(...)->plainTextToken` → `Authorization: Bearer …` → 200-respond. Tests in PingTest + SanctumAbilityTest.

**Eerder bewezen (referentie):**

- SC-3 + SC-4 query-laag: plan 03-04 (encryption + scoping tests).

**Nog NIET bewezen:**

- SC-1 (`migrate:fresh --seed` levert demo-data) — wacht op plan 03-05 (DatabaseSeeder + `hub:consumer:create`).
- SC-4 route-laag (403/404-response op cross-Consumer Account-toegang) — wacht op Phase 5b's `/v1/snelstart/{path}`-route met `X-Account-Id`-header-flow.
- SC-5 (Snelstart-only vs Mollie-only credential-shape validatie via FormRequest) — wacht op Phase 5b's `POST /v1/connections`.

## Deviations from Plan

Geen functionele afwijkingen. Plan exact volgens specificatie uitgevoerd; geen Rule 1/2/3/4-acties getriggerd.

Twee implementatie-nuances die het noemen waard zijn:

1. **Plan-acceptance vroeg om verificatie via `--json | grep "auth:sanctum"`.** `php artisan route:list --json` print de fully-qualified middleware-class (`Illuminate\\Auth\\Middleware\\Authenticate:sanctum`) i.p.v. de short-alias `auth:sanctum`. Equivalent — de short-alias resolved naar deze FQN. Verificatie blijft semantisch correct: de Sanctum-Authenticate-middleware staat op de route. Geen plan-aanpassing nodig.

2. **`throttle:api`-middleware niet expliciet in `route:list` output** maar wel actief via de Laravel-11+ `api`-route-group default (middleware-stack `["api", "Illuminate\\Auth\\Middleware\\Authenticate:sanctum"]` — `api` is de group-alias die `throttle:api` + `SubstituteBindings` insluit). T-03-10-mitigatie blijft van kracht.

## Auth Gates

Geen. Plan vereist geen externe authenticatie. Sanctum-PAT-uitgifte gebeurt in-process via `Consumer::factory()->create()->createToken(...)`.

## Deferred Issues

Geen nieuwe out-of-scope items. Pre-existing `deferred-items.md`-entry (Pint formatting-drift op vendor-published `webhook_calls`-migrations) blijft staan en is niet door dit plan veroorzaakt.

## Known Stubs

Eén intentionele placeholder:

- `tests/Feature/Api/SanctumAbilityTest::test_token_without_required_ability_is_rejected` — `markTestIncomplete('Wacht op /v1/snelstart/* met ability:snelstart:read in Phase 5b')`. Niet een stub die de plan-goal blokkeert; expliciet onderdeel van de plan-action (Task 3 behavior #2). Wordt scherp ingevuld in Phase 5b zodra een route met `->middleware('ability:snelstart:read')`-check bestaat. Suite blijft groen (incomplete ≠ failed in `--compact`-output).

Geen onbedoelde stubs in productiecode. PingController returnt echte data (`$request->user()->slug`, real `currentAccessToken()->abilities`) — geen hardgecodeerde placeholder-strings.

## Continuation

Vervolg-werk in deze fase:

- **Plan 03-05** — `hub:consumer:create` artisan-command + `DatabaseSeeder` demo-data + HubConsumerCreateTest + Phase 3 acceptance-run (verifieert SC-1; sluit de fase af).

Phase 3 is na 03-05 volledig afgerond. Daarna parallel:

- **Phase 4** — Mollie Connect OAuth-broker (depends on Phase 2 + Phase 3).
- **Phase 5b** — Snelstart-pass-through API (depends on Phase 3 only; parallelliseerbaar met Phase 4). Plan 03-03's PingController-pattern is de copy-target voor `Snelstart\PassthroughController`; `SanctumAbilityTest`'s placeholder krijgt daar zijn invulling.

## Self-Check: PASSED

**Files exist:**
- FOUND: app/Http/Controllers/Api/V1/PingController.php
- FOUND: routes/api.php (modified — auth:sanctum-group + PingController-binding verified via `php artisan route:list --path=v1/ping`)
- FOUND: tests/Feature/Api/PingTest.php
- FOUND: tests/Feature/Api/SanctumAbilityTest.php

**Commits exist (via `git log --oneline | grep`):**
- FOUND: 948dcb6 (Task 1: PingController + route)
- FOUND: b8b125e (Task 2: PingTest)
- FOUND: 57afe39 (Task 3: SanctumAbilityTest)
