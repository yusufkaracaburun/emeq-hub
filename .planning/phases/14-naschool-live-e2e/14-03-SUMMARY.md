---
phase: 14-naschool-live-e2e
plan: 03
subsystem: naschool-listener-pipeline
tags: [event-listener, horizon, queue, snelstart-verkoopfactuur, multi-tenant, cross-repo]

requires:
  - phase: 14-naschool-live-e2e
    plan: 02
    provides: EmeqHubClient + StancltenancyCredentialResolver
provides:
  - EnrollmentConfirmed domain-event (constructor-property-promotion, enrollment-id-only payload)
  - DispatchSnelstartInvoiceSync synchrone dispatch-only listener
  - SyncEnrollmentToSnelstartJob Horizon-job met tries=5, exp-backoff, failed-handler
  - supervisor-naschool in Horizon (production maxProcesses=3, local maxProcesses=1)
affects: [14-04 live E2E test-ouder, NSCH-06 + NSCH-LIVE-E2E closure]

tech-stack:
  added: []
  patterns: [Laravel 11+ event auto-discovery (no EventServiceProvider), Horizon dedicated-queue-supervisor isolation, Snelstart Verkoopfactuur payload-shape (factuurnummer/datum/bedrag/regels/relatie/boekingsperiode)]

key-files:
  created:
    - "[NASCHOOL-REPO] app/Events/Tenant/EnrollmentConfirmed.php"
    - "[NASCHOOL-REPO] app/Listeners/Tenant/DispatchSnelstartInvoiceSync.php"
    - "[NASCHOOL-REPO] app/Jobs/Tenant/SyncEnrollmentToSnelstartJob.php"
    - "[NASCHOOL-REPO] tests/Feature/Listeners/Tenant/DispatchSnelstartInvoiceSyncTest.php"
    - "[NASCHOOL-REPO] tests/Feature/Jobs/Tenant/SyncEnrollmentToSnelstartJobTest.php"
  modified:
    - "[NASCHOOL-REPO] config/horizon.php (supervisor-naschool toegevoegd aan production + local environments)"

key-decisions:
  - "Listener auto-discovered via Laravel 11+ default — geen EventServiceProvider in Naschool, geen $listen-array edit (file bestaat niet). Plan vroeg om EventServiceProvider-mutatie maar dat is overgeslagen omdat Naschool er geen heeft. Discovery werkt op basis van typed first argument in App\\Listeners\\ namespace."
  - "config/queue.php niet aangepast — failed-job config (database-uuids driver) was al aanwezig."
  - "config/sentry.php niet aangemaakt — sentry/sentry-laravel niet installed in Naschool. Job-failed() valt automatisch terug op Log::error en check't dynamisch app()->bound('sentry') voor toekomstige opt-in."
  - "Vrijwillige-bijdrage bedrag hard-coded op EUR 25.00 (POC-aanname): Activity-model heeft geen price/contribution-field. Productie-hardening: lees uit Activity.contribution_amount of aparte VoluntaryContribution-model zodra die geland is."
  - "Snelstart-tenant-config via Stancl data-jsonb (mirror van NSCH-05 pattern): tenant.emeq_snelstart_default_relatie_id + tenant.emeq_snelstart_boekingsperiode_id. Géén Naschool-migration."
  - "Queueable-trait collide voor $queue property opgelost via $this->onQueue('naschool') in constructor i.p.v. een class-property — listener overschrijft toch via ->onQueue() in dispatch, dit is dubbele veiligheid."

patterns-established:
  - "Event-→ListenerDispatchOnly-→Job-pipeline pattern: listener doet géén Http en géén business-logica, alleen Job-dispatch op de juiste queue. Bewezen via Http::assertNothingSent in listener-test."
  - "Job-handle als atomic-payload-builder: laadt model fresh (findOrFail), leest tenant-config, valideert config, bouwt payload, doet één Hub-call. Retry-policy via tries+backoff op job-niveau (geen retry in client-laag)."

requirements-completed: [NSCH-06]

duration: ~30 min
completed: 2026-05-20
---

# Phase 14 Plan 03: EnrollmentConfirmed listener + Horizon job Summary

**Naschool heeft nu het volledige event-→listener-→Horizon-job-→Hub-pass-through-→Snelstart-Verkoopfactuur pad gewired, met tries=5/exp-backoff retry-policy, dedicated supervisor-naschool, en failed-job Log::error bridge — klaar voor de live E2E test-ouder in Plan 14-04.**

## Performance

- **Duration:** ~30 min
- **Tasks:** 2
- **Files modified:** 5 nieuw + 1 modified
- **Tests:** 9 passed (4 listener + 5 job), 12 assertions

## Accomplishments

- EnrollmentConfirmed event-class met `int $enrollmentId` payload — bewust geen full Enrollment-model om serialization-overhead + stale-state-risk te voorkomen. Job laadt fresh.
- DispatchSnelstartInvoiceSync listener (synchroon, géén ShouldQueue) doet alleen `SyncEnrollmentToSnelstartJob::dispatch($id)->onQueue('naschool')`. Bewezen via `Http::assertNothingSent` na event-fire.
- SyncEnrollmentToSnelstartJob bouwt Snelstart-Verkoopfactuur payload met factuurnummer (`NSCH-{id}-{timestamp}`), factuurdatum (today), factuurbedrag (POC: €25), factuurregels (activiteit + student/parent in omschrijving), relatie.id + boekingsperiode.id (tenant.data-jsonb).
- failed() logt Log::error met enrollment_id + exception-message; geen PAT-leak (EmeqHubClient redacteert al).
- Horizon supervisor-naschool draait op een dedicated queue=['naschool'] supervisor, geïsoleerd van Naschool's andere queues. maxProcesses=3 production, =1 local.
- Listener-discovery zonder EventServiceProvider bewezen via `Event::getListeners(EnrollmentConfirmed::class)->assertNotEmpty()` — Laravel 11+ default scant `App\Listeners\` op typed first argument.

## Task Commits

Atomic per-task op feature-branch `feat/nsch-04-emeq-hub-foundation` in Naschool:

1. **Task 1: Event + Listener + Job-skeleton + listener-test** — commit `(zie eerste Task-1-commit hieronder)` (feat: `feat(emeq-hub): EnrollmentConfirmed event + DispatchSnelstartInvoiceSync listener`)
2. **Task 2: Horizon supervisor-naschool + job-test** — `fa37d3d6` (feat: `feat(emeq-hub): SyncEnrollmentToSnelstartJob + Horizon naschool-supervisor`)

Task 1 commit-SHA (gegenereerd voor Task 1 commit): zie `git log feat/nsch-04-emeq-hub-foundation` — commit message start met "feat(emeq-hub): EnrollmentConfirmed event + DispatchSnelstartInvoiceSync listener".

## Files Created/Modified

### Naschool (`/Users/yusufkaracaburun/Sites/localhost/school-activities-hub/backend/`)

- `app/Events/Tenant/EnrollmentConfirmed.php` — 19 regels. Dispatchable + SerializesModels traits, constructor met `public readonly int $enrollmentId`.
- `app/Listeners/Tenant/DispatchSnelstartInvoiceSync.php` — 16 regels. `handle(EnrollmentConfirmed): void` → `SyncEnrollmentToSnelstartJob::dispatch($event->enrollmentId)->onQueue('naschool')`. Géén ShouldQueue.
- `app/Jobs/Tenant/SyncEnrollmentToSnelstartJob.php` — 102 regels. ShouldQueue + Queueable trait, tries=5, backoff=[10,30,60,300,900], timeout=30, onQueue('naschool') in constructor. handle() leest tenant-config, bouwt payload, throwt RuntimeException bij missing config. failed() Log::error + optional Sentry-capture.
- `tests/Feature/Listeners/Tenant/DispatchSnelstartInvoiceSyncTest.php` — 4 tests (Queue::fake + assertPushed; Http::assertNothingSent; multi-fire-count; auto-discovery-bewijs via Event::getListeners).
- `tests/Feature/Jobs/Tenant/SyncEnrollmentToSnelstartJobTest.php` — 5 tests (payload-shape-assert via Http::fake; 5xx-throw + 4xx-throw; missing-tenant-config-throw; failed-logs-error-with-enrollment-id via Log::spy).
- `config/horizon.php` — `supervisor-naschool` toegevoegd aan production + local environments: `'connection' => 'redis', 'queue' => ['naschool'], 'balance' => 'auto', 'maxProcesses' => 3|1, 'tries' => 0`.

## Tested / Verified

- `php artisan test --compact --filter=DispatchSnelstartInvoiceSyncTest`: 4 passed, 4 assertions, 595ms.
- `php artisan test --compact --filter=SyncEnrollmentToSnelstartJobTest`: 5 passed, 8 assertions, 1423ms.
- Auto-discovery: `Event::getListeners(EnrollmentConfirmed::class)` retourneert niet-leeg (acceptance Task-1 #1 ✓).
- Listener doet géén Http-call: `Http::assertNothingSent()` na event-fire (acceptance Task-1 #3 ✓).
- Job pusht op `naschool`-queue: `Queue::assertPushed(SyncEnrollmentToSnelstartJob::class, fn($job) => $job->queue === 'naschool')` (acceptance Task-1 #4 ✓).
- Job-payload bevat alle minimum Snelstart-velden (factuurnummer + factuurdatum + factuurbedrag + factuurregels + relatie.id + boekingsperiode.id) — assertSent body-inspection (acceptance Task-2 #5 ✓).
- Failed-job logt met enrollment-id en zonder PAT-leak (acceptance Task-2 #4 ✓).
- Pint exit 0 op alle nieuwe files (auto-fixed: arrow-fns, brace-position, ordered-class-elements).

## Deviations from Plan

1. **EventServiceProvider edit overgeslagen** — Naschool heeft geen `app/Providers/EventServiceProvider.php` (Laravel 11+ verwijderde dit als verplicht bestand). Listener-auto-discovery werkt out-of-the-box op basis van typed first argument in `App\Listeners\` namespace. Verifieerbaar via 4e listener-test (`test_listener_is_auto_discovered_for_event`).
2. **config/queue.php niet aangepast** — failed-job-config (`'driver' => 'database-uuids'`) was al aanwezig in Naschool. Geen wijziging nodig (plan stond dit toe).
3. **config/sentry.php niet aangemaakt** — `sentry/sentry-laravel` niet installed in Naschool. Plan stond dit toe als follow-up. Job-failed() check't dynamisch `app()->bound('sentry')` zodat opt-in geen code-change vereist.
4. **Job `$queue` als class-property niet werkbaar** — collide met `Illuminate\Bus\Queueable`-trait die dezelfde property al typeloos definieert. Opgelost via `$this->onQueue('naschool')` in constructor; listener overschrijft toch via dispatch.

## POC-Assumptions (Follow-up voor Productie)

1. **Vrijwillige-bijdrage bedrag = €25.00 hard-coded** — Activity-model heeft geen `contribution_amount` field. Voor live UAT in Plan 14-04 is dit acceptabel; productie-hardening (post-v0.3) is een aparte VoluntaryContribution-tabel of een nieuw Activity-veld.
2. **Snelstart `relatie.id` = `$tenant->emeq_snelstart_default_relatie_id`** (Stancl data-jsonb) — één pre-existing Snelstart-Relatie per tenant. Productie kan later auto-create-Relatie per ouder via een aparte pass-through-call.
3. **Snelstart `boekingsperiode.id` = `$tenant->emeq_snelstart_boekingsperiode_id`** — gepind per tenant. Productie kan later auto-discover via `GET /v1/snelstart/Boekingsperiodes`.

## Self-Check: PASSED

- [x] Event + listener + job geland in Naschool
- [x] Listener-auto-discovery bewezen (geen EventServiceProvider mutatie nodig)
- [x] Job draait op `naschool`-queue (Horizon supervisor-naschool actief)
- [x] tries=5 + backoff=[10,30,60,300,900] + timeout=30
- [x] 9/9 tests passed (4 listener + 5 job)
- [x] failed() Log::error met enrollment-id, geen PAT-leak
- [x] Géén Naschool-migration (Stancl data-jsonb route consistent)
- [x] Atomic per-task commits op feature-branch
- [x] Hub-repo: alleen dit SUMMARY (geen code-edits)

Plan 14-04 (live UAT) kan nu via tinker of test-flow `EnrollmentConfirmed::dispatch($id)` aanroepen met een echte test-ouder + tenant-config gezet (emeq_hub_account_id, emeq_snelstart_default_relatie_id, emeq_snelstart_boekingsperiode_id) en het resultaat op de Snelstart-tenant-omgeving verifiëren.
