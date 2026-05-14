---
phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
plan: 02
subsystem: webhooks
tags:
  - laravel
  - mollie
  - webhooks
  - signature-verify
  - spatie-webhook-server
  - spatie-webhook-client
  - anti-spoofing
  - phpunit

# Dependency graph
requires:
  - phase: 05a-01
    provides: "Consumer.webhook_callback_url + encrypted webhook_callback_secret, MOLLIE_WEBHOOK_SECRET env-key, ConsumerFactory::withWebhookCallback state, MollieConnectionContext (bound via Plan 05a-01 in ResolveMollieAccount)"
  - phase: 02-sdk-mollie
    provides: "Emeq\\MollieApi\\Webhooks\\MollieWebhookSignature::verify/sign helpers"
  - phase: 00-skeleton
    provides: "Spatie webhook_calls migration (incoming audit table)"
provides:
  - "POST /webhooks/mollie/{connection_id} ingress-route (publiek; signature is de auth)"
  - "MollieWebhookController met de 6-stappen D-08-flow (signature -> connection-lookup -> payload-id -> anti-spoofing-fetch -> audit -> fan-out)"
  - "ForwardMollieWebhookToConsumer queueable job (Spatie\\WebhookServer\\WebhookCall fan-out met HMAC-signed payload)"
  - "config/webhook-server.php + config/webhook-client.php (Spatie defaults gepublished)"
  - "routes/webhooks.php geregistreerd via bootstrap/app.php withRouting()->then()"
  - "11 webhook feature-tests met MollieApiClient::fake() + ThrowingMollieApiClient stub"
affects:
  - 05a-03-mollie-payments-customers-paymentmethods
  - 05a-04-mollie-refunds-mandates-subscriptions
  - 05a-05-mollie-paymentlinks-scramble-acceptance

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Webhook-ingress flow per D-08: signature -> connection-lookup -> payload-validation -> anti-spoofing-fetch -> incoming-audit -> outgoing-fan-out -> 202"
    - "ForwardMollieWebhookToConsumer.mollieConnection rename: vermijdt property-collision met Illuminate\\Bus\\Queueable.connection trait-property"
    - "Test-side: \\Emeq\\MollieApi\\Mollie wrapper mock via $this->createMock + $this->app->instance, met MollieApiClient::fake() (success-pad) of ThrowingMollieApiClient-subclass (exception-pad) als concrete client() return"

key-files:
  created:
    - app/Http/Controllers/Webhooks/MollieWebhookController.php
    - app/Jobs/ForwardMollieWebhookToConsumer.php
    - routes/webhooks.php
    - config/webhook-server.php
    - config/webhook-client.php
    - tests/Feature/Webhooks/MollieWebhookSignatureTest.php
    - tests/Feature/Webhooks/MollieWebhookFanOutTest.php
    - tests/Feature/Webhooks/MollieWebhookAntiSpoofingTest.php
    - tests/Feature/Webhooks/ThrowingMollieApiClient.php
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-02-PREFLIGHT.md
  modified:
    - bootstrap/app.php

key-decisions:
  - "Pre-flight V2 bevestigd: Mollie::class IS singleton (vendor/emeq/mollie-api/src/MollieServiceProvider.php:31), maar client() bouwt elke call een verse MollieApiClient (vendor/emeq/mollie-api/src/Mollie.php:60). Geen forgetInstance(Mollie::class) nodig na MollieConnectionContext::set() in de controller — zelfde redenering als ResolveMollieAccount-middleware (Plan 05a-01)."
  - "Pre-flight V1 bevestigd: Emeq\\MollieApi\\Exceptions\\RateLimitException heeft geen retry-after getter — niet relevant voor 05a-02 (mapper raakt niets aan)."
  - "Pre-flight V3 bevestigd: alle Mollie-webhook-payloads voor v0.2 dragen een Payment-id (tr_*); anti-spoofing-fetch via payments->get($id) blijft correct. v0.3+ moet resource-type-detectie via id-prefix toevoegen indien Mollie's next-gen webhooks-API subscription-level events met sub_-prefix gaat sturen — gedocumenteerd in MollieWebhookController-docblock."
  - "Spatie webhook-server + webhook-client waren al beide in composer.json (geen require-stap nodig); alleen vendor:publish van de configs."
  - "Bootstrap-routing via withRouting()->then()-callback (Route::middleware('api')->group(base_path('routes/webhooks.php'))) — webhook-routes erven throttle:api maar GEEN auth:sanctum, conform D-07."
  - "Property-collision Rule-1-fix: ForwardMollieWebhookToConsumer.connection -> mollieConnection (Illuminate\\Bus\\Queueable trait definieert al public \$connection voor queue-connection-name; PHP 8.4 fataal bij different-definition in composition)."

patterns-established:
  - "Webhook-test mock-strategie: bind Mollie-wrapper via PHPUnit-mock op container; client() return is óf een MollieApiClient::fake() (voor success-pad — gebruikt MockResponse::ok) óf een test-only MollieApiClient-subclass met overridden __get (voor exception-pad). Hergebruikbaar voor toekomstige tests die Mollie-resources fakeen zonder echte HTTP-call."
  - "Audit-failed helper als private method op de controller (auditFailedWebhook(Request, string \$exception)) — single point voor alle failure-paden zodat audit-row-shape consistent blijft."

requirements-completed: [MOLL-04, HUB-03]

# Metrics
duration: ~11min
completed: 2026-05-14
---

# Phase 5a Plan 02: Mollie Webhook Ingress + Fan-out Summary

**POST /webhooks/mollie/{connection_id} met 6-stappen D-08-flow (signature → connection → payload-id → anti-spoofing → incoming-audit → outgoing-fan-out → 202) + ForwardMollieWebhookToConsumer queueable job naar consumers.webhook_callback_url via Spatie webhook-server.**

## Performance

- **Duration:** ~11 min (incl. composer-install in lege worktree-vendor + .env-bootstrap voor APP_KEY)
- **Started:** 2026-05-14T22:20:14Z
- **Completed:** 2026-05-14T22:31:25Z
- **Tasks:** 4 (Task 0 pre-flight + Task 1 setup + Task 2 controller/job + Task 3 tests)
- **Commits:** 5 (1 pre-flight + 1 setup + 1 controller/job + 1 fix + 1 tests)
- **Files modified:** 11 (10 created, 1 modified)

## Accomplishments

- **MollieWebhookController** implementeert de complete D-08 flow met info-disclosure-veilige error-codes (`invalid_signature`, `missing_signature`, `connection_gone`, `missing_id`, `resource_ownership_failed`). Failure-paden schrijven audit-rij met `exception`-veld; success-pad schrijft inkomende audit + dispatcht fan-out + retourneert 202.
- **ForwardMollieWebhookToConsumer** dispatcht Spatie's `WebhookServer\WebhookCall` met de Consumer-callback-URL en encrypted-secret; ontbrekende callback-config → silent skip (geen retry-druk op de queue voor consumers die nog niet geconfigureerd zijn).
- **Anti-spoofing-fetch** (D-08 stap 3) via `Mollie::client()->payments->get($payload['id'])` met `MollieConnectionContext::set($connection)` als pre-step — geverifieerd door 2 tests die `NotFoundException` resp. `AuthenticationException` gooien en de webhook tot 400 mappen + `Bus::assertNotDispatched`.
- **routes/webhooks.php** als nieuwe top-level publieke route-file, geregistreerd via `withRouting()->then()`-callback binnen de `'api'`-middleware-group (krijgt `throttle:api` automatisch, géén `auth:sanctum`).
- **Spatie webhook-server + webhook-client configs** gepublished — defaults blijven onveranderd (`signing_secret` blijft op env-default, `webhook_job` op `CallWebhookJob`).
- **11 feature-tests groen** verdeeld over 3 files: 6 signature-paden, 3 fan-out-paden, 2 anti-spoofing-paden. **Volledige suite: 154 passed / 1 incomplete (pre-existing Phase 3 placeholder) / 0 failed.**

## Task Commits

1. **Task 0 — Pre-flight verifie**
   - `6a46680` docs (PREFLIGHT.md met drie vendor-API verifie-uitkomsten)
2. **Task 1 — composer-deps verifie + Spatie configs + routes/webhooks.php + bootstrap**
   - `02c0b3c` feat (routes/webhooks.php + 2 Spatie configs + bootstrap-then-callback)
3. **Task 2 — Controller + Job**
   - `908070d` feat (MollieWebhookController + ForwardMollieWebhookToConsumer)
4. **Task 3 — Rule 1 fix + TDD-tests**
   - `49b7090` fix (rename Job::connection → Job::mollieConnection — trait-collision)
   - `6cf74b9` test (3 test-files + ThrowingMollieApiClient stub, 11 cases)

## Files Created/Modified

### Created
- `app/Http/Controllers/Webhooks/MollieWebhookController.php` — 6-stappen D-08 flow + private `auditFailedWebhook` helper
- `app/Jobs/ForwardMollieWebhookToConsumer.php` — ShouldQueue + Spatie webhook-server fan-out
- `routes/webhooks.php` — POST `/webhooks/mollie/{connection_id}` (publiek, signature is auth)
- `config/webhook-server.php` — Spatie defaults gepublished
- `config/webhook-client.php` — Spatie defaults gepublished
- `tests/Feature/Webhooks/MollieWebhookSignatureTest.php` — 6 signature-/lookup-paden
- `tests/Feature/Webhooks/MollieWebhookFanOutTest.php` — 3 fan-out-paden (controller-dispatch + job-handle + silent-skip)
- `tests/Feature/Webhooks/MollieWebhookAntiSpoofingTest.php` — 2 anti-spoofing-paden (404 + auth-fail)
- `tests/Feature/Webhooks/ThrowingMollieApiClient.php` — test-only `MollieApiClient`-subclass voor exception-paden via overridden `__get('payments')`
- `.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-02-PREFLIGHT.md` — vendor-API verifie

### Modified
- `bootstrap/app.php` — `withRouting()->then()`-callback voegt `routes/webhooks.php` toe binnen de `'api'`-middleware-group; nieuwe import `Illuminate\Support\Facades\Route`

## Decisions Made

- **D-03/V2 verifie-uitkomst (uit Plan 05a-01 al bevestigd, opnieuw geverifieerd in 05a-02 pre-flight):** `Mollie::class` is singleton, maar `client()` bouwt elke call een verse `MollieApiClient` (vendor/emeq/mollie-api/src/Mollie.php:60: `$client = new MollieApiClient();`). De `MollieConnectionContext::set()` mid-controller is voldoende; **geen `forgetInstance(Mollie::class)` nodig**.
- **Pre-flight V1 RateLimitException retry-after**: niet exposed in SDK (`final class RateLimitException extends MollieException {}` leeg) noch in vendor `TooManyRequestsException`. Niet relevant voor 05a-02 (raakt geen mapper); voor 05a-01's mapper is `Retry-After`-header leeg-pad al gepicked.
- **Pre-flight V3 webhook-payload-id-prefix**: alle Mollie-webhooks dragen voor v0.2 een Payment-id (`tr_*`) — anti-spoofing-fetch via `payments->get` blijft correct. Documenteer als v0.2-aanname in `MollieWebhookController`-docblock voor v0.3+ uitbreiding (resource-type-detectie via id-prefix: `tr_`/`sub_`/`re_`).
- **Property-rename Job.connection → Job.mollieConnection (Rule 1 auto-fix)**: `Illuminate\Bus\Queueable` definieert al `public $connection` (voor de queue-connection-name). PHP 8.4 weigert composition met "different definition is considered incompatible". Conventionele oplossing: hernoem de domein-property; geen functioneel verschil omdat dispatch positional gaat (`::dispatch($connection, $payload)`).
- **No-`composer require`-stap nodig**: `spatie/laravel-webhook-server@^3.10` en `spatie/laravel-webhook-client@^3.6` waren beide al in composer.json. Alleen `vendor:publish` van configs.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] ForwardMollieWebhookToConsumer property-collision met Queueable trait**
- **Found during:** Task 3 test-run
- **Issue:** PHP-fatal `App\Jobs\ForwardMollieWebhookToConsumer and Illuminate\Bus\Queueable define the same property ($connection) in the composition`. `Queueable` definieert `public $connection;` voor de queue-name; mijn job had `public Connection $connection;` met type-mismatch.
- **Fix:** Hernoem `public Connection $connection` → `public Connection $mollieConnection` op het job-model én op de test-assertion in MollieWebhookFanOutTest (`$job->mollieConnection->id`). Controller-dispatch is positional dus geen wijziging daar.
- **Files modified:** `app/Jobs/ForwardMollieWebhookToConsumer.php`, `tests/Feature/Webhooks/MollieWebhookFanOutTest.php`
- **Commit:** `49b7090` (fix) — tests in `6cf74b9` referen direct de nieuwe naam.

**2. [Rule 3 - Blocking] worktree had geen vendor/ + geen .env (= geen APP_KEY)**
- **Found during:** Composer-install vóór Task 0 (vendor-dir leeg in worktree) en eerste test-run (alle tests failen op `No application encryption key has been specified`).
- **Fix:** `composer install` + `cp .env.example .env && php artisan key:generate` — beide standaard worktree-bootstrap-stappen die niet in plan stonden omdat ze omgevings-setup zijn, niet plan-logica. `.env` is gitignored dus geen impact op commits.
- **Files modified:** geen tracked files
- **Commit:** N/A (omgevings-setup)

### Plan Output-instructions

- Plan's `<output>`-block specificeert `docs-sync`-trigger als follow-up — nieuwe `routes/webhooks.php` + Spatie-configs + webhook-controller raken `.docs/`-structuur. Niet uitgevoerd binnen deze plan-execute (geen wijzigingen aan `.docs/` of `CLAUDE.md` nodig vanuit de pure code-output); kan apart getriggerd worden bij merge naar `chore/v02-roadmap-split-and-scramble`.

## Issues Encountered

- **Geen vendor/ in worktree-cwd (recovered)**: Eerste poging tot `composer show spatie/laravel-webhook-server` faalde — worktree-checkouts zijn leeg voor `vendor/`. Recovery: `composer install` (~30s). `.env` (gitignored) ook ontbrekend → `cp .env.example .env && php artisan key:generate` (~2s). Beide standaard worktree-bootstrap; geen plan-impact.
- **Eerste Write naar PREFLIGHT.md landde op main repo i.p.v. worktree**: Absolute path `/Users/.../emeq-hub/.planning/...` ipv worktree-path. Hersteld via `rm` op main repo + Write naar `/Users/.../emeq-hub/.claude/worktrees/agent-acc8f427724d2abfb/.planning/...`. Geen commit op main repo; geen drift.

## User Setup Required

None — `MOLLIE_WEBHOOK_SECRET` was al ingesteld in 05a-01's `.env.example`. Productie-rollout vereist:
1. Eén globale `MOLLIE_WEBHOOK_SECRET` zetten in de Hub-omgeving (Mollie Connect-platform-secret, niet per-Connection).
2. Per Consumer: `consumers.webhook_callback_url` + `consumers.webhook_callback_secret` invullen (kolommen bestaan sinds 05a-01).
3. Bij Payment-create (komt in 05a-03) de `webhookUrl` als `https://hub.emeq.test/webhooks/mollie/{connection_id}` instellen zodat Mollie weet waar te POST'en.

## Known Stubs

None — webhook-ingress is een complete vertical slice. Geen UI-rendering, geen TODOs, geen placeholder-data. Hub-side audit-rows landen in `webhook_calls`; outgoing fan-out gaat via Spatie's queueable job naar Consumer-callback-URL.

## v0.3+ Backlog Notes

- **Resource-type-detectie via id-prefix** in anti-spoofing-fetch: huidige `Mollie::client()->payments->get($id)` werkt voor v0.2 omdat Mollie's webhook-payloads altijd Payment-id's (`tr_*`) dragen. Als Mollie's next-gen webhooks-API later ook subscription-level events met `sub_`-prefix gaat sturen, moet de controller een prefix-switch toevoegen: `tr_` → `payments->get`, `sub_` → `customerSubscriptions->getForId`, `re_` → `refunds->get`. Gedocumenteerd in `MollieWebhookController` PHPDoc.
- **Per-Connection webhook-callback-URL override**: Consumer-niveau-URL is voor v0.2 voldoende (één SaaS-app = één callback). Als een Consumer multi-tenant zelf wordt, kan een per-Connection-override-kolom op `connections` worden toegevoegd. Backlog.
- **Webhook-replay protection / dedupe**: Mollie kan dezelfde webhook meerdere keren retried sturen. Spatie's webhook-client schrijft elk inkomend event apart in `webhook_calls`. v0.3+ kan een `processed_at` + idempotency-key kolom toevoegen + dedupe-check vóór fan-out. Niet kritiek voor v0.2 (Consumers moeten zelf idempotent zijn — best-practice).

## Next Phase Readiness

- **Plan 05a-03..05a-05 (resource-controllers)** kunnen direct starten op `AbstractMolliePassThroughController` (Plan 05a-01). Webhook-pad is een onafhankelijke vertical; resource-plans hoeven niets te integreren met `MollieWebhookController` behalve het zetten van `webhookUrl` in Payment-create-payload (5a-03 scope).
- **Naschool-integratie** kan vanaf 05a-02 een Consumer-callback-URL configureren en webhook-events ontvangen — fan-out-pad is productie-rijp.
- **Geen blockers**.

## Self-Check: PASSED

- All 10 created files exist on disk (verified via `test -f`).
- All 5 task-commits exist in `git log` (6a46680, 02c0b3c, 908070d, 49b7090, 6cf74b9).
- Webhook feature-tests: **11 passed / 0 failed** (filter='MollieWebhook').
- Full PHPUnit suite: **154 passed / 1 incomplete (pre-existing Phase 3 placeholder) / 0 failed**.
- Pint clean op alle nieuwe/gewijzigde files.
- `php artisan route:list --path=webhooks` toont `POST webhooks/mollie/{connection_id}` met `MollieWebhookController` als target.

---
*Phase: 05a-mollie-sdk-resources-webhooks-pass-through-api*
*Plan: 02*
*Completed: 2026-05-14*
