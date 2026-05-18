---
phase: 03-hub-skeleton
plan: 02
subsystem: hub-auth-config
tags:
  - laravel
  - sanctum
  - auth
  - routing
  - config
requirements:
  - HUB-01
dependency-graph:
  requires:
    - "03-01: App\\Models\\Consumer met HasApiTokens-trait (target van consumers-provider)"
  provides:
    - "config('auth.guards.sanctum') = ['driver' => 'sanctum', 'provider' => 'consumers']"
    - "config('auth.providers.consumers') = ['driver' => 'eloquent', 'model' => Consumer::class]"
    - "routes/api.php skeleton — auto-loaded onder /v1/*-prefix via bootstrap/app.php"
    - "App\\Sanctum\\TokenAbilities — 6 public const + all()-helper voor reuse"
  affects:
    - "Phase 3 plan 03-03 (PingController consumeert auth:sanctum + TokenAbilities voor abilities-payload)"
    - "Phase 3 plan 03-05 (hub:consumer:create gebruikt TokenAbilities::all() als --abilities default)"
    - "Phase 4 (MollieConnectOAuthFlow registreert routes onder /v1/*)"
    - "Phase 5a (/v1/mollie/* pass-through valt onder dezelfde middleware-stack)"
    - "Phase 5b (pass-through ability-checks via TokenAbilities::SNELSTART_READ etc.)"
tech-stack:
  added: []
  patterns:
    - "Laravel 11+/13 bootstrap/app.php met withRouting(api:, apiPrefix:) — geen RouteServiceProvider meer"
    - "final class met public const voor enum-alternatief (Sanctum-abilities zijn ruwe strings)"
    - "Auth-config met meerdere guards/providers — web/users blijft naast sanctum/consumers"
key-files:
  created:
    - "app/Sanctum/TokenAbilities.php"
    - "routes/api.php"
  modified:
    - "config/auth.php"
    - "bootstrap/app.php"
decisions:
  - "final class met public const i.p.v. enum TokenAbility: string — Sanctum vergelijkt ruwe strings via tokenCan('snelstart:read'); enum zou bij elke check ->value toevoegen, en er staan nog geen enums in repo"
  - "Geen EnsureFrontendRequestsAreStateful — Hub is API-only Bearer-PAT, geen SPA-cookies (PATTERNS.md regel 433)"
  - "Web/users-guard+provider blijft naast Sanctum/consumers — User-model is voor Filament admin in Phase 9 (CONTEXT.md Claude's Discretion regel 277)"
metrics:
  duration_minutes: 4
  tasks_completed: 3
  tasks_total: 3
  files_created: 2
  files_modified: 2
  commits: 3
  completed_at: "2026-05-14"
---

# Phase 3 Plan 02: Sanctum-config + /v1-routing Summary

Sanctum-guard `sanctum` + provider `consumers` gekoppeld aan `App\Models\Consumer`; `routes/api.php`-skeleton geregistreerd onder `/v1/*` via `bootstrap/app.php`; `App\Sanctum\TokenAbilities` levert de zes canonical ability-strings voor latere plans en fases.

## What Was Built

### Task 1 — TokenAbilities constants-class (commit `d0b4e32`)

`app/Sanctum/TokenAbilities.php` als `final class` met zes `public const`:

| Constant | Value | Doel |
|---|---|---|
| `SNELSTART_READ` | `snelstart:read` | Read-only Snelstart pass-through (Phase 5b) |
| `SNELSTART_WRITE` | `snelstart:write` | Write Snelstart pass-through (Phase 5b) |
| `MOLLIE_READ` | `mollie:read` | Read-only Mollie pass-through (Phase 5a) |
| `MOLLIE_WRITE` | `mollie:write` | Write Mollie pass-through (Phase 5a) |
| `CONSUMER_MANAGE_ACCOUNTS` | `consumer:manage-accounts` | `POST /v1/accounts` + `POST /v1/connections` (Phase 5b) |
| `ADMIN` | `*` | Sanctum-wildcard — admin-token |

`all(): array` levert alle zes als `list<string>` zodat plan 03-05 ze als default kan gebruiken voor `hub:consumer:create --abilities`.

### Task 2 — Sanctum-guard + consumers-provider (commit `ade9296`)

`config/auth.php` uitgebreid, niet vervangen:

- `use App\Models\Consumer;` import toegevoegd boven (naast `App\Models\User`).
- `guards.sanctum` toegevoegd naast `guards.web` — `['driver' => 'sanctum', 'provider' => 'consumers']`.
- `providers.consumers` toegevoegd naast `providers.users` — `['driver' => 'eloquent', 'model' => Consumer::class]`.
- `defaults`, `passwords`, `password_timeout` ongewijzigd; `users`-provider en `web`-guard intact zodat User-pad voor Filament (Phase 9) niet regredieert.

### Task 3 — bootstrap/app.php + routes/api.php skeleton (commit `92a747b`)

`bootstrap/app.php` — twee keys toegevoegd aan `withRouting()` (volgorde: web → api → apiPrefix → commands → health):

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    apiPrefix: 'v1',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
)
```

`SetNoIndexHeaders::class`-middleware-append blijft staan (één `use` + één `append` = 2 hits). Geen `EnsureFrontendRequestsAreStateful` — Hub is API-only Bearer-PAT.

`routes/api.php` als skeleton-file met header-comment die naar volgende plans wijst (`03-03` ping, Phase 4 oauth-routes, Phase 5a/5b pass-throughs). Pint heeft de pre-emptieve `use Route;`-import gestript omdat de file nog geen routes definieert — plan 03-03 voegt 'm weer toe wanneer `Route::get('/ping', ...)` landt.

## Verification Results

| Acceptance criterion | Status |
|---|---|
| `App\Sanctum\TokenAbilities::all()` retourneert 6 elementen | OK (`abilities:ok`) |
| `TokenAbilities::SNELSTART_READ === 'snelstart:read'` | OK (`snel-read:ok`) |
| `TokenAbilities::ADMIN === '*'` | OK (`admin:ok`) |
| `grep -c "public const" app/Sanctum/TokenAbilities.php == 6` | OK |
| `grep -c "final class TokenAbilities" == 1` | OK |
| `config('auth.guards.sanctum.driver') === 'sanctum'` | OK (`guard:ok`) |
| `config('auth.providers.consumers.model') === Consumer::class` | OK (`provider:ok`) |
| `config('auth.guards.web.driver') === 'session'` (web-guard intact) | OK (`web-intact:ok`) |
| `bootstrap/app.php` heeft `apiPrefix: 'v1'` | OK (1 hit) |
| `bootstrap/app.php` heeft `api: __DIR__` | OK (1 hit) |
| `routes/api.php` bestaat | OK |
| `bootstrap/app.php` heeft `SetNoIndexHeaders` 2× (use + append) | OK |
| `php artisan route:list --except-vendor` slaagt, toont `/` + `/up` | OK |
| Pint clean op alle gewijzigde files (`pint --dirty` + `pint --test bootstrap/app.php routes/api.php`) | OK |
| Volledige testsuite groen (regressie-check) | OK (5/5, 11 assertions, 266ms) |

## Threat-mitigaties bewezen (uit plan-frontmatter threat_model)

- **T-03-06** (Sanctum-PAT brute-force) — `routes/api.php` valt onder Laravel 11+ default `api`-middleware-group met `throttle:api` (60 req/min/IP); verificatie wordt scherper in plan 03-03 zodra `php artisan route:list` daadwerkelijk een `/v1`-route toont.
- **T-03-07** (PAT-replay na lek) — Sanctum hasht plain-token via `hash('sha256', $plainToken)` voordat het in `personal_access_tokens.token` landt (`vendor/laravel/sanctum/src/HasApiTokens.php:103`). DB-compromise levert geen plain-token op. Geen Hub-config nodig — out-of-the-box mitigation.
- **T-03-08** (Misconfigured provider) — `sanctum`-guard verwijst expliciet naar `consumers`-provider, `consumers`-provider naar `Consumer::class`. Bewezen via `config('auth.providers.consumers.model') === App\Models\Consumer::class`.
- **T-03-09** (Cookie-injection via stateful-middleware) — `EnsureFrontendRequestsAreStateful` bewust NIET toegevoegd. Disposition: accept.

## Deviations from Plan

Eén nuance:

1. **Pint heeft de `use Route;`-import uit `routes/api.php` gestript.** Plan-action toonde een skeleton met `use Illuminate\Support\Facades\Route;` boven de header-comment, maar omdat de file nog geen `Route::get(...)`-call doet, classificeert Pint dat als `no_unused_imports`. Effect: file is nu een puur comment-skeleton. Plan 03-03 voegt de import weer toe op het moment dat de `/ping`-route landt — dit is de Pint-conforme volgorde en heeft geen functioneel effect op `bootstrap/app.php`'s `api:`-pad of route-loading. Geen Rule 1/2/3 actie nodig.

Geen verdere afwijkingen. Plan exact volgens specificatie uitgevoerd; geen architecturele wijzigingen (Rule 4 niet getriggerd).

## Auth Gates

Geen. Plan vereist geen externe authenticatie.

## Deferred Issues

Geen nieuwe out-of-scope items. Pre-existing `deferred-items.md`-entry (Pint formatting-drift op vendor-published `webhook_calls`-migrations) blijft staan en is niet door dit plan veroorzaakt.

## Known Stubs

`routes/api.php` is intentioneel een comment-only skeleton — niet een stub. De `objective` van dit plan is expliciet "geen routes, geen controllers, geen tests in dit plan — wel een `routes/api.php`-skeleton bestand zodat `bootstrap/app.php`'s `api:`-pad bestaat". Plan 03-03 vult `/ping`; dat is de directe opvolger.

## Continuation

Vervolg-werk in deze fase:

- **Plan 03-03** — `routes/api.php` `/v1/ping` + `PingController` + PingTest + SanctumAbilityTest (gebruikt `auth:sanctum`-middleware uit deze plan-config + `TokenAbilities` uit Task 1).
- **Plan 03-04** — `ConnectionEncryptionTest` (DB-bypass) + `ConsumerAccountScopingTest`.
- **Plan 03-05** — `hub:consumer:create` artisan-command (gebruikt `TokenAbilities::all()` als `--abilities` default) + `DatabaseSeeder` demo-data.

## Self-Check: PASSED

**Files exist:**
- FOUND: app/Sanctum/TokenAbilities.php
- FOUND: routes/api.php
- FOUND: config/auth.php (modified — sanctum-guard + consumers-provider verified via `php artisan config:show auth.providers.consumers`)
- FOUND: bootstrap/app.php (modified — apiPrefix verified via grep)

**Commits exist (via `git log --oneline --all | grep`):**
- FOUND: d0b4e32 (Task 1: TokenAbilities)
- FOUND: ade9296 (Task 2: Sanctum-guard + consumers-provider)
- FOUND: 92a747b (Task 3: bootstrap/app.php api: + apiPrefix + routes/api.php skeleton)
