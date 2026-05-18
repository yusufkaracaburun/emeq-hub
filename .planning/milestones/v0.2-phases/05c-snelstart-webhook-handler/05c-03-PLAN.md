---
phase: 05c-snelstart-webhook-handler
plan: 03
type: execute
wave: 3
depends_on: [05c-01, 05c-02, 05c-04]
files_modified:
  - routes/webhooks.php
  - app/Http/Controllers/Webhooks/SnelstartWebhookController.php
  - tests/Feature/SnelstartWebhookControllerTest.php

# Note: alle snelstart.webhook.*-keys staan SDK-side in `packages/snelstart-api/config/snelstart.php`
# (post-execute SDK-refactor van plan 02). Deze plan voegt geen config-keys toe.
autonomous: true
requirements: [HUB-06]
tags:
  - laravel
  - routes
  - controller
  - audit-log
  - phpunit

must_haves:
  truths:
    - "`POST /webhooks/snelstart` is publiek bereikbaar zonder Sanctum en zonder `throttle:api`; signature-middleware is de enige gatekeeper"
    - "Een valid HMAC + bekende `administratieId` → 200 + `pass_through_calls`-rij met `direction=inbound`, juiste consumer/account/connection-FKs, status=200 — én `ForwardSnelstartWebhookToConsumerJob` dispatched"
    - "Onbekende `administratieId` met valid HMAC → 200 + audit-rij met `connection_id=NULL` / `account_id=NULL` / `consumer_id=NULL` + `upstream_error='unknown_administratie_id'`, géén job dispatched"
    - "Idempotency: zelfde `event_id` 2× → tweede call krijgt 200 + 1 dup-audit-rij + 0 nieuwe job-dispatches (originele job blijft staan)"
    - "Cross-Consumer-isolation: een webhook met administratieId van Consumer X's Account fan-out alleen naar Consumer X's `webhook_callback_url`"
  artifacts:
    - path: "routes/webhooks.php"
      provides: "Route `POST /webhooks/snelstart` met middleware `verify.snelstart.signature` en naam `webhooks.snelstart`"
      contains: "webhooks.snelstart"
    - path: "app/Http/Controllers/Webhooks/SnelstartWebhookController.php"
      provides: "Single-action invokable controller met audit-write + job-dispatch + idempotency-check"
      contains: "SnelstartWebhookController"
  key_links:
    - from: "POST /webhooks/snelstart"
      to: "SnelstartWebhookController::__invoke"
      via: "Route middleware chain ['api', 'verify.snelstart.signature']"
      pattern: "invokable controller"
    - from: "SnelstartWebhookController::__invoke"
      to: "ForwardSnelstartWebhookToConsumerJob::dispatch"
      via: "Job class import"
      pattern: "post-audit async dispatch"
---

<objective>
De HTTP-laag van de Snelstart-webhook-ingress: route + controller die de signature-middleware afgesloten heeft, payload parsed, Connection resolved, audit schrijft, en de fan-out-job dispatcht.

Purpose: HUB-06 success criteria 1, 3, 4 — alle "valid HMAC"-paden voor known/unknown administratie + idempotency. SC-2 (invalid HMAC) zit al in plan 02; SC-5 (cross-Consumer-isolation) wordt door deze controller mogelijk en in plan 05 als integration-test bewezen.

Output: route-registratie + controller + 6 feature-tests. **Geen** verifier-class en geen job-class — die komen uit plan 02 + 04.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/05c-snelstart-webhook-handler/05c-CONTEXT.md
@CLAUDE.md
@routes/webhooks.php
@app/Http/Controllers/Webhooks/MollieWebhookController.php
@app/Models/Connection.php
@app/Models/PassThroughCall.php
@app/Webhooks/SnelstartSignatureVerifier.php
@app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php

<interfaces>
<!-- Outputs van eerdere plannen die deze controller raakt -->

From plan 05c-02 (Wave 2):
- middleware-alias `verify.snelstart.signature` is registered en kapt invalid HMAC af → 401
- de middleware roept `$next($request)` aan zodra signature valid is; raw body blijft toegankelijk via `$request->getContent()`

From plan 05c-04 (Wave 2):
- `App\Jobs\Webhooks\ForwardSnelstartWebhookToConsumerJob::dispatch(Connection $connection, array $payload, string $eventId)` werkt; queue=`webhooks`

From plan 05c-01 (Wave 1):
- `pass_through_calls` heeft `direction`, `event_id`, nullable consumer/account FKs
- `connections.administratie_id` is queryable via composite index
- `PassThroughCall::factory()->inbound()` voor test-setup
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Route — `POST /webhooks/snelstart`</name>
  <files>routes/webhooks.php</files>
  <read_first>
    - routes/webhooks.php (huidige Mollie-route + Cashier-block — pattern voor `Route::post + middleware + name`)
    - bootstrap/app.php (controleer dat `routes/webhooks.php` al via `withRouting->then`-callback geregistreerd is — bestaat al uit Phase 5a-01)
  </read_first>
  <action>
    Voeg in `routes/webhooks.php`, ná de bestaande Mollie-block (voor de Cashier-block), de Snelstart-route toe:

    ```php
    use App\Http\Controllers\Webhooks\SnelstartWebhookController;

    /*
     * Snelstart webhook-ingress (HUB-06). Eén publieke URL voor alle administraties.
     * Per-Connection routing gebeurt in de controller op payload `administratieId`.
     * Geen Sanctum, geen throttle:api — signature-middleware is de gatekeeper.
     *
     * NB: routes/webhooks.php is geregistreerd via Route::middleware('api')
     * in bootstrap/app.php. De `api`-group heeft `throttle:api` als prepend
     * (zie withMiddleware->api(prepend: ['throttle:api'])). We strippen die
     * expliciet voor déze route via withoutMiddleware — Snelstart kan bursten
     * en throttle'n betekent gemiste events. Mollie- en Cashier-routes blijven
     * onaangetast: scope-minimal.
     */
    Route::post('/webhooks/snelstart', SnelstartWebhookController::class)
        ->middleware(['verify.snelstart.signature'])
        ->withoutMiddleware(['throttle:api'])
        ->name('webhooks.snelstart');
    ```

    Controleer dat de `use`-statement bovenaan staat (groepeer met bestaande webhook-controller-imports).

    Run pint.
  </action>
  <verify>
    <automated>php artisan route:list --except-vendor --path=webhooks/snelstart 2>&1 | grep snelstart</automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "Route::post.*'/webhooks/snelstart'" routes/webhooks.php` == 1
    - `grep -c "verify.snelstart.signature" routes/webhooks.php` == 1
    - `grep -c "webhooks.snelstart" routes/webhooks.php` == 1
    - `grep -c "withoutMiddleware.*throttle:api" routes/webhooks.php` >= 1 (Snelstart-specifieke strip; Mollie-/Cashier-routes intact)
    - `php artisan route:list --except-vendor --path=webhooks/snelstart` toont één POST-route met `verify.snelstart.signature` in de middleware-kolom
    - `php artisan route:list --except-vendor --path=webhooks/snelstart --columns=method,uri,middleware` toont GEEN `throttle:api` voor `/webhooks/snelstart` (regressie-check: andere webhook-routes mogen 'm wél behouden)
  </acceptance_criteria>
  <done>Route is geregistreerd met enkel `api,verify.snelstart.signature` als middleware-chain.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: `SnelstartWebhookController` (single-action invokable)</name>
  <files>app/Http/Controllers/Webhooks/SnelstartWebhookController.php, tests/Feature/SnelstartWebhookControllerTest.php</files>
  <behavior>
    - `__invoke(Request $request)` doet 6 stappen in deze volgorde:
      1. Parse JSON-body → array. Geen array of geen `administratieId`-veld → 400 + audit-row met `upstream_error='malformed_payload'` (consumer/account/connection NULL)
      2. Lees `event_id` (Snelstart-veld ❓; aanname `eventId` — config-driven via `snelstart.webhook.event_id_key` default `eventId`). Null mag, idempotency-check skipt dan.
      3. Idempotency-check: als event_id != null en `PassThroughCall::where('provider','snelstart')->where('event_id', $eventId)->exists()` → 200 + extra audit-row met `upstream_error='duplicate_event'`; géén nieuwe job-dispatch
      4. Connection-resolutie: `Connection::where('provider','snelstart')->where('administratie_id', $payload['administratieId'])->first()`. Niet gevonden → 200 + audit-row met `consumer_id/account_id/connection_id = NULL`, `upstream_error='unknown_administratie_id'`; géén job-dispatch
      5. Audit-row write — direction=inbound, event_id, request_fingerprint = sha256(rawBody)[0..12], path=`/webhooks/snelstart`, status=200, juiste FK-keten
      6. Job-dispatch — `ForwardSnelstartWebhookToConsumerJob::dispatch($connection, $payload, $eventId ?? 'no-id')`
    - Returnt altijd `response('', 200)` op het happy-path (geen JSON-body — Snelstart verwacht alleen 200). 400 op malformed-payload met `response()->json(['error' => 'malformed_payload'], 400)`.
  </behavior>
  <read_first>
    - app/Http/Controllers/Webhooks/MollieWebhookController.php (audit-on-failure-pattern; `WebhookCall::create([...])` → bij ons `PassThroughCall::create`)
    - app/Models/PassThroughCall.php (Fillable + scopes)
    - app/Models/Connection.php (zojuist uitgebreid met `administratie_id`)
    - app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php (dispatch-signature)
  </read_first>
  <action>
    **1. `app/Http/Controllers/Webhooks/SnelstartWebhookController.php`**:

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Http\Controllers\Webhooks;

    use App\Http\Controllers\Controller;
    use App\Jobs\Webhooks\ForwardSnelstartWebhookToConsumerJob;
    use App\Models\Connection;
    use App\Models\PassThroughCall;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use Symfony\Component\HttpFoundation\Response;

    /**
     * Snelstart webhook-ingress (HUB-06).
     *
     * Geverifieerd-signature-pad (middleware verify.snelstart.signature heeft
     * raw body al gevalideerd). Deze controller doet payload-parse, idempotency,
     * Connection-routing op `administratieId`, audit-write en async fan-out.
     *
     * CONTEXT decisions: 05c-CONTEXT.md §<decisions>:
     *  - "Onbekende administratieId" → 200 + NULL-tenant audit, geen fan-out
     *  - "Audit-tabel reuse" → pass_through_calls met direction='inbound'
     *  - "Fan-out timing (async)" → Hub ack't <500ms, Spatie handle't retries
     */
    final class SnelstartWebhookController extends Controller
    {
        public function __invoke(Request $request): Response
        {
            $rawBody = $request->getContent();
            $payload = $request->json()->all();

            if (! is_array($payload) || ! isset($payload['administratieId']) || ! is_string($payload['administratieId'])) {
                $this->auditMalformed($request, $rawBody);

                return response()->json(['error' => 'malformed_payload'], 400);
            }

            $eventIdKey = (string) config('snelstart.webhook.event_id_key', 'eventId');
            $eventId = isset($payload[$eventIdKey]) && is_string($payload[$eventIdKey])
                ? $payload[$eventIdKey]
                : null;

            // Idempotency-check
            if ($eventId !== null) {
                $alreadySeen = PassThroughCall::query()
                    ->inbound()
                    ->where('provider', 'snelstart')
                    ->where('event_id', $eventId)
                    ->exists();

                if ($alreadySeen) {
                    PassThroughCall::create([
                        'direction' => 'inbound',
                        'provider' => 'snelstart',
                        'method' => 'POST',
                        'path' => '/webhooks/snelstart',
                        'status' => 200,
                        'duration_ms' => 0,
                        'request_fingerprint' => substr(hash('sha256', $rawBody), 0, 12),
                        // Bewust GEEN event_id zetten — anders triggert het de
                        // (provider, event_id) unique index. Dup-rij heeft event_id NULL
                        // maar upstream_error 'duplicate_event' voor traceability.
                        'upstream_error' => 'duplicate_event',
                    ]);

                    return response('', 200);
                }
            }

            // Connection-resolutie via administratieId
            $connection = Connection::query()
                ->where('provider', 'snelstart')
                ->where('administratie_id', $payload['administratieId'])
                ->whereNull('revoked_at')
                ->first();

            if ($connection === null) {
                PassThroughCall::create([
                    'direction' => 'inbound',
                    'provider' => 'snelstart',
                    'method' => 'POST',
                    'path' => '/webhooks/snelstart',
                    'status' => 200,
                    'duration_ms' => 0,
                    'request_fingerprint' => substr(hash('sha256', $rawBody), 0, 12),
                    'event_id' => $eventId,
                    'upstream_error' => 'unknown_administratie_id',
                ]);

                return response('', 200);
            }

            // Happy path — audit + dispatch
            PassThroughCall::create([
                'direction' => 'inbound',
                'consumer_id' => $connection->account->consumer_id,
                'account_id' => $connection->account_id,
                'connection_id' => $connection->id,
                'provider' => 'snelstart',
                'method' => 'POST',
                'path' => '/webhooks/snelstart',
                'status' => 200,
                'duration_ms' => 0,
                'request_fingerprint' => substr(hash('sha256', $rawBody), 0, 12),
                'event_id' => $eventId,
            ]);

            ForwardSnelstartWebhookToConsumerJob::dispatch(
                $connection,
                $payload,
                $eventId ?? 'no-id',
            );

            return response('', 200);
        }

        private function auditMalformed(Request $request, string $rawBody): void
        {
            PassThroughCall::create([
                'direction' => 'inbound',
                'provider' => 'snelstart',
                'method' => $request->getMethod(),
                'path' => '/webhooks/snelstart',
                'status' => 400,
                'duration_ms' => 0,
                'request_fingerprint' => $rawBody !== ''
                    ? substr(hash('sha256', $rawBody), 0, 12)
                    : null,
                'upstream_error' => 'malformed_payload',
            ]);
        }
    }
    ```

    **Note** — `snelstart.webhook.event_id_key` is geland in plan 05c-02 Task 1 (alle webhook-config-keys staan daar samen). Deze controller leest 'm alleen, voegt geen nieuwe key toe. Default `'eventId'` werkt zonder env override.

    **2. Test `tests/Feature/SnelstartWebhookControllerTest.php`** met `RefreshDatabase`. Helper-methode in test-class genereert geldige signature met `SnelstartSignatureVerifier::sign()` zodat de middleware passeert:

    Scenarios:
    1. `test_valid_webhook_with_known_administratie_dispatches_job` — maak Consumer + Account + Snelstart-Connection met `administratie_id='aaa-111'`; `Bus::fake();` postJson met body `{"administratieId":"aaa-111","eventId":"evt-1",...}` + geldige sig → 200 + `PassThroughCall::inbound()->count() === 1` met juiste FKs + `Bus::assertDispatched(ForwardSnelstartWebhookToConsumerJob::class)`
    2. `test_unknown_administratie_returns_200_with_null_tenant_audit` — geen Connection voor `bbb-222`; postJson → 200 + audit-row met alle drie FKs NULL + `upstream_error='unknown_administratie_id'` + `Bus::assertNothingDispatched()`
    3. `test_idempotent_duplicate_event_id_does_not_redispatch` — setup zoals scenario 1; eerste call → 1 audit + 1 job; tweede zelfde-event-id call → 200 + tweede audit-row (`upstream_error='duplicate_event'`, event_id NULL) + nog steeds maar 1 dispatch
    4. `test_malformed_payload_returns_400_with_audit` — postJson zonder `administratieId`-veld (maar wel geldige sig over de body) → 400 + audit-row met `upstream_error='malformed_payload'` + geen dispatch
    5. `test_invalid_signature_returns_401_without_audit` — postJson met fout `X-SnelStart-Signature` → 401 + `PassThroughCall::count() === 0` (regressie-check op middleware uit plan 02; bevestigt routing-chain correct hookt)
    6. `test_revoked_connection_treated_as_unknown` — Connection met `revoked_at = now()`; webhook met die administratie → 200 + NULL-tenant audit + geen dispatch
    7. `test_cross_consumer_isolation_routes_to_correct_consumer` — twee Consumers elk met een Account + Snelstart-Connection (verschillende `administratie_id`); webhook voor Consumer-A's administratie → audit-row heeft `consumer_id` van A + job-payload-Connection wijst naar Consumer A's Account (via `Bus::assertDispatched`-callback-inspectie)

    Run pint + `php artisan test --compact --filter=SnelstartWebhookControllerTest`.
  </action>
  <verify>
    <automated>php artisan test --compact --filter=SnelstartWebhookControllerTest</automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "final class SnelstartWebhookController" app/Http/Controllers/Webhooks/SnelstartWebhookController.php` == 1
    - `grep -c "ForwardSnelstartWebhookToConsumerJob::dispatch" app/Http/Controllers/Webhooks/SnelstartWebhookController.php` == 1
    - `grep -c "unknown_administratie_id" app/Http/Controllers/Webhooks/SnelstartWebhookController.php` >= 1
    - `grep -c "duplicate_event" app/Http/Controllers/Webhooks/SnelstartWebhookController.php` >= 1
    - `grep -c "'administratie_id'" app/Http/Controllers/Webhooks/SnelstartWebhookController.php` >= 1
    - `php artisan test --compact --filter=SnelstartWebhookControllerTest` exit 0, ≥7 tests passed
    - `php artisan test --compact --filter=VerifySnelstartSignatureMiddlewareTest` blijft groen (regressie)
    - `php artisan test --compact --filter=MollieWebhook` blijft groen (regressie op andere webhook-controller)
  </acceptance_criteria>
  <done>Alle 7 scenarios groen; controller respecteert anti-amplification (geen audit op invalid-sig) en anti-retry-storm (200 op unknown administratie).</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Middleware ↔ controller | Signature is al gevalideerd in middleware; controller vertrouwt `$request->getContent()` als authentieke Snelstart-data |
| Controller ↔ Connection-resolutie | `administratie_id`-lookup is een query-join naar de juiste Consumer; misroute = cross-tenant leak |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05c-14 | Information disclosure | Audit-row leakt raw payload | mitigate | Geen body-snapshot — alleen fingerprint sha256(body)[0..12]. Volgt 5b-pattern. |
| T-05c-15 | Tampering | Idempotency-bypass via NULL event_id | accept | Snelstart hoort altijd event_id mee te sturen; bij ontbrekend event_id geen idempotency-guarantee, maar fan-out gebeurt wél (consumer dedupe via eigen logic). Beperking gedocumenteerd in SUMMARY. |
| T-05c-16 | Repudiation | Snelstart retry-storm op 5xx | mitigate | Controller returnt 200 op unknown administratie (CONTEXT.md decision). 4xx alleen op malformed-payload; Snelstart retried 4xx niet (RFC-compliant). |
| T-05c-17 | Spoofing | Forged `administratieId` in payload | accept | Aanvaller moet eerst valid HMAC kunnen forgen — als dat lukt is signature-laag al gebroken (out-of-scope voor controller). Lookup-laag retournt NULL-Connection bij random UUID. |
| T-05c-18 | Information disclosure | Cross-Consumer-leak via misroute | mitigate | SC-5 test in plan 05 + scenario 7 in deze test bewijst single-Consumer-routing via `connection.account.consumer_id`-chain. |
</threat_model>

<verification>
- 7 controller-feature-tests groen
- 5a-Mollie-controller-tests blijven groen (regressie)
- `php artisan route:list --except-vendor` toont `/webhooks/snelstart` met juiste middleware-chain
- Pint clean
</verification>

<success_criteria>
- HUB-06 SC-1 (valid HMAC + bekende administratie → 200 + audit + dispatch) gedekt door scenario 1
- HUB-06 SC-3 (onbekende administratie → 200 + NULL-tenant + geen fan-out) gedekt door scenario 2
- HUB-06 SC-4 (idempotency event_id) gedekt door scenario 3
- HUB-06 SC-5 (cross-Consumer-isolation) gedekt door scenario 7
- Volledige Hub-testsuite groen
</success_criteria>

<output>
Na completion: schrijf `.planning/phases/05c-snelstart-webhook-handler/05c-03-SUMMARY.md`; vermeld welke SC's al door deze tests gedekt zijn en welke nog door plan 05 met end-to-end integration-test bevestigd worden.
</output>
