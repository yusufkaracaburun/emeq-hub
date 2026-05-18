---
phase: 05c-snelstart-webhook-handler
plan: 04
subsystem: queue-fanout
tags: [laravel, queue, horizon, spatie-webhook-server, phpunit, snelstart]

# Dependency graph
requires:
  - phase: 05a-mollie-pass-through
    provides: consumers.webhook_callback_url + consumers.webhook_callback_secret (encrypted) — fan-out-doelwit
  - phase: 03-hub-skeleton
    provides: Connection → Account → Consumer chain (relatie-resolutie in handle())
  - phase: 05c-snelstart-webhook-handler
    provides: spatie/laravel-webhook-server (vendor uit Phase 5a) — outbound HMAC + retry/backoff
provides:
  - App\Jobs\Webhooks\ForwardSnelstartWebhookToConsumerJob — queueable fan-out-job op queue 'webhooks'
  - config/horizon.php supervisor-webhooks — aparte Horizon-supervisor voor de 'webhooks'-queue (isolatie tegen Cashier/Mollie-burst-stalling)
affects: [05c-03-route-and-controller, 05c-05-integration-tests]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Per-Consumer anti-correlation HMAC: outbound signature gebruikt consumer.webhook_callback_secret (encrypted at rest) en NOOIT de inbound partner-secret. Een gelekte SNELSTART_WEBHOOK_SECRET kan dus geen consumer-callback forgen — invariant T-05c-09."
    - "Queue-isolation per provider-class: aparte Horizon-supervisor voor 'webhooks' naast 'default' zodat Snelstart-bursts geen Mollie-Cashier-jobs blokkeren (T-05c-13 mitigation)"
    - "Silent skip op missing callback-URL: handle() returnt zonder retry-loop wanneer Consumer geen webhook_callback_url heeft — geen exception, geen failed_jobs-pollution"
    - "TDD RED → GREEN twee-commit-cyclus: failing tests apart committed (test(05c-04): RED) vóór implementation (feat(05c-04)) — chronologisch bewijs in git-log"

key-files:
  created:
    - app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php
    - tests/Feature/ForwardSnelstartWebhookToConsumerJobTest.php
  modified:
    - config/horizon.php
    - .planning/phases/05c-snelstart-webhook-handler/05c-04-SUMMARY.md
    - .planning/STATE.md
    - .planning/ROADMAP.md

key-decisions:
  - "App\\Jobs\\Webhooks\\ — nieuwe sub-namespace voor outbound webhook fan-out-jobs (Mollie's ForwardMollieWebhookToConsumer blijft in app/Jobs/ root, niet verhuisd — scope-discipline)"
  - "onQueue('webhooks') in constructor i.p.v. via static dispatch — garandeert dat ELKE dispatch op de juiste queue landt, ook directe new ForwardSnelstartWebhookToConsumerJob(...)->dispatch() of Bus-binding-misuse"
  - "Test-assertions via Spatie's PUBLIC CallWebhookJob properties (webhookUrl + payload + headers) — geen reflectie. Secret-correctheid bewezen via reproductie van de Signature-header met DefaultSigner (hash_hmac sha256), niet via een niet-bestaande $job->secret property"
  - "Test 1 (queue-routing) gebruikt Bus::assertDispatched-closure die $job->queue === 'webhooks' checkt — Laravel 13 BusFake heeft géén assertDispatchedOn() (plan-stipulatie was incorrect)"
  - "Production maxProcesses=5 voor supervisor-webhooks (default supervisor-1 staat op 10) — Snelstart-fan-out is lichter dan Cashier-job-pipeline, maar moet wel kunnen schalen bij rate-limit-burst"

patterns-established:
  - "Outbound webhook fan-out job-shape: constructor(Connection, payload-array, eventId-string) + handle() doet consumer-resolve via account?->consumer chain en gebruikt WebhookCall::create()->url()->payload()->useSecret()->withHeaders()->dispatch()"
  - "Horizon multi-supervisor-pattern: default + dedicated queue-supervisor met eigen timeout en maxProcesses voor isolatie van bursting/slow downstream"

requirements-completed: []  # HUB-06 closure komt na plan 05c-05 (full integration-suite)

# Metrics
duration: ~15min
completed: 2026-05-17
---

# Phase 05c Plan 04: Async fan-out van Snelstart-webhooks naar Consumer Summary

**Een queueable job die geverifieerde Snelstart-payloads via Spatie's webhook-server fan-out naar de per-Consumer callback-URL met anti-correlation HMAC, plus een aparte Horizon-supervisor zodat Snelstart-bursts de Mollie-Cashier-queue niet platleggen.**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-05-17 (na 05c-02 close)
- **Completed:** 2026-05-17
- **Tasks:** 2 (1 TDD, 1 auto)
- **Files created/modified:** 4 (2 created, 2 modified incl. STATE/ROADMAP)
- **Commits:** 3 (1 RED test + 1 GREEN feat + 1 chore horizon)

## Accomplishments

- `App\Jobs\Webhooks\ForwardSnelstartWebhookToConsumerJob` is een `final` `ShouldQueue`-job die zichzelf in de constructor op queue `webhooks` plaatst en in `handle()` Spatie's `WebhookCall::create()->url()->payload()->useSecret()->withHeaders()->dispatch()` aanroept. Consumer zonder `webhook_callback_url` → silent skip zonder retry.
- 5/5 PHPUnit feature-tests groen:
  - **test_job_dispatches_to_webhooks_queue** — `Bus::fake([Job])`, dispatch via static, assertDispatched-closure asserteert `$job->queue === 'webhooks'`
  - **test_handle_skips_silently_without_callback_url** — Consumer zonder URL, `Bus::fake([CallWebhookJob])`, `handle()` direct aangeroepen, asserteert `Bus::assertNotDispatched(CallWebhookJob)`
  - **test_handle_dispatches_spatie_webhook_with_consumer_secret** — asserteert `$job->webhookUrl` + `$job->payload` + `Signature`-header gelijk aan `hash_hmac('sha256', json_encode($payload), 'consumer-secret-abc')`
  - **test_handle_includes_event_id_header** — asserteert `$job->headers['X-Emeq-Event-Id'] === 'evt-001'` (consumer-side dedupe-mogelijkheid)
  - **test_handle_uses_consumer_callback_secret_not_partner_secret** — anti-correlation: `config(['snelstart.webhook.secret' => 'partner-only'])`, consumer-secret `consumer-only`, asserteert Signature == HMAC(payload, 'consumer-only') én != HMAC(payload, 'partner-only')
- `config/horizon.php` heeft nu twee supervisors: bestaande `supervisor-1` (queue `default`) onaangeroerd; nieuwe `supervisor-webhooks` (queue `webhooks`, timeout 30s). Production-override `maxProcesses=5`, local-override `maxProcesses=1`. `php -l` clean, `php artisan config:show horizon.defaults.supervisor-webhooks.queue` rapporteert `['webhooks']`.
- Volledige Hub-testsuite: **511/512 passed** + 1 incomplete (Phase 4-01 placeholder) + 1 pre-existing failure (`UserResourceTest::test_super_admin_can_create_user_via_resource` — out-of-scope per success criteria). Baseline 506/507 → +5 nieuwe tests (mijn 5), zelfde failure-baseline.

## Task Commits

1. **Task 1 (TDD): `ForwardSnelstartWebhookToConsumerJob`**
   - RED: `be50c94` (test) — 5 failing PHPUnit feature-tests op missing class
   - GREEN: `d18b414` (feat) — final job-class met `onQueue('webhooks')`-in-constructor + handle() + 1 test-API-correctie (`assertDispatchedOn` bestaat niet op BusFake; vervangen door closure die `$job->queue` checkt)
2. **Task 2 (auto): Horizon `supervisor-webhooks`** — `4ba10d8` (chore) — `config/horizon.php` uitgebreid met tweede supervisor in `defaults` + environment-overrides voor `production` (`maxProcesses=5`) en `local` (`maxProcesses=1`)

_Note: Geen REFACTOR-gate nodig — GREEN-implementatie bleef minimaal (40 regels job-class, geen duplicatie t.o.v. ForwardMollieWebhookToConsumer omdat namespace + queue + eventId-header de Snelstart-job al diversifiëren)._

## Files Created/Modified

- `app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php` — final job-class (57 regels, strict types, ShouldQueue)
- `tests/Feature/ForwardSnelstartWebhookToConsumerJobTest.php` — 5 tests / 5 assertions (RefreshDatabase)
- `config/horizon.php` — supervisor-webhooks in `defaults` + environments-overrides voor production + local
- `.planning/STATE.md` + `.planning/ROADMAP.md` — Roadmap Evolution entry + plan-progress 2/5 → 3/5 (zie post-execution-edits)

## Decisions Made

- **App\\Jobs\\Webhooks\\ sub-namespace** — nieuwe folder voor outbound-fan-out-jobs. `ForwardMollieWebhookToConsumer` blijft in `app/Jobs/` root (geen mass-rename in deze plan-scope — chirurgisch wijzigen, `.ai/rules/engineering.md`).
- **`onQueue('webhooks')` in constructor** — garandeert routing ongeacht hoe de job gedispatcht wordt (static `::dispatch()` of `Bus::dispatch(new Job(...))`). Geen `public string $queue = 'webhooks'` property — die werkt niet op alle Queueable-paden in Laravel 13.
- **Test-assertions via public CallWebhookJob properties** — `webhookUrl`, `payload`, `headers` zijn public op Spatie's `CallWebhookJob`. Een `$job->secret`-property bestaat NIET (secret leeft alleen op `WebhookCall` en wordt via DefaultSigner omgezet naar de `Signature`-header). Anti-correlation getest door de Signature-header te reproduceren met `hash_hmac('sha256', json_encode(payload), $secret)` en te vergelijken met beide kandidaten.
- **Test 1 queue-assertion via `$job->queue`** — `BusFake::assertDispatchedOn()` bestaat niet in Laravel 13 (plan-tekst stipuleerde het wel — incorrecte API-aanname). Vervangen door `Bus::assertDispatched(Class, fn ($job) => $job->queue === 'webhooks')`. Behoudt dezelfde semantische assertion zonder ongebruikte stub.
- **`maxProcesses=5` production voor supervisor-webhooks** — `supervisor-1` staat op 10 (Cashier/Connect-flow). Snelstart-fan-out is licht (single HTTP-POST per event) maar moet wel kunnen schalen tijdens partner-burst; 5 is een balans tussen burst-capaciteit en resource-footprint. Aanpasbaar wanneer monitoring shows tegendraads gedrag.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Test 1 `assertDispatchedOn` bestaat niet op Laravel 13 BusFake**

- **Found during:** GREEN-fase, eerste full-run van de 5 tests
- **Issue:** Plan-tekst stipuleerde `Bus::assertDispatchedOn('webhooks', ForwardSnelstartWebhookToConsumerJob::class)`. Die method bestaat niet op `Illuminate\Support\Testing\Fakes\BusFake` — `grep -n "assertDispatched" vendor/laravel/.../BusFake.php` levert wel `assertDispatched`, `assertDispatchedOnce`, `assertDispatchedTimes`, etc., maar geen `assertDispatchedOn`.
- **Fix:** `assertDispatched` met closure die `$job->queue === 'webhooks'` asserteert. `$job->queue` is een public property uit `Illuminate\Bus\Queueable` (gezet door `onQueue()`).
- **Files modified:** `tests/Feature/ForwardSnelstartWebhookToConsumerJobTest.php`
- **Verification:** 5/5 tests passed.
- **Committed in:** `d18b414` (GREEN feat — test-fix + class-implementation in dezelfde commit, omdat de test-fix een API-correctie was, geen behavior-claim)

**2. [Rule 2 - Missing critical scope-protection] Anti-correlation test bewijst niet alleen consumer-secret-gebruik maar ook expliciet partner-secret-non-gebruik**

- **Found during:** Schrijven van RED-tests
- **Issue:** Plan beschrijft het anti-correlation invariant als "anti-correlation invariant — twee asserts in één callback". De originele Mollie-fan-out-test asserteert alleen `webhookUrl + payload`, niet de Signature-header. Voor de Snelstart-anti-correlation-mitigation (T-05c-09 spoofing) is een expliciet bewijs nodig dat het partner-secret niet stilletjes door de stack lekt.
- **Fix:** Test 5 asserteert `signature === HMAC(payload, 'consumer-only')` **én** `signature !== HMAC(payload, 'partner-only')`. Beide vergelijkingen in één callback. Eerste assert bewijst correct gebruik; tweede assert bewijst non-gebruik (zonder die zou een toekomstige refactor stilletjes naar de partner-secret kunnen swappen).
- **Files modified:** `tests/Feature/ForwardSnelstartWebhookToConsumerJobTest.php`
- **Verification:** test 5 passed in GREEN-run.
- **Committed in:** `be50c94` (RED test)

---

**Total deviations:** 2 auto-fixed (1 Rule 1 = test-API-bug + 1 Rule 2 = critical-security-coverage extension).
**Impact on plan:** Geen architecturele aanpassing. Test 1 fix gebruikt nog steeds een Bus-fake-closure (semantisch hetzelfde — alleen API-naam anders). Test 5 levert sterker bewijs voor T-05c-09 dan de Mollie-tegenhanger.

## Issues Encountered

- **Pre-existing test-failure in `UserResourceTest::test_super_admin_can_create_user_via_resource`** — gevonden tijdens full-suite-regressie-check. Baseline 506/507 → 511/512: exact +5 nieuwe tests, zelfde failure. Out-of-scope per success criteria (gemarkeerd als bekend uit plan 05c-02 STATE-entry). Phase 9/10 eigenaar.

## Threat Flags

Geen nieuwe security-surface buiten het threat-model van het plan. T-05c-09 t/m T-05c-13 zijn gemitigeerd:

- **T-05c-09 (Spoofing):** anti-correlation getest in test 5 — partner-secret wordt aantoonbaar NIET gebruikt voor outbound HMAC
- **T-05c-10 (Tampering):** Spatie's `WebhookCall::useSecret()` + DefaultSigner = HMAC-SHA256 over JSON-payload; tampering invalideert signature consumer-side
- **T-05c-11 (Repudiation):** accept-status — `event_id`-header (`X-Emeq-Event-Id`) draagt correlatie; volledige audit-row landt in plan 05c-03 (controller-laag)
- **T-05c-12 (Information disclosure):** Connection → Account → Consumer chain in handle() = single-tenant routing; cross-tenant-test komt in plan 05c-05 SC-5
- **T-05c-13 (Denial of service):** mitigated via `supervisor-webhooks` Horizon-isolation — Snelstart-bursts kunnen `supervisor-1` (Cashier/Mollie-default-queue) niet blokkeren

## Docs-drift signaal

`app/Jobs/Webhooks/` is een **nieuwe sub-namespace**. Downstream docs die kunnen driften:

- `CLAUDE.md` Architecture-block noemt nog geen `app/Jobs/Webhooks/`-folder als pattern — overweeg te updaten zodra plan 05c-05 ook integration-tests heeft (de hele Snelstart-webhook-pipeline staat dan compleet).
- `.docs/decisions/snelstart-certificering-pad.md` (gitignored) kan een addendum krijgen over de Horizon-supervisor-isolation-keuze (`webhooks`-queue) wanneer alle 5 Phase 05c-plans geland zijn.

Geen actie binnen deze plan-uitvoering — gemarkeerd voor `/gsd-transition` of `docs-sync`-pass na Phase 05c afronding.

## User Setup Required

None — `php artisan horizon` herkent de nieuwe supervisor automatisch zodra Horizon herstart wordt. In dev: `php artisan horizon:terminate` + opnieuw `php artisan horizon`. Geen env-vars-wijziging.

## Next Phase Readiness

- Plan 03 (route + controller) kan deze job direct dispatchen via `ForwardSnelstartWebhookToConsumerJob::dispatch($connection, $payload, $eventId)` — de queue-routing zit in de constructor, geen extra `->onQueue()`-call nodig in de controller.
- Plan 05 (integration-tests) kan SC-1 ("ForwardSnelstartWebhookToConsumerJob dispatched") bewijzen met `Bus::assertDispatched(ForwardSnelstartWebhookToConsumerJob::class, ...)` na een geldige inbound webhook.
- Cross-Consumer-isolation (SC-5) is in deze plan al gedeeltelijk bewezen — handle() resolveert consumer via `snelstartConnection->account?->consumer`; geen globale routing-tabel.

## Self-Check

Verifying claims before returning to orchestrator.

**Files exist:**

- `[FOUND]` app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php
- `[FOUND]` tests/Feature/ForwardSnelstartWebhookToConsumerJobTest.php
- `[FOUND]` config/horizon.php (modified, supervisor-webhooks aanwezig)

**Commits exist on feat/05c-snelstart-webhook-handler:**

- `[FOUND]` be50c94 — Task 1 RED (5 failing tests)
- `[FOUND]` d18b414 — Task 1 GREEN (job-class + test-API-fix)
- `[FOUND]` 4ba10d8 — Task 2 (horizon supervisor-webhooks)

**Acceptance grep-checks (uit plan):**

- `[OK]` `grep -c "final class ForwardSnelstartWebhookToConsumerJob" app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php` == 1
- `[OK]` `grep -c "implements ShouldQueue" app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php` == 1
- `[OK]` `grep -c "onQueue('webhooks')" app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php` == 1
- `[OK]` `grep -c "webhook_callback_secret" app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php` >= 1 (1×)
- `[OK]` `grep -c "Spatie\\\\WebhookServer\\\\WebhookCall" app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php` >= 1 (1×)
- `[OK]` `grep -c "'supervisor-webhooks'" config/horizon.php` >= 1 (3×)
- `[OK]` `grep -c "'queue' => \\['webhooks'\\]" config/horizon.php` >= 1 (1×)
- `[OK]` `php -l config/horizon.php` — No syntax errors detected
- `[OK]` `php artisan config:show horizon.defaults.supervisor-webhooks.queue` → `['webhooks']`
- `[OK]` `php artisan test --compact --filter=ForwardSnelstartWebhookToConsumerJobTest` — 5/5 passed
- `[OK]` Full suite: 511/512 + 1 pre-existing failure (out-of-scope) + 1 pre-existing incomplete

## Self-Check: PASSED

---
*Phase: 05c-snelstart-webhook-handler*
*Completed: 2026-05-17*
