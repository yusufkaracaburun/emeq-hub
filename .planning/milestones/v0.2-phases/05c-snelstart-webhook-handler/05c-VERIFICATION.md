---
phase: 05c-snelstart-webhook-handler
verified: 2026-05-17T23:25:00Z
status: passed
score: 5/5 SC's verified
re_verification:
  previous_status: null
  notes: "Initial verification (geen eerdere VERIFICATION.md)."
deferred:
  - truth: "OData-safety-net polling-job voor verloren retries"
    addressed_in: "post-MVP / Phase 9 (admin-replay) of v0.3+"
    evidence: "05c-CONTEXT.md regels 22, 95, 232 — out-of-scope tot ❓ #4 (partner retry-policy) beantwoord; idempotency-unique-index dekt de happy-path al."
  - truth: "Filament admin replay-knop voor gefaalde inbound events"
    addressed_in: "Phase 9 (HUB-04) follow-up — backlog"
    evidence: "05c-CONTEXT.md regel 21 — Out of scope; Phase 9 is afgesloten met `PassThroughCallResource` als HUB-OBSERVABILITY backlog-item."
open_questions:
  - question: "#4 Snelstart retry-policy (aantal retries + backoff + DLQ ja/nee)"
    status: "❓ partner heeft niet geantwoord 2026-05-17"
    risk: "Acceptable — defensieve aanname (5× exponential backoff) + DB-level idempotency-unique-index op (provider, event_id) maakt de happy-path correct ongeacht werkelijke retry-policy."
    follow_up: "OData-safety-net-job is captured als optionele follow-up; activatie afhangt van partner-respons. Niet blokkerend voor HUB-06 SC-1..SC-5."
---

# Phase 5c: Snelstart webhook-handler Verification Report

**Phase Goal:** Werkende ingress voor Snelstart-webhooks op `POST /webhooks/snelstart` met HMAC-verificatie, Connection-resolutie via payload `administratieId`, audit in `pass_through_calls` (`direction=inbound`) en async fan-out via Horizon `webhooks`-queue + Spatie `laravel-webhook-server` naar de Consumer-callback. Productie-certificeringsblocker voor HUB-06.

**Verified:** 2026-05-17T23:25:00Z
**Status:** passed
**Re-verification:** Nee — initiële verificatie.
**Branch:** `feat/05c-snelstart-webhook-handler` @ HEAD `b86022a`

## Goal Achievement

### Observable Truths (HUB-06 SC-1..SC-5)

| # | Success Criterion | Status | Evidence |
|---|---|---|---|
| SC-1 | Valid HMAC + bekende `administratieId` → 200 + audit-row `direction=inbound` + `ForwardSnelstartWebhookToConsumerJob` dispatched | ✓ VERIFIED | E2E: `tests/Feature/SnelstartWebhookEndToEndTest.php:44` (`test_sc_1_valid_known_administratie_dispatches_forward_job`) — 200, full Consumer/Account/Connection-chain audit-row geverifieerd, Bus::assertDispatched. Controller-unit: `tests/Feature/SnelstartWebhookControllerTest.php:34`. Controller-pad: `app/Http/Controllers/Webhooks/SnelstartWebhookController.php:85-103` (happy path schrijft chain-FKs + dispatcht job). |
| SC-2 | Invalid HMAC → 401, lege body, géén audit-row | ✓ VERIFIED | E2E: `tests/Feature/SnelstartWebhookEndToEndTest.php:84` — `$response->assertStatus(401)`, `assertSame('', $response->getContent())`, `PassThroughCall::count() === 0`. SDK-middleware: `vendor/emeq/snelstart-api/src/Http/Middleware/VerifySnelstartSignature.php:34-58` retourneert `response('', 401)` op mismatch, zonder DB-write. SDK Pest: `packages/snelstart-api/tests/Feature/Http/Middleware/VerifySnelstartSignatureTest.php` (7/7 passed). |
| SC-3 | Onbekende `administratieId` + valid HMAC → 200 + NULL-tenant audit + geen fan-out | ✓ VERIFIED | E2E: `tests/Feature/SnelstartWebhookEndToEndTest.php:120` — 200 + `consumer_id/account_id/connection_id` alle NULL + `upstream_error='unknown_administratie_id'` + `Bus::assertNothingDispatched()`. Controller-pad: `SnelstartWebhookController.php:69-83`. |
| SC-4 | Idempotency: zelfde `event_id` 2× → 200 + 1 dup-audit (`event_id=NULL`) + 1 originele job | ✓ VERIFIED | E2E: `tests/Feature/SnelstartWebhookEndToEndTest.php:148` — 2× zelfde payload → 2 audit-rows (1 origineel met `event_id='evt-dup'`, 1 dup met `event_id=NULL` + `upstream_error='duplicate_event'`) + `Bus::assertDispatchedTimes(...,1)`. DB-laag: unique-index `pass_through_calls_provider_event_unique (provider, event_id)` in `database/migrations/2026_05_15_000001_create_pass_through_calls_table.php:35`. Controller-pad: `SnelstartWebhookController.php:46-61` (NULL `event_id` op dup-rij voorkomt unique-violation). |
| SC-5 | Cross-Consumer-isolation: webhook voor administratie van Consumer X kan nooit fan-outten naar Consumer Y | ✓ VERIFIED | E2E: `tests/Feature/SnelstartWebhookEndToEndTest.php:181` — 2 Consumers met eigen Account+Connection, webhook voor `admin-A` audit-row heeft `consumer_id=consumerA`, `connection_id=connectionA`, en `Bus::assertDispatched` verifieert dat `$job->snelstartConnection->account->consumer_id === consumerA->id` én `$job->snelstartConnection->id !== connectionB->id`. Anti-correlation extra bewezen in `tests/Feature/ForwardSnelstartWebhookToConsumerJobTest.php:101-127` — outbound HMAC gebruikt `consumers.webhook_callback_secret`, niet de inbound partner-secret. |

**Score:** 5/5 SC's bewezen onafhankelijk via E2E + per-scenario tests.

### Required Artifacts

| Artifact | Expected | Status | Details |
|---|---|---|---|
| `routes/webhooks.php` | `POST /webhooks/snelstart` met `verify.snelstart.signature` + `withoutMiddleware(throttle:api)` + name `webhooks.snelstart` | ✓ VERIFIED | `routes/webhooks.php:31-34`. `php artisan route:list --path=webhooks/snelstart -v` toont route + middleware-chain `api` + `Emeq\SnelstartApi\Http\Middleware\VerifySnelstartSignature` (geen `throttle:api`). `route('webhooks.snelstart')` resolves naar `http://hub.emeq.test:8090/webhooks/snelstart`. |
| `app/Http/Controllers/Webhooks/SnelstartWebhookController.php` | Final single-action invokable controller, 4 paden (malformed/dup/unknown/happy) | ✓ VERIFIED | 135 regels, `final class` + `strict_types`, `__invoke(Request)` returnt Symfony Response, 3 private helpers (`isDuplicateEvent`, `auditMalformed`, `fingerprint`). Alle 4 paden auditen behalve invalid-HMAC (door SDK-middleware afgevangen vóór controller). |
| `app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php` | `ShouldQueue` job met `onQueue('webhooks')` in constructor + `handle()` doet Spatie `WebhookCall::create()->url()->payload()->useSecret()->withHeaders()->dispatch()` | ✓ VERIFIED | 57 regels, `final class` + `ShouldQueue` + `Dispatchable/Queueable/InteractsWithQueue/SerializesModels` traits. Constructor zet `$this->onQueue('webhooks')`. `handle()` resolves consumer via `account?->consumer`-chain, silent-skip op missing `webhook_callback_url`, gebruikt `consumers.webhook_callback_secret` (anti-correlation). |
| `vendor/emeq/snelstart-api/src/Webhooks/SnelstartWebhookSignature.php` | SDK HMAC-verifier (verify + sign) | ✓ VERIFIED | 60 regels, `final class`, `verify()` timing-safe via `hash_equals()`, accepteert `string\|array` voor secrets (rotation-window), saneert null/empty entries. `sign()` retourneert hex-encoded `hash_hmac`. composer.lock pinned op `e9076d4`. |
| `vendor/emeq/snelstart-api/src/Http/Middleware/VerifySnelstartSignature.php` | SDK middleware auto-aliased onder `verify.snelstart.signature` via `packageBooted()` | ✓ VERIFIED | 60 regels, `final class`. Hardfail-500 bij ontbrekende secret, 401 bij missing/invalid header of signature-mismatch, valid → `$next($request)`. Geen Hub-state in middleware (Hub-agnostisch); auto-alias-registratie via SDK-ServiceProvider — bevestigd door `route:list -v` (geen alias-import in `bootstrap/app.php`). |
| `vendor/emeq/snelstart-api/config/snelstart.php` | 5 `webhook.*` config-keys (secret, secret_next, signature_header, signature_algo, event_id_key) | ✓ VERIFIED | `vendor/emeq/snelstart-api/config/snelstart.php:48-82`. Alle 5 keys env-overridable (`SNELSTART_WEBHOOK_SECRET`, `_SECRET_NEXT`, `_SIGNATURE_HEADER`, `_SIGNATURE_ALGO`, `_EVENT_ID_KEY`); defaults bevestigd door partner 2026-05-17 (#1 + #5). |
| `database/migrations/2026_05_15_000001_create_pass_through_calls_table.php` | Schema heeft `direction`, `event_id`, nullable tenant-FKs, composite indices | ✓ VERIFIED | Per refactor `ec319cd` consolidated van add-migrations naar create-migration. Bevat: `direction` (default `outbound`), `event_id` nullable, `consumer_id/account_id/connection_id` nullable, indices `['direction', 'created_at']`, unique `(provider, event_id)` (`pass_through_calls_provider_event_unique`). |
| `database/migrations/2026_05_14_000003_create_connections_table.php` | Heeft `administratie_id` + composite index `(provider, administratie_id)` | ✓ VERIFIED | `administratie_id` nullable kolom + `$table->index(['provider', 'administratie_id'])`. Niet encrypted, niet hidden (analoog aan `subscription_id`). |
| `app/Models/PassThroughCall.php` | `scopeInbound()` + `scopeOutbound()` + `direction`/`event_id` fillable | ✓ VERIFIED | `PassThroughCall.php:36-44` definieert beide scopes; fillable bevat `direction` en `event_id`. |
| `app/Models/Connection.php` | `administratie_id` in fillable, geen encrypted-cast | ✓ VERIFIED | Fillable line 25; geen `$casts` voor `administratie_id` (per Phase 3 decision 03-01). |
| `config/horizon.php` | `supervisor-webhooks` met queue `webhooks` | ✓ VERIFIED | Supervisor in `defaults`, environment-overrides (production `maxProcesses=5`, local `maxProcesses=1`). `php artisan config:show horizon.defaults.supervisor-webhooks.queue` rapporteert `webhooks`. |
| `tests/Feature/SnelstartWebhookEndToEndTest.php` | E2E acceptance-suite — 5 tests met `test_sc_{1..5}_*`-naming | ✓ VERIFIED | 239 regels, 5 final test-methods + private `postSignedWebhook`-helper die SDK's `SnelstartWebhookSignature::sign` gebruikt. 5/5 / 35 assertions / ~1s. |
| `.docs/decisions/snelstart-webhook-ingress.md` | Hub-internal ADR | ✓ VERIFIED (gitignored) | Bestaat lokaal (5842 bytes); `.docs/decisions/` is gitignored per Hub-conventie, dus niet op remote — verwacht. |

### Key Link Verification

| From | To | Via | Status | Details |
|---|---|---|---|---|
| Inbound POST `/webhooks/snelstart` | SDK-middleware `VerifySnelstartSignature` | `verify.snelstart.signature`-alias auto-aliased door `SnelstartServiceProvider::packageBooted()` | ✓ WIRED | `route:list -v` toont `Emeq\SnelstartApi\Http\Middleware\VerifySnelstartSignature` als resolved middleware; geen Hub-side alias-edit nodig. |
| SDK-middleware (valid HMAC) | `SnelstartWebhookController::__invoke` | Laravel routing-pipeline (`$next($request)`) | ✓ WIRED | Middleware retourneert `$next($request)` op valid-pad; controller bereikt zonder Hub-state-coupling. |
| Controller happy-path | `ForwardSnelstartWebhookToConsumerJob::dispatch()` | Static dispatch + `onQueue('webhooks')` in job-constructor | ✓ WIRED | `SnelstartWebhookController.php:99-103` dispatcht met `($connection, $payload, $eventId ?? 'no-id')`. Bewezen via `Bus::assertDispatched` met closure-check in SC-1 + SC-5. |
| Forward-job | Spatie `WebhookCall` → Consumer-URL | `webhook-server`-vendor + `consumers.webhook_callback_secret` (encrypted at rest) | ✓ WIRED | `ForwardSnelstartWebhookToConsumerJob.php:50-55` doet `WebhookCall::create()->url(...)->payload(...)->useSecret(...)->withHeaders(['X-Emeq-Event-Id' => ...])->dispatch()`. Anti-correlation bewezen in `ForwardSnelstartWebhookToConsumerJobTest::test_handle_uses_consumer_callback_secret_not_partner_secret`. |
| Controller | `pass_through_calls`-tabel | Eloquent `PassThroughCall::create([...])` op alle 4 paden behalve invalid-HMAC | ✓ WIRED | `direction='inbound'` op alle audit-paden; fingerprint via sha256 eerste 12 chars (geen raw body in DB); `upstream_error` voor forensics op malformed/dup/unknown. |
| Webhook routes-bestand | Laravel router | `bootstrap/app.php` `withRouting()->then()` group | ✓ WIRED | `bootstrap/app.php:26` registreert `routes/webhooks.php` onder `api`-middleware-groep. |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|---|---|---|---|---|
| `SnelstartWebhookController` | `$payload['administratieId']` | `$request->json()->all()` (van Snelstart) | Echte payload via E2E-test + Connection-lookup matched op echte DB-row | ✓ FLOWING |
| `SnelstartWebhookController` | `$connection` | `Connection::query()->where('provider','snelstart')->where('administratie_id', ...)->whereNull('revoked_at')->first()` | DB-query met composite index `(provider, administratie_id)` | ✓ FLOWING |
| `ForwardSnelstartWebhookToConsumerJob` | `$consumer` | `$snelstartConnection->account?->consumer` (Eloquent relation-chain) | E2E bewijst Consumer-resolutie via SC-1/SC-5 | ✓ FLOWING |
| `ForwardSnelstartWebhookToConsumerJob` | outbound HMAC `Signature`-header | `hash_hmac('sha256', json_encode($payload), $consumer->webhook_callback_secret)` (Spatie's `DefaultSigner`) | Bewezen via reproductie in `ForwardSnelstartWebhookToConsumerJobTest::test_handle_dispatches_spatie_webhook_with_consumer_secret` | ✓ FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|---|---|---|---|
| Phase 5c-scoped tests groen | `php artisan test --compact --filter='SnelstartWebhook\|ForwardSnelstartWebhook'` | 17 passed / 88 assertions / 1167ms | ✓ PASS |
| Full Hub-suite groen modulo pre-existing failure | `php artisan test --compact` | 523/524 passed / 1801 assertions / 1 incomplete / 1 pre-existing failure (`UserResourceTest::test_super_admin_can_create_user_via_resource`) | ✓ PASS (failure out-of-scope per Phase 9/10) |
| SDK Pest webhook+middleware tests groen | `cd packages/snelstart-api && ./vendor/bin/pest tests/Unit/Webhooks/ tests/Feature/Http/Middleware/` | 15 passed / 24 assertions / 720ms | ✓ PASS |
| Route bestaat met juiste middleware | `php artisan route:list --path=webhooks/snelstart -v` | `POST webhooks/snelstart` → `Webhooks\SnelstartWebhookController` met middleware `api` + `Emeq\SnelstartApi\Http\Middleware\VerifySnelstartSignature` (geen `throttle:api`) | ✓ PASS |
| Horizon webhook-queue geconfigureerd | `php artisan config:show horizon.defaults.supervisor-webhooks.queue` | `0 .. webhooks` | ✓ PASS |
| Job implementeert ShouldQueue | `grep -c 'implements ShouldQueue' app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php` | 1 | ✓ PASS |
| Named route resolves | `php artisan tinker --execute 'echo route("webhooks.snelstart");'` | `http://hub.emeq.test:8090/webhooks/snelstart` | ✓ PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|---|---|---|---|---|
| HUB-06 | Phase 5c (alle 5 plans) | Snelstart webhook-handler op `POST /webhooks/snelstart`, HMAC-verified, Connection-resolutie via `administratieId`, audit + async fan-out + cross-tenant-isolation | ✓ SATISFIED | Alle 5 SC's verifiëerbaar (zie SC-tabel hierboven). REQUIREMENTS.md HUB-06 marker `[x]` + Phase 5c traceability-row Complete. |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|---|---|---|---|---|
| — | — | — | — | Géén `TBD`/`FIXME`/`XXX` debt-markers in 5c-modified files. Geen stubs. Geen empty handlers. Geen hardcoded mock-data in productiepaden. |

### Open Vragen

#### ❓ #4 Snelstart retry-policy — DEFENSIEF GEMITIGEERD

**Status:** Partner heeft niet geantwoord op vraag #4 in respons van 2026-05-17 (Gmail-thread `r-8836998535038336548`). 4/5 andere vragen 🔒 bevestigd (#1 HMAC-header + algo, #2 secret-lifecycle Claude-pick, #3 tenant-routing-veld, #5 event-typen).

**Aanname:** Snelstart doet 5× exponential backoff (Azure APIM-default).

**Mitigatie aanwezig in code:**
- DB-level idempotency-unique `pass_through_calls_provider_event_unique (provider, event_id)` — re-delivery binnen retry-window kan geen dubbele forward triggeren (SC-4 bewijst dit).
- Controller's dup-pad schrijft een audit-rij met `event_id=NULL` zodat de unique-index niet crasht — bewezen in E2E `test_sc_4`.

**Niet-blokkerend voor verifier-pass.** CONTEXT.md classificeert dit expliciet als acceptable risk (regel 88-99). OData-safety-net is captured als optionele follow-up; activatie afhankelijk van partner-respons. Phase 9 admin-replay is een fallback-route, niet een hard requirement voor HUB-06.

### Drift tussen ACCEPTANCE-claims en code

**Geen drift gevonden.** Elke ACCEPTANCE-claim is onafhankelijk reproduceerbaar:

- ACCEPTANCE-tabel SC-tests 1:1 aanwezig in `SnelstartWebhookEndToEndTest` (`test_sc_1_*` t/m `test_sc_5_*`).
- Per-scenario tests in `SnelstartWebhookControllerTest` (7 tests) + `ForwardSnelstartWebhookToConsumerJobTest` (5 tests) bestaan en zijn groen.
- Commit-hashes uit ACCEPTANCE (e.g. `f999b4e`, `a0365a7`, `4ba10d8`, `d18b414`) traceren naar de claimed file-mutaties.
- Schema-consolidatie (`ec319cd refactor(migrations): merge add_* in create_*`) heeft de 05c-01 `add_inbound_columns_*` + `add_administratie_id_*` migrations gemerged in de respectievelijke `create_*`-migrations — eindstaat van schema klopt nog steeds met de invariants (direction-discriminator + nullable FKs + unique-index + administratie_id-kolom + composite index). Drift in artefact-paden tussen 05c-01-SUMMARY ("`_000002_add_inbound_columns_*`" + "`_000003_add_administratie_id_*`") en huidig disk-state is een *historische* deviation die de eindstaat niet aantast. **Niet-blokkerend.**
- Test-baseline (524/523/1 incomplete/1 pre-existing) is gereproduceerd: zelfde failure (`UserResourceTest`), zelfde count.

### Deferred Items (niet-blokkerend)

| # | Item | Addressed In | Evidence |
|---|---|---|---|
| 1 | OData-safety-net polling-job voor verloren retries | post-MVP / v0.3+ (afhankelijk van #4 partner-respons) | CONTEXT.md regel 22, 95, 232 — explicit out-of-scope; idempotency-unique-index dekt happy-path. |
| 2 | Filament admin replay-knop voor gefaalde inbound events | Phase 9 backlog (HUB-OBSERVABILITY) | CONTEXT.md regel 21 — Phase 9 sloot met `PassThroughCallResource` als backlog-item. |

### Gaps Summary

**Geen blokkerende gaps.** Alle 5 HUB-06 Success Criteria zijn onafhankelijk bewezen via groene E2E-suite + per-scenario controller-tests + per-job-tests + SDK Pest-tests + Hub-side route/config/Horizon-spot-checks. De enige open vraag (#4 retry-policy) is defensief gemitigeerd via DB-level idempotency en gemarkeerd als acceptable risk in CONTEXT.md.

Eén pre-existing testfailure (`UserResourceTest::test_super_admin_can_create_user_via_resource`) is Phase 9/10 owner en wordt door alle 5 SUMMARY's expliciet out-of-scope verklaard — vóór 5c had de suite dezelfde baseline (518/519 in plan-03-tijd → 523/524 nu, +5 nieuwe E2E-tests, zelfde failure-baseline).

---

## Eindoordeel

**passed** — Phase 5c (HUB-06) achieved its goal. 5/5 SC's bewezen, 17 Phase-5c tests groen (88 assertions), 15 SDK Pest-tests groen (24 assertions), route + middleware + Horizon-queue correct gewired, anti-correlation invariant T-05c-09 expliciet geverifieerd, geen debt-markers, geen drift tussen ACCEPTANCE en code.

**Open vraag #4 (retry-policy)** wordt als acceptable risk geclassificeerd en is niet-blokkerend voor verifier-pass — code is correct ongeacht werkelijke Snelstart retry-policy.

**Volgende stap:** ROADMAP/REQUIREMENTS markeringen kloppen al (Phase 5c `[x]`, HUB-06 Complete). Phase-merge of `/gsd-ship` is de logische vervolgactie.

---

*Verified: 2026-05-17T23:25:00Z*
*Verifier: Claude (gsd-verifier)*
