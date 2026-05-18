---
phase: 05c-snelstart-webhook-handler
plan: 03
subsystem: webhook-ingress
tags: [laravel, routes, controller, audit-log, idempotency, phpunit, snelstart]

# Dependency graph
requires:
  - phase: 05c-snelstart-webhook-handler
    provides: pass_through_calls inbound-kolommen + connections.administratie_id (plan 05c-01)
  - phase: 05c-snelstart-webhook-handler
    provides: SDK-middleware `verify.snelstart.signature` (plan 05c-02, post-execute SDK-refactor)
  - phase: 05c-snelstart-webhook-handler
    provides: ForwardSnelstartWebhookToConsumerJob (plan 05c-04)
provides:
  - Route `POST /webhooks/snelstart` met `verify.snelstart.signature` + `withoutMiddleware(throttle:api)` en naam `webhooks.snelstart`
  - App\Http\Controllers\Webhooks\SnelstartWebhookController — single-action invokable met malformed/idempotency/unknown-administratie/happy-path takken
affects: [05c-05-integration-tests]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Invokable webhook-controller-pattern hergebruikt uit Phase 5a MollieWebhookController: single-action `__invoke` met 4 paden (malformed/dup/unknown/happy) en audit-write op alle paden"
    - "Anti-retry-storm via 200-respons op valid HMAC + onbekende administratie (CONTEXT 🔒): voorkomt Snelstart-retry-cyclus op niet-bestaande tenants"
    - "Idempotency-dup-rij heeft `event_id=NULL` om de (provider, event_id) unique-index uit plan 05c-01 niet te triggeren; forensics via `upstream_error='duplicate_event'`"
    - "Stub-controller + route in dezelfde RED-commit (Rule 3 deviation): invokable-controller wordt door RouteAction::makeInvokable eager geresolveerd, dus Task 1 verify (`route:list`) faalt zonder Task 2's controller-class"

key-files:
  created:
    - app/Http/Controllers/Webhooks/SnelstartWebhookController.php
    - tests/Feature/SnelstartWebhookControllerTest.php
  modified:
    - routes/webhooks.php

key-decisions:
  - "Stub-controller meegenomen in RED-commit (Rule 3 deviation): combineert plan Task 1 + Task 2 RED in één commit omdat route:list eager invokable-resolve doet. GREEN-commit voegt enkel behavior toe. Volgorde RED → GREEN blijft chronologisch bewijsbaar in git log"
  - "Dup-rij `event_id=NULL` i.p.v. `event_id=$eventId`: zonder NULL crasht de tweede insert op de unique-index. Plan-tekst suggereerde 'tweede rij' zonder NULL-detail; deviation in plan-code is correctheid-vereiste (Rule 1 zou opvoeren — preventief opgelost in RED-fase)"
  - "Revoked Connection krijgt zelfde behandeling als unknown administratie: `whereNull('revoked_at')` in de lookup-query. Geen aparte 'revoked' audit-status; plan T-05c-17 (forged administratieId) leunt op precies dit pad"
  - "TestResponse-helper postSignedWebhook gebruikt $this->call() i.p.v. postJson() omdat de SDK-middleware de raw body hash't — Laravel's postJson re-encodet de body en breekt anders de signature-match. Match-met-byte-niveau is een security-invariant"

patterns-established:
  - "Hub-side audit-write op alle inbound-paden (200 happy + 200 unknown + 200 dup + 400 malformed). Middleware-laag (SDK) blijft Hub-agnostisch en schrijft géén audit op 401/500. Asymmetrie is bewust: invalid-HMAC = anti-amplification, valid-HMAC = forensics-trail"
  - "Single-source-of-truth voor `snelstart.webhook.event_id_key`: SDK-config + Hub-controller leest het. Plan 05 mag dezelfde config-key gebruiken in E2E-tests"

requirements-completed: []  # HUB-06 closure komt na plan 05c-05 (integration-suite)

# Metrics
duration: ~20min
completed: 2026-05-17
---

# Phase 05c Plan 03: Route + SnelstartWebhookController Summary

**`POST /webhooks/snelstart` route + single-action invokable controller die ná de SDK-signature-middleware vier paden (malformed/dup/unknown/happy) afhandelt met audit-write op `pass_through_calls` en async fan-out via de Phase-04-job. Anti-retry-storm via 200 op valid HMAC + unknown administratie; idempotency met NULL-event_id-dup-rij om de unique-index niet te triggeren.**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-05-17 (na 05c-04 close)
- **Completed:** 2026-05-17
- **Tasks:** 2 (1 auto + 1 TDD)
- **Files created/modified:** 3 (2 created, 1 modified)
- **Commits:** 2 (1 RED test + 1 GREEN feat)

## Accomplishments

- `routes/webhooks.php` registreert `POST /webhooks/snelstart` met middleware-chain `['api', 'verify.snelstart.signature']` en expliciete `withoutMiddleware(['throttle:api'])`. Route-naam `webhooks.snelstart` resolves naar `http://hub.emeq.test:8090/webhooks/snelstart`. Mollie- en Cashier-routes onaangetast.
- `App\Http\Controllers\Webhooks\SnelstartWebhookController` is een `final` single-action invokable die de 4 inbound-paden afhandelt:
  - **malformed**: ontbrekend/niet-string `administratieId` → 400 + audit `upstream_error='malformed_payload'` + JSON-error body
  - **duplicate**: bestaand `event_id` voor provider snelstart → 200 + dup-audit met `event_id NULL` + `upstream_error='duplicate_event'`; géén tweede dispatch
  - **unknown** (incl. revoked): geen Connection met `(provider=snelstart, administratie_id=$id, revoked_at IS NULL)` → 200 + NULL-tenant audit + `upstream_error='unknown_administratie_id'`; géén dispatch
  - **happy**: Connection gevonden → 200 + audit-row met `consumer_id/account_id/connection_id` keten + `event_id` + sha256-fingerprint + `ForwardSnelstartWebhookToConsumerJob::dispatch($connection, $payload, $eventId ?? 'no-id')`
- 7/7 PHPUnit feature-tests groen (48 assertions) in `tests/Feature/SnelstartWebhookControllerTest.php`:
  - `test_valid_webhook_with_known_administratie_dispatches_job` — HUB-06 SC-1
  - `test_unknown_administratie_returns_200_with_null_tenant_audit` — HUB-06 SC-3
  - `test_idempotent_duplicate_event_id_does_not_redispatch` — HUB-06 SC-4
  - `test_malformed_payload_returns_400_with_audit` — anti-amplification op malformed
  - `test_invalid_signature_returns_401_without_audit` — regressie op plan-02 middleware
  - `test_revoked_connection_treated_as_unknown` — `whereNull('revoked_at')` pad
  - `test_cross_consumer_isolation_routes_to_correct_consumer` — HUB-06 SC-5 (gedeeltelijk; E2E in plan 05c-05)
- Volledige Hub-testsuite: **518/519 passed** + 1 incomplete (Phase 3-03 placeholder) + 1 pre-existing failure (`UserResourceTest::test_super_admin_can_create_user_via_resource` — out-of-scope per plan). Baseline 511/512 → 518/519 met +7 nieuwe tests, zelfde failure-baseline.
- Plan-05c-02 middleware-tests blijven groen via SDK-laag (Hub-side suite raakt ze niet meer aan — coverage zit in `packages/snelstart-api/tests/`).
- Mollie-webhook-regressie: 13/13 tests groen — 5a/7a-pipeline onaangeroerd.

## Task Commits

1. **Task 1 + Task 2 RED: route + stub controller + 7 failing tests** — `207ed1b` (test)
2. **Task 2 GREEN: controller behavior** — `f999b4e` (feat)

_Note: Geen REFACTOR-gate nodig — GREEN-implementatie bleef minimaal (118 regels controller, 3 private helpers, geen duplicatie t.o.v. Mollie-controller-pattern omdat audit-tabel + idempotency-logic Snelstart-specifiek zijn)._

## Files Created/Modified

- `app/Http/Controllers/Webhooks/SnelstartWebhookController.php` — final single-action controller (118 regels, strict types)
- `tests/Feature/SnelstartWebhookControllerTest.php` — 7 tests / 48 assertions met SDK-signing-helper voor valid-HMAC-pad
- `routes/webhooks.php` — POST /webhooks/snelstart toegevoegd, middleware + naam + throttle-strip

## Decisions Made

- **Stub-controller in RED-commit (Rule 3 deviation)** — Plan splitste Task 1 (route) en Task 2 RED/GREEN als 3 commits. Maar `RouteAction::makeInvokable()` eager-resolved de controller-class bij `Route::post()`-aanroep, dus Task 1's verify-step (`php artisan route:list`) crasht zonder de class. Oplossing: minimale stub + route in dezelfde RED-commit met de 7 tests; GREEN-commit voegt enkel behavior toe. Chronologische TDD-volgorde (test eerst falen, daarna minimal-impl) blijft bewijsbaar in git log.
- **Dup-audit-rij heeft `event_id=NULL`** — Plan-tekst sprak over "tweede audit-rij met `upstream_error='duplicate_event'`" zonder het NULL-detail. Maar plan 05c-01 introduceerde een unique-index op `(provider, event_id)`; een tweede insert met hetzelfde event_id crasht. NULL gerespecteerd door Postgres + SQLite in unique indexes (meerdere NULLs OK). Forensics-trace via `upstream_error`-veld. RED-test asserteert `event_id=NULL` op de dup-rij — preventief opgelost.
- **TestResponse-helper via `$this->call()` i.p.v. `postJson()`** — Signature is HMAC over de raw body. Laravel's `postJson()` re-encodet body achter de schermen waardoor de match faalt. `call()` met expliciete `content: $rawBody` + `CONTENT_TYPE: application/json` houdt byte-niveau-equivalentie tussen sign-tijd en request-tijd. Match-pattern uit `ForwardSnelstartWebhookToConsumerJobTest` (anti-correlation-test gebruikt hetzelfde `hash_hmac('sha256', json_encode($payload), $secret)`).
- **Revoked Connection valt onder unknown-pad** — `whereNull('revoked_at')` in de lookup-query produceert exact dezelfde respons als een niet-bestaande administratie (200 + NULL-tenant audit). Plan-T-05c-17 (forged administratieId) leunt op deze samenvoeging. Aparte 'connection_revoked' upstream_error zou een lekkende info-disclosure-vector zijn (aanvaller kan onderscheid maken tussen "bestaat niet" vs "bestond ooit, nu revoked").
- **Geen body in 200-respons** — `response('', 200)` op happy + dup + unknown paden. Snelstart's spec vereist alleen status-code voor ack; lege body bespaart bandwidth en geeft geen handle voor info-disclosure. Match-pattern uit Spatie's webhook-server tegenhanger.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Stub-controller + route + RED-tests in één commit**

- **Found during:** Task 1 verify-step
- **Issue:** Plan's Task 1 stipuleerde een aparte commit voor de route. Maar Laravel's `RouteAction::makeInvokable()` doet een `method_exists` op de controller-class bij route-registratie tijd — niet pas bij request-handling. `php artisan route:list` crasht met `UnexpectedValueException` zonder de class. Task 1's verify zou dus altijd falen tot Task 2 land.
- **Fix:** Minimale stub-controller (`return response('', 200);`) toegevoegd aan de RED-commit zodat de route resolveert. Behavior in GREEN-commit. TDD-volgorde blijft bewijsbaar (RED-tests + stub falen samen om de juiste reden; GREEN-controller laat ze slagen).
- **Files modified:** `app/Http/Controllers/Webhooks/SnelstartWebhookController.php` (initial stub) + `routes/webhooks.php` + `tests/Feature/SnelstartWebhookControllerTest.php`
- **Verification:** RED 6/7 falen om de juiste reden (audit-rij ontbreekt, 200 i.p.v. 400); 1 test (invalid-signature) slaagt al door middleware-laag uit plan 02. GREEN 7/7 groen.
- **Committed in:** `207ed1b` (RED) + `f999b4e` (GREEN)

**2. [Rule 1 - Bug-preventie] Dup-audit-rij heeft `event_id=NULL` om unique-index niet te crashen**

- **Found during:** RED-test design
- **Issue:** Plan stipuleerde "tweede audit-rij met `upstream_error='duplicate_event'`" zonder het NULL-detail. Plan 05c-01 introduceerde unique-index `(provider, event_id)` — een tweede insert met hetzelfde event_id crasht op DB-niveau. Dit zou de happy-path-bug van de eerste duplicate naar de tweede duplicate verplaatsen.
- **Fix:** Dup-rij krijgt `event_id` weggelaten (NULL); `upstream_error='duplicate_event'` houdt forensics. Postgres + SQLite staan meerdere NULLs toe in unique indexes. RED-test asserteert dit expliciet (`$audits[1]->event_id === null`).
- **Files modified:** controller + test
- **Verification:** Idempotency-test groen; geen DB-constraint-crash.
- **Committed in:** `207ed1b` (test) + `f999b4e` (controller)

---

**Total deviations:** 2 auto-fixed (1 Rule 3 = blocking-route-resolve-eagerness + 1 Rule 1 = unique-index-crash-preventie).
**Impact on plan:** Geen architecturele aanpassing. Commit-shape gewijzigd (2 commits i.p.v. 3); plan-acceptance-greps alle groen.

## Issues Encountered

- **Pre-existing test-failure in `UserResourceTest::test_super_admin_can_create_user_via_resource`** — gevonden tijdens full-suite-regressie-check. Baseline 511/512 → 518/519: exact +7 nieuwe tests, zelfde failure. Out-of-scope per success criteria (gemarkeerd in plans 02 + 04). Phase 9/10 eigenaar.

## Threat Flags

Geen nieuwe security-surface buiten het threat-model van het plan. T-05c-14..18 gemitigeerd:

- **T-05c-14 (Information disclosure via audit-payload-leak):** geen body-snapshot opgeslagen, alleen `sha256(rawBody)[0..12]` fingerprint. Match-pattern Phase 5b.
- **T-05c-15 (Idempotency-bypass via NULL event_id):** accept-status — plan-T-15 erkent dat Snelstart hoort altijd `eventId` mee te sturen; dup-detectie alleen op non-null. Documenteerd in plan-CONTEXT.md regel 86.
- **T-05c-16 (Retry-storm op 5xx):** mitigated — controller returnt 200 op unknown administratie (CONTEXT decision); 4xx alleen op malformed-payload (Snelstart hertried 4xx niet per RFC).
- **T-05c-17 (Forged administratieId):** accept — aanvaller moet eerst valid HMAC kunnen forgen (out-of-scope voor controller). Lookup-pad retourneert NULL-Connection, geen dispatch.
- **T-05c-18 (Cross-Consumer-leak):** mitigated — test 7 bewijst single-Consumer-routing via `connection.account.consumer_id`-chain. Full E2E-bewijs in plan 05c-05.

## Docs-drift signaal

`routes/webhooks.php` is geraakt — docs-sync-hook vuurde tijdens Task 1. Downstream docs die kunnen driften:

- `CLAUDE.md` Architecture-block: noemt `/webhooks/{provider}/{...}`-pattern; concrete Snelstart-route is nu live. Update bij plan 05c-05 close (volledige inbound-pipeline gesloten).
- `packages/snelstart-api/docs/decisions/snelstart-certificering-pad.md` (gitignored): kan een addendum krijgen met de definitieve `/webhooks/snelstart`-URL voor de certificeringsaanvraag.

Geen actie binnen deze plan-uitvoering — gemarkeerd voor `/docs-sync` na Phase 05c afronding.

## User Setup Required

None — `SNELSTART_WEBHOOK_SECRET` env-var is een tier-1 productie-config. Lokaal werkt de SDK-middleware met de config-default uit `packages/snelstart-api/config/snelstart.php`. Tests injecteren een test-secret via `config(['snelstart.webhook.secret' => ...])` in `setUp`.

## Next Phase Readiness

- Plan 05c-05 (integration-tests + ADR + tracking) kan deze controller direct exercisen:
  - HUB-06 SC-1 (valid + known administratie → 200 + audit + dispatch) — al gedekt door scenario 1; E2E voegt de fan-out-leg toe via `Bus::assertDispatched` + `CallWebhookJob::class`
  - HUB-06 SC-3 (unknown administratie → 200 + NULL-tenant + geen dispatch) — gedekt door scenario 2
  - HUB-06 SC-4 (idempotency) — gedekt door scenario 3
  - HUB-06 SC-5 (cross-Consumer-isolation) — gedeeltelijk gedekt door scenario 7 (Hub-side); E2E moet `CallWebhookJob.webhookUrl == consumerA.webhook_callback_url` asserteren
- HUB-06 SC-2 (invalid HMAC → 401 + geen audit) blijft SDK-side bewezen via `packages/snelstart-api/tests/Unit/Webhooks/SnelstartWebhookSignatureTest.php` + Hub-side via scenario 5 in deze plan.

## Self-Check

Verifying claims before returning to orchestrator.

**Files exist:**

- `[FOUND]` app/Http/Controllers/Webhooks/SnelstartWebhookController.php
- `[FOUND]` tests/Feature/SnelstartWebhookControllerTest.php
- `[FOUND]` routes/webhooks.php (modified)

**Commits exist on feat/05c-snelstart-webhook-handler:**

- `[FOUND]` 207ed1b — Task 1 + Task 2 RED (route + stub controller + 7 failing tests)
- `[FOUND]` f999b4e — Task 2 GREEN (controller behavior)

**Acceptance grep-checks (uit plan):**

- `[OK]` `grep -c "final class SnelstartWebhookController" app/Http/Controllers/Webhooks/SnelstartWebhookController.php` == 1
- `[OK]` `grep -c "ForwardSnelstartWebhookToConsumerJob::dispatch" app/Http/Controllers/Webhooks/SnelstartWebhookController.php` == 1
- `[OK]` `grep -c "unknown_administratie_id" app/Http/Controllers/Webhooks/SnelstartWebhookController.php` >= 1 (1×)
- `[OK]` `grep -c "duplicate_event" app/Http/Controllers/Webhooks/SnelstartWebhookController.php` >= 1 (1×)
- `[OK]` `grep -c "'administratie_id'" app/Http/Controllers/Webhooks/SnelstartWebhookController.php` >= 1 (1×)
- `[OK]` `grep -c "Route::post.*'/webhooks/snelstart'" routes/webhooks.php` == 1
- `[OK]` `grep -c "verify.snelstart.signature" routes/webhooks.php` == 1
- `[OK]` `grep -c "withoutMiddleware.*throttle:api" routes/webhooks.php` >= 1 (1×)
- `[OK]` `php artisan route:list --except-vendor --path=webhooks/snelstart` toont POST-route + `verify.snelstart.signature` + géén `throttle:api`
- `[OK]` `php artisan tinker --execute 'echo route("webhooks.snelstart");'` → `http://hub.emeq.test:8090/webhooks/snelstart`
- `[OK]` `php artisan test --compact --filter=SnelstartWebhookControllerTest` → 7/7 passed (48 assertions)
- `[OK]` `php artisan test --compact --filter=MollieWebhook` → 13/13 passed (regressie)
- `[OK]` `php artisan test --compact --filter=ForwardSnelstartWebhookToConsumerJobTest` → 5/5 passed (regressie)
- `[OK]` Full suite: 518/519 + 1 pre-existing failure (out-of-scope) + 1 pre-existing incomplete

## Self-Check: PASSED

---
*Phase: 05c-snelstart-webhook-handler*
*Completed: 2026-05-17*
