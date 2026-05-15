---
phase: 05c-snelstart-webhook-handler
plan: 04
type: execute
wave: 2
depends_on: [05c-01]
files_modified:
  - app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php
  - config/horizon.php
  - tests/Feature/ForwardSnelstartWebhookToConsumerJobTest.php
autonomous: true
requirements: [HUB-06]
tags:
  - laravel
  - queue
  - horizon
  - spatie-webhook-server
  - phpunit

must_haves:
  truths:
    - "Een dispatched job verstuurt de geverifieerde Snelstart-payload als HMAC-gesigneerde POST naar `consumers.webhook_callback_url`, gesigneerd met `consumers.webhook_callback_secret` — gescheiden van de inbound-Snelstart-secret (anti-correlation)"
    - "Job draait op Horizon-queue `webhooks`; deze queue staat geconfigureerd in `config/horizon.php` voor zowel local als production"
    - "Consumer zonder `webhook_callback_url` → job exit silent zonder retry-loop"
    - "Failed-job-pad (na Spatie's eigen retries) landt in Horizon `failed_jobs`; geen partial state op de consumer-callback-URL"
  artifacts:
    - path: "app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php"
      provides: "Queueable job die Spatie's `Spatie\\WebhookServer\\WebhookCall` aanstuurt voor outbound fan-out naar Consumer"
      contains: "ForwardSnelstartWebhookToConsumerJob"
    - path: "config/horizon.php"
      provides: "Supervisor-config voor de `webhooks`-queue (apart van `default`, kan eigen scaling-policy)"
      contains: "'webhooks'"
  key_links:
    - from: "ForwardSnelstartWebhookToConsumerJob::handle"
      to: "Spatie\\WebhookServer\\WebhookCall::create()"
      via: "DI-vrije static factory"
      pattern: "outbound webhook fan-out"
    - from: "job onQueue('webhooks')"
      to: "config('horizon.defaults.supervisor-1.queue') / environment-supervisor"
      via: "Horizon supervisor balance"
      pattern: "queue routing"
---

<objective>
Async fan-out job voor geverifieerde Snelstart-webhooks naar de Consumer-callback-URL — analoog aan Mollie's `ForwardMollieWebhookToConsumer`, maar dispatcht op een aparte Horizon-queue zodat Snelstart-bursten Mollie-payments niet vertragen.

Purpose: HUB-06 success criterion 1 — "ForwardSnelstartWebhookToConsumerJob dispatched". CONTEXT decision "Locked — Fan-out timing (async)" — Hub ack't Snelstart binnen <500ms; Consumer-callback wordt asynchroon afgehandeld via Spatie's eigen retry-mechanisme.

Output: job-class onder `app/Jobs/Webhooks/`, een nieuwe Horizon-supervisor voor de `webhooks`-queue, en een job-feature-test met `Bus::fake()` + Spatie-`WebhookCall`-spy. **Geen** route, controller, of audit-write (dat zit in plan 03).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/05c-snelstart-webhook-handler/05c-CONTEXT.md
@CLAUDE.md
@app/Jobs/ForwardMollieWebhookToConsumer.php
@config/horizon.php

<interfaces>
<!-- Spatie webhook-server API + Mollie-fan-out-pattern -->

From vendor/spatie/laravel-webhook-server/src/WebhookCall.php:
```php
class WebhookCall {
    public static function create(): self;
    public function url(string $url): self;
    public function payload(array $payload): self;
    public function useSecret(string $secret): self;
    public function dispatch(): void;
}
```

From app/Jobs/ForwardMollieWebhookToConsumer.php:
```php
class ForwardMollieWebhookToConsumer implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public function __construct(public Connection $mollieConnection, public array $payload);
    public function handle(): void { /* silent skip op missing url; Spatie::dispatch */ }
}
```

From app/Models/Consumer.php (relevante velden):
```php
$consumer->webhook_callback_url; // ?string
$consumer->webhook_callback_secret; // string|null (encrypted cast)
```
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Job-class `ForwardSnelstartWebhookToConsumerJob`</name>
  <files>app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php, tests/Feature/ForwardSnelstartWebhookToConsumerJobTest.php</files>
  <behavior>
    - Constructor accepteert `Connection $snelstartConnection` + `array $payload` + `string $eventId`
    - `handle()` skip silent als `$connection->account?->consumer?->webhook_callback_url` null/empty is — geen exception, geen retry, géén audit-update
    - `handle()` dispatcht een Spatie `WebhookCall` met url + payload + secret (Consumer's `webhook_callback_secret`); voegt `event_id` toe als header via `withHeaders(['X-Emeq-Event-Id' => $eventId])` (compat-pattern; Consumer kan dedupen op zijn end)
    - Job wordt expliciet op queue `webhooks` gedispatcht (via `onQueue` constructor of class-attribute)
    - Spatie's eigen `$tries = 3` config + backoff komt uit `config('webhook-server.tries')` — geen eigen retry-config op de job
  </behavior>
  <read_first>
    - app/Jobs/ForwardMollieWebhookToConsumer.php (volledige pattern — `Spatie\WebhookServer\WebhookCall::create()->...->dispatch()`)
    - vendor/spatie/laravel-webhook-server/src/WebhookCall.php (geverifieerde method-signatures: `url()`, `payload()`, `useSecret()`, `withHeaders()`, `dispatch()`)
    - app/Models/Consumer.php (voor `webhook_callback_url` + `webhook_callback_secret` accessors)
    - app/Models/Account.php + app/Models/Connection.php (voor `connection->account->consumer`-chain)
  </read_first>
  <action>
    **1. `app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php`** (nieuwe sub-folder voor webhook-fan-out-jobs):

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Jobs\Webhooks;

    use App\Models\Connection;
    use Illuminate\Bus\Queueable;
    use Illuminate\Contracts\Queue\ShouldQueue;
    use Illuminate\Foundation\Bus\Dispatchable;
    use Illuminate\Queue\InteractsWithQueue;
    use Illuminate\Queue\SerializesModels;
    use Spatie\WebhookServer\WebhookCall;

    /**
     * Fan-out van een geverifieerde Snelstart-webhook naar de Consumer-callback-URL.
     *
     * Anti-correlation: outbound HMAC gebruikt `consumers.webhook_callback_secret`
     * (per-Consumer, encrypted). Snelstart's inbound `SNELSTART_WEBHOOK_SECRET`
     * komt hier nooit langs.
     *
     * Consumer zonder `webhook_callback_url` → silent skip (geen retry).
     * Spatie's webhook-server doet retry/backoff per `config/webhook-server.php`.
     */
    final class ForwardSnelstartWebhookToConsumerJob implements ShouldQueue
    {
        use Dispatchable;
        use InteractsWithQueue;
        use Queueable;
        use SerializesModels;

        /**
         * @param  array<string, mixed>  $payload
         */
        public function __construct(
            public Connection $snelstartConnection,
            public array $payload,
            public string $eventId,
        ) {
            $this->onQueue('webhooks');
        }

        public function handle(): void
        {
            $consumer = $this->snelstartConnection->account?->consumer;

            if ($consumer === null || ! $consumer->webhook_callback_url) {
                return;
            }

            WebhookCall::create()
                ->url($consumer->webhook_callback_url)
                ->payload($this->payload)
                ->useSecret((string) $consumer->webhook_callback_secret)
                ->withHeaders(['X-Emeq-Event-Id' => $this->eventId])
                ->dispatch();
        }
    }
    ```

    **2. Test `tests/Feature/ForwardSnelstartWebhookToConsumerJobTest.php`** met `RefreshDatabase`:

    **Testing-pattern (gepind):** Spatie's `WebhookCall::create()->dispatch()` instantieert intern `Spatie\WebhookServer\CallWebhookJob` en dispatcht 'm via Laravel's Bus. We faken die job-class expliciet en inspecteren via de **public** job-properties (`webhookUrl`, `payload`, `headers`, `secret`) — geen reflectie, geen private state. Voor scenario 1 (outer-job dispatched op `webhooks`-queue) faken we onze eigen job-class.

    ```php
    use App\Jobs\Webhooks\ForwardSnelstartWebhookToConsumerJob;
    use Illuminate\Support\Facades\Bus;
    use Spatie\WebhookServer\CallWebhookJob;
    ```

    Scenarios:
    1. `test_job_dispatches_to_webhooks_queue` — `Bus::fake([ForwardSnelstartWebhookToConsumerJob::class]);` dispatch via class-static; `Bus::assertDispatchedOn('webhooks', ForwardSnelstartWebhookToConsumerJob::class)`
    2. `test_handle_skips_silently_without_callback_url` — Consumer zonder `webhook_callback_url`; `Bus::fake([CallWebhookJob::class]);` roep `(new ForwardSnelstartWebhookToConsumerJob(...))->handle()` direct aan (NIET via Bus dispatch); `Bus::assertNotDispatched(CallWebhookJob::class)`
    3. `test_handle_dispatches_spatie_webhook_with_consumer_secret` — Consumer met url+secret; `Bus::fake([CallWebhookJob::class]);` `(new Job(...))->handle();` `Bus::assertDispatched(CallWebhookJob::class, function (CallWebhookJob $job) use ($consumer): bool { return $job->webhookUrl === $consumer->webhook_callback_url && $job->payload === $expectedPayload && $job->secret === $consumer->webhook_callback_secret; })`
    4. `test_handle_includes_event_id_header` — zelfde fake; assert `$job->headers['X-Emeq-Event-Id'] === 'evt-001'` in de callback
    5. `test_handle_uses_consumer_callback_secret_not_partner_secret` — `config(['services.snelstart.webhook_secret' => 'partner-only'])`; consumer-secret 'consumer-only'; assert `$job->secret === 'consumer-only'` én `$job->secret !== 'partner-only'` (anti-correlation invariant — twee asserts in één callback)

    Alle scenarios gebruiken Spatie's `CallWebhookJob` public properties (`webhookUrl`, `payload`, `headers`, `secret`). Geen reflectie op private state.

    Run pint + `php artisan test --compact --filter=ForwardSnelstartWebhookToConsumerJobTest`.
  </action>
  <verify>
    <automated>php artisan test --compact --filter=ForwardSnelstartWebhookToConsumerJobTest</automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "final class ForwardSnelstartWebhookToConsumerJob" app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php` == 1
    - `grep -c "implements ShouldQueue" app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php` == 1
    - `grep -c "onQueue('webhooks')" app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php` == 1
    - `grep -c "useSecret.*webhook_callback_secret\|webhook_callback_secret" app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php` >= 1
    - `grep -c "Spatie\\\\WebhookServer\\\\WebhookCall" app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php` >= 1
    - `php artisan test --compact --filter=ForwardSnelstartWebhookToConsumerJobTest` exit 0, ≥5 tests passed
    - Geen regressie: `php artisan test --compact --filter=ForwardMollieWebhookTest` (als die bestaat) blijft groen
  </acceptance_criteria>
  <done>Job dispatcht op `webhooks`-queue, gebruikt consumer-secret (niet partner-secret), skip silent zonder callback-URL.</done>
</task>

<task type="auto">
  <name>Task 2: Horizon — `webhooks`-queue supervisor</name>
  <files>config/horizon.php</files>
  <read_first>
    - config/horizon.php (huidige `supervisor-1`-block + `environments`-overrides)
    - .planning/phases/05c-snelstart-webhook-handler/05c-CONTEXT.md (decision: queue `webhooks`, async fan-out)
  </read_first>
  <action>
    Breid `config/horizon.php` uit zodat Horizon zowel `default` als `webhooks` queues bedient. Twee plekken aanpassen:

    **1. Top-level `defaults`-block** — voeg een tweede supervisor toe:

    ```php
    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            // ... bestaande config ...
        ],
        'supervisor-webhooks' => [
            'connection' => 'redis',
            'queue' => ['webhooks'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,         // outer-tries; Spatie's webhook-server doet eigen retries op de CallWebhookJob
            'timeout' => 30,      // outbound HTTP-call < 30s
            'nice' => 0,
        ],
    ],
    ```

    **2. `environments` → `production` + `local`** — beide krijgen óók `supervisor-webhooks`. Voor production `maxProcesses` op 3-5 (configureerbaar); voor local `maxProcesses: 1` voldoende. Houd de bestaande overrides voor `supervisor-1` intact.

    **3. Smoke** — `php artisan horizon:status` mag geen config-fouten teruggeven (lokaal niet draaiend kan ook — dan exit code !=0 OK, maar geen PHP parse-error).

    Run pint na de wijziging.
  </action>
  <verify>
    <automated>php artisan config:show horizon.defaults 2>&1 | grep -E "supervisor-(1|webhooks)"</automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "'supervisor-webhooks'" config/horizon.php` >= 1
    - `grep -c "'queue' => \\['webhooks'\\]" config/horizon.php` >= 1
    - `php artisan config:show horizon.defaults.supervisor-webhooks.queue` exit 0 en toont `webhooks`
    - `php -l config/horizon.php` geeft `No syntax errors detected`
  </acceptance_criteria>
  <done>Twee supervisors gedefinieerd voor `default` + `webhooks`; geen parse-fouten; environments-overrides intact voor beide.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Hub-queue ↔ Consumer-callback (public internet) | Outbound HMAC met per-Consumer secret; secret encrypted at rest, raw alleen kort in geheugen tijdens dispatch |
| Inbound partner-secret ↔ outbound consumer-secret | Anti-correlation invariant — verschillende secrets, anders kan een gelekte partner-secret consumer-callbacks forgen |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05c-09 | Spoofing | Consumer ontvangt forged callback | mitigate | Per-Consumer `webhook_callback_secret` (encrypted) staat los van partner-secret; consumer verifieert HMAC met zijn eigen secret. |
| T-05c-10 | Tampering | Payload-mutatie tussen Hub en Consumer | mitigate | Spatie's webhook-server doet HMAC over payload; tampering invalideert signature aan consumer-zijde. |
| T-05c-11 | Repudiation | Consumer ontkent ontvangst | accept | Spatie's `webhook_calls`-tabel (server-side) houdt log van outbound; correlatie via `event_id`-header. Volledige tracing in plan 03 (audit-row). |
| T-05c-12 | Information disclosure | `payload` bevat tenant-data van Consumer A in Consumer B's callback | mitigate | `$connection->account->consumer` is de routing-bron — single Connection → single Consumer-chain. Cross-tenant-test in plan 05 SC-5. |
| T-05c-13 | Denial of service | Burst-fan-out blokkeert Mollie-payments-queue | mitigate | Aparte `webhooks`-supervisor in Horizon — Snelstart-bursten isoleren niet de `default`-queue waar Cashier-jobs op zitten. |
</threat_model>

<verification>
- 5 job-feature-tests groen
- Horizon-config heeft 2 supervisors zonder syntax-fouten
- Bestaande Mollie-fan-out-job (`ForwardMollieWebhookToConsumer`) blijft op `default`-queue (regressie-check)
- Pint clean
</verification>

<success_criteria>
- `App\Jobs\Webhooks\ForwardSnelstartWebhookToConsumerJob` dispatcht op queue `webhooks`
- Outbound HMAC gebruikt `consumers.webhook_callback_secret`, niet de inbound partner-secret
- Horizon heeft een `webhooks`-supervisor naast `default`
- Volledige Hub-testsuite groen
</success_criteria>

<output>
Na completion: schrijf `.planning/phases/05c-snelstart-webhook-handler/05c-04-SUMMARY.md`; vermeld de exacte class-FQN en queue-naam zodat plan 03 ze direct kan dispatchen + plan 05 ze kan asserten.
</output>
