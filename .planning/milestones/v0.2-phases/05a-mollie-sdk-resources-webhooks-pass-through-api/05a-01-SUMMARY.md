---
phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
plan: 01
subsystem: api
tags:
  - laravel
  - mollie
  - middleware
  - error-mapping
  - encryption
  - pass-through
  - phpunit

# Dependency graph
requires:
  - phase: 04-mollie-oauth-broker
    provides: "MollieConnectionContext (scoped), HubMollieCredentialResolver-binding, MollieConnectOAuthFlow"
  - phase: 03-hub-skeleton
    provides: "Consumer/Account/Connection-modellen, Sanctum-PAT-auth, TokenAbilities (MOLLIE_READ/WRITE/ADMIN)"
  - phase: 05b-snelstart-pass-through-api
    provides: "pass_through_calls + query_keys-kolom, ResolveSnelstartAccount/UpstreamErrorMapper/HeaderForwarder als mirror-templates"
provides:
  - "AbstractMolliePassThroughController (ability-guard + 415-guard + audit-write + exception-mapping)"
  - "ResolveMollieAccount-middleware (alias resolve.mollie.account)"
  - "MollieUpstreamErrorMapper (D-13 mapping-tabel, 401/403 cloak naar 502)"
  - "MollieHeaderForwarder (beperkte whitelist: Accept + Content-Type)"
  - "Consumer.webhook_callback_url + encrypted webhook_callback_secret kolommen (D-09)"
  - "MOLLIE_WEBHOOK_SECRET env + services.mollie.webhook_secret config-key"
  - "Tests\\Concerns\\BindsMollieConnectionContext trait"
affects:
  - 05a-02-mollie-webhook-ingress-fan-out
  - 05a-03-mollie-payments-customers-paymentmethods
  - 05a-04-mollie-refunds-mandates-subscriptions
  - 05a-05-mollie-paymentlinks-scramble-acceptance

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Abstract pass-through controller met handle(Request, $endpoint, callable $sdkCall): Response — concrete subclass levert alleen de SDK-call, alle cross-cutting concerns (ability, 415, mapping, audit) leven in de base"
    - "Tenant-resolutie via MollieConnectionContext::set() ipv container-rebind — werkt via scoped binding + constructor-injection in HubMollieCredentialResolver (cleaner dan Snelstart's app->instance()-pad)"
    - "Eloquent 'encrypted' cast voor consumer-level secrets (zelfde pattern als Connection.access_token/refresh_token/client_key)"

key-files:
  created:
    - app/Http/Middleware/ResolveMollieAccount.php
    - app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php
    - app/Support/Mollie/MollieUpstreamErrorMapper.php
    - app/Support/Mollie/MollieHeaderForwarder.php
    - database/migrations/2026_05_16_000001_add_webhook_callback_to_consumers_table.php
    - tests/Concerns/BindsMollieConnectionContext.php
    - tests/Unit/Support/Mollie/MollieUpstreamErrorMapperTest.php
    - tests/Feature/Api/V1/Mollie/MolliePassThroughResolutionTest.php
  modified:
    - app/Models/Consumer.php
    - database/factories/ConsumerFactory.php
    - bootstrap/app.php
    - config/services.php
    - .env.example

key-decisions:
  - "D-03 verifieer-punt bevestigd: Mollie::client() bouwt elke call een verse MollieApiClient (vendor/emeq/mollie-api/src/Mollie.php regel 60: new MollieApiClient()), dus geen forgetInstance(Mollie::class) nodig — anders dan Snelstart-middleware-pad"
  - "Emeq\\MollieApi\\Exceptions\\RateLimitException exposeert (nog) geen retry-after-getter — mapper laat de Retry-After-header leeg; Mollie-docs prescribeert client-default 60s backoff"
  - "Emeq\\MollieApi\\Exceptions\\ValidationException heeft ::getField(); mapper forward't dat in response-body als 'field'-key"
  - "Migration filename volgt 2026_05_16_000001_-pattern (zelfde datum-aware naming als 5b's 2026_05_15_*-trio)"

patterns-established:
  - "AbstractMolliePassThroughController-base: future resource-controllers (plans 05a-03..05a-05) extenden deze en leveren alleen het endpoint-template + SDK-call-closure"
  - "Tenant-resolutie via scoped binding: MollieConnectionContext::set() in middleware → HubMollieCredentialResolver::resolve() leest current() → fresh MollieApiClient per Mollie::client()-call"

requirements-completed: [HUB-03]

# Metrics
duration: ~9min
completed: 2026-05-14
---

# Phase 5a Plan 01: Mollie Pass-Through Foundation Summary

**AbstractMolliePassThroughController-base + ResolveMollieAccount-middleware + MollieUpstreamErrorMapper + encrypted Consumer.webhook_callback_secret — cross-cutting infrastructure waar plans 05a-02..05a-05 op bouwen.**

## Performance

- **Duration:** ~9 min (incl. ~3 min recovery van worktree-path-misuse — zie Issues Encountered)
- **Started:** 2026-05-14T22:05:14Z
- **Completed:** 2026-05-14T22:14:31Z
- **Tasks:** 3 (TDD, 6 commits total)
- **Files modified:** 13 (8 created, 5 modified)

## Accomplishments

- MollieUpstreamErrorMapper implementeert D-13 mapping-tabel — 401/403 worden naar 502 + `mollie_auth_failed` cloaked (info-disclosure-mitigatie threat T-05a-06), Validation→422, NotFound→404, RateLimit→429, Server→502 + `mollie_5xx`, base/onbekend→502 + `mollie_unknown`. 7 unit-tests groen.
- ResolveMollieAccount-middleware bindt via `MollieConnectionContext::set()` (D-03) ipv container-rebind; mismatch op consumer/account/connection retourneert 400/404 met info-disclosure-veilige error-keys. 7 feature-tests groen, incl. cross-Consumer→404 (NIET 403, threat T-05a-01).
- AbstractMolliePassThroughController consolideert ability-guard (D-14), 415-guard (D-05), exception-mapping (D-13), en audit-write naar `pass_through_calls` met de drie 5b-CRITICAL-fixes (NULL fingerprint bij lege body, path zonder query-string, query_keys-kolom).
- `consumers.webhook_callback_url` + `consumers.webhook_callback_secret` (Eloquent `encrypted` cast, D-09) — webhook fan-out in plan 05a-02 leest deze.
- Volledige suite: **143 passed / 1 incomplete / 0 failed** (pre-existing Phase 3 placeholder onveranderd). Geen regressies.

## Task Commits

1. **Task 1: MollieUpstreamErrorMapper + MollieHeaderForwarder + 7 unit-tests**
   - `5f754e9` test (RED — 7 falende cases)
   - `72038ec` feat (GREEN — mapper + forwarder)
2. **Task 2: Consumer webhook-callback-velden + migration + factory**
   - `4d4ab6b` feat (migration + Consumer cast + ConsumerFactory::withWebhookCallback)
3. **Task 3: ResolveMollieAccount + AbstractMolliePassThroughController + alias + trait**
   - `9bb5e47` chore (.env.example + config/services.php — webhook_secret env-key)
   - `1cbaa8c` test (RED — 7 falende resolution-cases)
   - `4797cfe` feat (GREEN — middleware + abstract base + bootstrap-alias)

## Files Created/Modified

### Created
- `app/Support/Mollie/MollieUpstreamErrorMapper.php` — D-13 SDK-exception → Hub-response mapper
- `app/Support/Mollie/MollieHeaderForwarder.php` — whitelist Accept + Content-Type (geen If-Match, geen Idempotency-Key)
- `app/Http/Middleware/ResolveMollieAccount.php` — tenant-resolutie via MollieConnectionContext::set()
- `app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php` — base voor 05a-03..05a-05 resource-controllers
- `database/migrations/2026_05_16_000001_add_webhook_callback_to_consumers_table.php` — Consumer.webhook_callback_url + secret
- `tests/Concerns/BindsMollieConnectionContext.php` — test-trait
- `tests/Unit/Support/Mollie/MollieUpstreamErrorMapperTest.php` — 7 cases
- `tests/Feature/Api/V1/Mollie/MolliePassThroughResolutionTest.php` — 7 cases

### Modified
- `app/Models/Consumer.php` — fillable + casts() override met `webhook_callback_secret => 'encrypted'`
- `database/factories/ConsumerFactory.php` — `withWebhookCallback()` state
- `bootstrap/app.php` — `'resolve.mollie.account' => ResolveMollieAccount::class` alias
- `config/services.php` — `services.mollie.webhook_secret` (env `MOLLIE_WEBHOOK_SECRET`)
- `.env.example` — `MOLLIE_WEBHOOK_SECRET=` blok

## Decisions Made

- D-03 verifieer-punt: `Mollie::client()` is per-call instantiation in `vendor/emeq/mollie-api/src/Mollie.php` regel 60. De `Mollie::class`-singleton-binding houdt enkel de resolver + config vast; elke `client()`-call bouwt een verse `MollieApiClient` met fresh credentials. Daarom **GEEN** `app()->forgetInstance(Mollie::class)` in `ResolveMollieAccount` — anders dan Snelstart-middleware. Cleaner pad.
- `Emeq\MollieApi\Exceptions\RateLimitException` heeft (nog) geen `getRetryAfter()`-getter (zie `vendor/emeq/mollie-api/src/Exceptions/RateLimitException.php` — leeg class-body). Mapper laat `Retry-After`-header leeg; Mollie-docs prescribeert default 60s backoff. Followup: SDK kan de getter alsnog toevoegen door Mollie's `TooManyRequestsException::getResponseBody()` te parsen — backlog.
- `Emeq\MollieApi\Exceptions\ValidationException` heeft `::getField()` (geverifieerd in vendor); mapper forward't dat in body als `'field'`.

## Deviations from Plan

None - plan executed exactly as written (alle plan-action-blocks letterlijk gevolgd, behalve één Pint-fix die operator-spacing in AbstractMolliePassThroughController bijwerkte).

## Issues Encountered

- **Worktree-path-misuse (recovered)**: Eerste commit-poging landde per ongeluk op `chore/v02-roadmap-split-and-scramble` in plaats van de worktree-branch `worktree-agent-ac3ef70498997c27c`. Oorzaak: `Bash`-tool reset cwd tussen calls, en `cd /Users/yusufkaracaburun/Sites/localhost/emeq-hub` in een commando opende een verkeerde checkout (de niet-worktree). Write-tool gebruikte absolute paden naar diezelfde main checkout. Hersteld via `git reset --soft HEAD~1` op het verkeerde-branch-commit (clean undo, geen `--hard`) + manuele file-removals + opnieuw aanmaken op de juiste worktree-path. Tijdens recovery werd duidelijk dat de worktree geen `vendor/`-dir had — composer-install vereist (3 min). Vanaf dat punt alleen worktree-relative pad gebruikt; geen verdere drift.

## User Setup Required

None - geen externe service-configuratie nodig. `MOLLIE_WEBHOOK_SECRET` env-key staat in `.env.example`; user vult 'm pas in als plan 05a-02 webhook-ingress aanzet.

## Known Stubs

- Geen stub-rendering in 5a-01 (foundation-plan — geen UI/data-bindings). Pass-through-pad blijft "leeg" tot 05a-03 resource-controllers landen.

## Next Phase Readiness

- **Plan 05a-02 (webhook-ingress + fan-out)** kan starten:
  - `consumers.webhook_callback_url` + encrypted `webhook_callback_secret` zijn klaar
  - `MOLLIE_WEBHOOK_SECRET` env + `config('services.mollie.webhook_secret')` zijn klaar
  - Spatie `webhook_calls` tabel staat al uit Phase 0
- **Plan 05a-03..05a-05 (resources)** kunnen starten:
  - `AbstractMolliePassThroughController::handle()` is klaar — concrete controllers leveren alleen endpoint-template + SDK-callable
  - `ResolveMollieAccount`-middleware werkt; routes binden met `->middleware('resolve.mollie.account')`
  - `MollieUpstreamErrorMapper` doet automatisch het exception-pad
  - `BindsMollieConnectionContext`-trait beschikbaar voor unit-stijl tests
- **Geen blockers** — D-06 (Idempotency-Key generator-binding) komt mee in plan 05a-03 wanneer de eerste write-route (POST `/v1/mollie/payments`) landt; out-of-scope voor 05a-01-foundation.

## Self-Check: PASSED

- All 8 created/modified files exist on disk (verified via `test -f`).
- All 6 task-commits exist in `git log` (5f754e9, 72038ec, 4d4ab6b, 9bb5e47, 1cbaa8c, 4797cfe).
- Full PHPUnit suite groen: 143 passed / 1 incomplete (pre-existing) / 0 failed.
- Pint clean op alle nieuwe/gewijzigde files.

---
*Phase: 05a-mollie-sdk-resources-webhooks-pass-through-api*
*Plan: 01*
*Completed: 2026-05-14*
