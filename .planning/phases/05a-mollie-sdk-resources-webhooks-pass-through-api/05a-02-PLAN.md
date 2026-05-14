---
phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
plan: 02
type: execute
wave: 2
depends_on: [05a-01]
files_modified:
  - app/Http/Controllers/Webhooks/MollieWebhookController.php
  - app/Jobs/ForwardMollieWebhookToConsumer.php
  - routes/webhooks.php
  - bootstrap/app.php
  - config/webhook-server.php
  - config/webhook-client.php
  - tests/Feature/Webhooks/MollieWebhookSignatureTest.php
  - tests/Feature/Webhooks/MollieWebhookFanOutTest.php
  - tests/Feature/Webhooks/MollieWebhookAntiSpoofingTest.php
autonomous: true
requirements: [MOLL-04, HUB-03]
tags:
  - laravel
  - mollie
  - webhooks
  - signature-verify
  - spatie-webhook-server
  - phpunit

must_haves:
  decisions: [D-07, D-08, D-09]
  truths:
    - "MOLL-04 SC-3: POST /webhooks/mollie/{connection_id} met geldige X-Mollie-Signature → 202 + audit-rij in webhook_calls + ForwardMollieWebhookToConsumer job dispatched"
    - "MOLL-04 SC-3: Tampered signature → 400 + audit-rij met exception-veld + GEEN job dispatched + GEEN doorgegeven aan Consumer-callback"
    - "MOLL-04 missing-signature-header → 400 + audit-rij + geen dispatch"
    - "Replay/unknown connection_id → 410 Gone (Mollie retried niet bij 4xx anders dan 408/429)"
    - "Anti-spoofing: webhook met `id` die deze Connection's access_token niet kan ophalen → 400 + audit + geen dispatch"
    - "ForwardMollieWebhookToConsumer dispatcht via spatie/laravel-webhook-server naar consumer.webhook_callback_url met HMAC-signed payload via consumer.webhook_callback_secret"
    - "routes/webhooks.php is publiek (geen Sanctum) en geregistreerd in bootstrap/app.php's withRouting"
  artifacts:
    - path: "app/Http/Controllers/Webhooks/MollieWebhookController.php"
      provides: "__invoke(Request, int $connection_id) — signature-verify → connection-lookup → anti-spoofing-fetch → audit → dispatch → 202"
      contains: "class MollieWebhookController"
    - path: "app/Jobs/ForwardMollieWebhookToConsumer.php"
      provides: "Queueable Spatie WebhookCall::create()->url()->payload()->useSecret()->dispatch() naar Consumer-callback-URL"
      contains: "class ForwardMollieWebhookToConsumer"
    - path: "routes/webhooks.php"
      provides: "POST /webhooks/mollie/{connection_id} → MollieWebhookController, no auth, signature-verified"
      contains: "/webhooks/mollie/{connection_id}"
    - path: "bootstrap/app.php"
      provides: "withRouting then-callback laadt routes/webhooks.php als api-middleware-group"
      contains: "routes/webhooks.php"
  key_links:
    - from: "MollieWebhookController"
      to: "MollieWebhookSignature::verify (SDK helper)"
      via: "MollieWebhookSignature::verify($request, config('services.mollie.webhook_secret'))"
      pattern: "MollieWebhookSignature::verify"
    - from: "MollieWebhookController"
      to: "ForwardMollieWebhookToConsumer (queueable job)"
      via: "ForwardMollieWebhookToConsumer::dispatch($connection, $payload)"
      pattern: "ForwardMollieWebhookToConsumer::dispatch"
    - from: "ForwardMollieWebhookToConsumer"
      to: "Spatie WebhookCall (laravel-webhook-server)"
      via: "Spatie\\WebhookServer\\WebhookCall::create()->url(...)->payload(...)->useSecret(...)->dispatch()"
      pattern: "Spatie\\\\WebhookServer\\\\WebhookCall"
    - from: "MollieWebhookController failure-paths"
      to: "Spatie webhook_calls audit-tabel"
      via: "WebhookCall::create([... 'exception' => ... ])"
      pattern: "WebhookCall::create"
---

<objective>
Webhook-ingress + fan-out voor Mollie Connect-webhooks. Signature-verifie via SDK helper, anti-spoofing-fetch (D-08 stap 3), inkomend audit naar Spatie's `webhook_calls`, queueable fan-out via spatie/laravel-webhook-server naar Consumer-callback-URL met HMAC-signed payload.

Purpose: MOLL-04 (webhook-verifier) + HUB-03 webhook-deel. CONTEXT D-07 (route-shape met `{connection_id}` als Hub-interne PK in nieuwe `routes/webhooks.php`), D-08 (zes-stappen-flow), D-09 (Consumer-niveau callback-URL).

Output: 1 controller + 1 job + 1 nieuwe route-file + bootstrap-registratie + Spatie webhook-client/server-config + 3 feature-tests.

Composer-deps: `spatie/laravel-webhook-server` en `spatie/laravel-webhook-client` MOETEN beide als dependency staan in `composer.json`. Webhook-client (Spatie's `webhook_calls`-tabel) bestaat al sinds Phase 0 (zie migration `2026_05_13_223628_create_webhook_calls_table.php`); webhook-server is nieuw — verifieer en `composer require spatie/laravel-webhook-server` indien nog niet aanwezig.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-CONTEXT.md
@.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-PATTERNS.md
@.docs/partners/mollie/webhooks-overview.md
@.docs/partners/mollie/payments-api.md
@CLAUDE.md
@.ai/rules/global.md
@app/Http/Controllers/Api/V1/OAuth/CallbackController.php
@app/Mollie/MollieConnectionContext.php
@app/Mollie/HubMollieCredentialResolver.php
@app/Models/Connection.php
@app/Models/Consumer.php
@app/Models/Account.php
@bootstrap/app.php
@routes/api.php
@composer.json
@database/migrations/2026_05_13_223628_create_webhook_calls_table.php
@database/migrations/2026_05_13_223629_add_attachments_to_webhook_calls_table.php
@packages/mollie-api/src/Webhooks/MollieWebhookSignature.php
@packages/mollie-api/src/Mollie.php

<interfaces>
<!-- Bestaande contracten die dit plan consumeert. NIET wijzigen. -->

From packages/mollie-api/src/Webhooks/MollieWebhookSignature.php (Phase 2 SDK):
```php
final class MollieWebhookSignature {
    /**
     * Verifieert X-Mollie-Signature header.
     * @return bool true=geldig; false=missende header
     * @throws \Mollie\Api\Exceptions\InvalidSignatureException bij tampered signature
     */
    public static function verify(\Illuminate\Http\Request $request, string|array $signingSecrets): bool;

    /** Voor tests: genereer geldige signature voor een payload */
    public static function sign(string $payload, string $signingSecret): string;
}
```

From Spatie webhook-client (already installed — used voor INKOMENDE audit):
```php
namespace Spatie\WebhookClient\Models;
class WebhookCall extends \Illuminate\Database\Eloquent\Model {
    // table: webhook_calls
    // fillable: name, url, headers (json), payload (json), exception (text)
}
```

From Spatie webhook-server (TO BE INSTALLED — for OUTGOING fan-out):
```php
namespace Spatie\WebhookServer;
class WebhookCall {
    public static function create(): self;
    public function url(string $url): self;
    public function payload(array $payload): self;
    public function useSecret(string $secret): self;
    public function uuid(string $uuid): self;             // optioneel
    public function meta(array $meta): self;               // optioneel
    public function dispatch(): \Illuminate\Foundation\Bus\PendingDispatch;
}
```

From app/Mollie/MollieConnectionContext.php (Phase 4):
```php
final class MollieConnectionContext {
    public function set(\App\Models\Connection $connection): void;
    public function current(): \App\Models\Connection;
    public function has(): bool;
}
```

From packages/mollie-api/src/Mollie.php (Phase 2):
```php
class Mollie {
    /** Bouwt fresh MollieApiClient per call met current tenant credentials */
    public function client(): \Mollie\Api\MollieApiClient;
}
// Facade: Emeq\MollieApi\Facades\Mollie  (alias 'EmeqMollie' als config('mollie.facade_alias') gezet)
```

From .docs/partners/mollie/webhooks-overview.md:
- Header: `X-Mollie-Signature: sha256=<hex>` (HMAC-SHA256 op raw body)
- Mollie retried bij 5xx; bij 4xx (408/429 expected) — andere 4xx (zoals 400/410) zijn permanent.
- Connect-webhooks zijn platform-signed met de partner's webhook-secret (één per Mollie Partner-account).
- Body bevat minimaal `{"id":"tr_..."}` voor Payments; voor Subscriptions/Refunds andere prefix.
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 0 (pre-flight): Verify vendor-API assumptions voor webhook-pad</name>
  <files>
    (read-only — geen wijzigingen, alleen vendor-discovery)
  </files>
  <behavior>
    Pre-flight verify per PATTERNS.md "Open verifie-punten". Blokkerende vendor-discoveries hier oppervlakken vóór Task 1 start, NIET mid-controller.

    **Verifie-punt 1 — RateLimitException retry-after extractie:**
    - Lees `vendor/mollie/mollie-api-php/src/Exceptions/RateLimitException.php` (of equivalente exception-klasse).
    - Bevestig hoe `Retry-After`-header uit Mollie's response wordt geëxposeerd: via `getHeader('Retry-After')`, public property `$retryAfter`, of `$response->getHeader('Retry-After')`. Documenteer in plan-summary welke methode-naam werkt.

    **Verifie-punt 2 — `Mollie::class` singleton vs bind:**
    - Lees `packages/mollie-api/src/MollieServiceProvider.php` rond `register()`.
    - Bevestig of `Mollie::class` als `singleton` of `bind` is geregistreerd. Als `singleton`: na `MollieConnectionContext::set()` mid-request blijft een eerder gebouwde MollieApiClient kleven aan oude credentials → `forgetInstance(Mollie::class)` of `forgetInstance(MollieApiClient::class)` nodig in webhook-controller na `set()`. Als `bind`: per-call fresh, geen forgetInstance nodig.
    - Documenteer uitkomst — bepaalt of MollieWebhookController na anti-spoofing-fetch een instance-clear moet doen.

    **Verifie-punt 3 — webhook-payload format voor non-payment-events (W2):**
    - Lees `.docs/partners/mollie/webhooks-overview.md` sectie 'webhook payload format' + 'event types'.
    - Bevestig of Mollie voor subscription-renewal-events de Payment-`id` (`tr_*`) stuurt of de Subscription-`id` (`sub_*`) of Refund-`id` (`re_*`).
    - Als alleen `tr_*`: huidige anti-spoofing-strategie (`payments->get($id)`) blijft correct voor v0.2; documenteer aanname. Als andere prefixes: scope-clip naar payment-events-only of pas anti-spoofing-fetch aan met id-prefix-detectie (`tr_` → `payments->get`, `sub_` → `customerSubscriptions->getForId`, etc.).

    **Output:** korte tekstuele log in summary met de drie bevindingen + welke implementatie-aanpassingen Task 2 nodig heeft.
  </behavior>
  <read_first>
    - vendor/mollie/mollie-api-php/src/Exceptions/RateLimitException.php (verifieer Retry-After extractie)
    - packages/mollie-api/src/MollieServiceProvider.php (singleton vs bind)
    - .docs/partners/mollie/webhooks-overview.md sectie 'webhook payload format' + 'event types'
  </read_first>
  <action>
    Lees de drie bronnen, verzamel uitkomst per verifie-punt. Geen file-wijzigingen. Documenteer resultaat in `.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-02-PREFLIGHT.md` met sectie per verifie-punt:

    ```markdown
    # Plan 05a-02 Pre-flight verifie

    ## V1 — RateLimitException retry-after
    Methode: `<getRetryAfter() | getHeader('Retry-After') | $retryAfter property>`
    Bron: vendor/mollie/mollie-api-php/src/Exceptions/RateLimitException.php:<regel>

    ## V2 — Mollie::class singleton/bind
    Binding: `<singleton | bind>`
    Bron: packages/mollie-api/src/MollieServiceProvider.php:<regel>
    Implicatie Task 2: `<forgetInstance nodig | niet nodig>`

    ## V3 — webhook-payload-prefix
    Mollie stuurt voor: payments=`tr_*`, subscriptions=`<sub_* | tr_* van renewal-payment>`, refunds=`<re_* | tr_* van parent>`
    Bron: .docs/partners/mollie/webhooks-overview.md
    Implicatie Task 2: `<huidige aanname OK | id-prefix-detectie nodig | scope-clip naar payment-events>`
    ```

    Bij blokkerende bevinding (bv. Mollie::class singleton + geen forgetInstance-pad in v0.2): STOP, escaleer naar user vóór Task 1.
  </action>
  <verify>
    <automated>test -f .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-02-PREFLIGHT.md && grep -cE "## V[123]" .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-02-PREFLIGHT.md</automated>
  </verify>
  <acceptance_criteria>
    - `test -f .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-02-PREFLIGHT.md`
    - `grep -c "## V1" .planning/phases/.../05a-02-PREFLIGHT.md` == 1
    - `grep -c "## V2" .planning/phases/.../05a-02-PREFLIGHT.md` == 1
    - `grep -c "## V3" .planning/phases/.../05a-02-PREFLIGHT.md` == 1
  </acceptance_criteria>
  <done>Drie verifie-uitkomsten gedocumenteerd; Task 1 + 2 weten welke vendor-API te gebruiken zonder mid-implementation discovery.</done>
</task>

<task type="auto">
  <name>Task 1: Composer-deps + Spatie webhook-server config + bootstrap-routing voor webhooks.php</name>
  <files>
    composer.json,
    composer.lock,
    config/webhook-server.php,
    config/webhook-client.php,
    routes/webhooks.php,
    bootstrap/app.php
  </files>
  <behavior>
    Setup-stap zonder TDD: dependency-installeren + Spatie-config publishen + nieuwe routes-file aanmaken + bootstrap registreren.

    **Stap 1 — Verifieer composer-deps:**
    - `spatie/laravel-webhook-client` MOET in composer.json staan (al aanwezig sinds Phase 0 — verifieer via `composer show spatie/laravel-webhook-client`).
    - `spatie/laravel-webhook-server` is NIEUW — `composer require spatie/laravel-webhook-server` indien afwezig.

    **Stap 2 — Publish Spatie config-files** zodat we ze kunnen reviewen en eventueel tweaken:
    - `php artisan vendor:publish --tag=webhook-server-config --no-interaction` (creëert `config/webhook-server.php`)
    - `php artisan vendor:publish --tag=webhook-client-config --no-interaction` (creëert `config/webhook-client.php` indien nog niet aanwezig)
    - Geen wijzigingen aan default-config nodig — laat `signing_secret` op env-default.

    **Stap 3 — Maak `routes/webhooks.php`:**

    ```php
    <?php

    use App\Http\Controllers\Webhooks\MollieWebhookController;
    use Illuminate\Support\Facades\Route;

    /*
    |--------------------------------------------------------------------------
    | Webhook Routes — /webhooks/{provider}/{...}
    |--------------------------------------------------------------------------
    | Publiek; signature is de auth. NIET geprefixed met /v1/. Geregistreerd
    | in bootstrap/app.php's withRouting()->then()-callback.
    */

    Route::post('/webhooks/mollie/{connection_id}', MollieWebhookController::class)
        ->where('connection_id', '[0-9]+')
        ->name('webhooks.mollie');
    ```

    **Stap 4 — Update `bootstrap/app.php` om de routes te laden:**

    Voeg `then`-callback toe aan `withRouting()`. Behoud bestaande `web/api/apiPrefix/commands/health`-keys; voeg `then` toe:

    ```php
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            \Illuminate\Support\Facades\Route::middleware('api')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ```

    De `'api'`-middleware-group bevat al `throttle:api` (per `bootstrap/app.php:14`); webhook-routes erven dat. Geen `auth:sanctum` — webhook is publiek.

    **Stap 5 — Smoke:**
    ```bash
    php artisan route:list --path=webhooks
    ```
    Moet `POST /webhooks/mollie/{connection_id}` tonen — controller bestaat nog niet maar route registratie moet werken (404-binding-error is OK in deze fase, route:list checkt alleen route-defs).
  </behavior>
  <read_first>
    - composer.json (huidige Spatie-deps)
    - composer.lock (verifieer welke versies geïnstalleerd zijn)
    - bootstrap/app.php (huidige withRouting structuur)
    - routes/api.php (geen wijziging — alleen referentie)
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-CONTEXT.md sectie `<decisions>` D-07, D-08
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-PATTERNS.md regels 776-825 (routes/webhooks.php + bootstrap-update)
    - database/migrations/2026_05_13_223628_create_webhook_calls_table.php (Spatie webhook-client tabel — al aanwezig)
  </read_first>
  <action>
    **Stap 1 — Verifieer + install deps:**

    ```bash
    composer show spatie/laravel-webhook-client 2>/dev/null | head -5
    composer show spatie/laravel-webhook-server 2>/dev/null | head -5
    ```

    Als webhook-server ontbreekt:
    ```bash
    composer require spatie/laravel-webhook-server
    ```

    **Stap 2 — Publish configs:**

    ```bash
    php artisan vendor:publish --tag=webhook-server-config --no-interaction
    # Webhook-client config (idempotent als al gepublished):
    php artisan vendor:publish --tag=webhook-client-config --no-interaction || true
    ```

    Verifieer dat `config/webhook-server.php` is aangemaakt. Geen tweaks aan content.

    **Stap 3 — Maak `routes/webhooks.php`** met de inhoud uit behavior-sectie.

    **Stap 4 — Update `bootstrap/app.php`** — voeg de `then`-callback toe aan `withRouting()`. NIET de bestaande keys aanpassen — alleen `then` toevoegen onderaan het `withRouting()`-call-args-blok.

    **Stap 5 — Smoke:**
    ```bash
    vendor/bin/pint --dirty --format agent
    php artisan route:list --path=webhooks
    ```

    Verwacht: route is geregistreerd (controller-resolution-fout is OK omdat MollieWebhookController nog niet bestaat — route:list valideert alleen def, niet target).
  </action>
  <verify>
    <automated>composer show spatie/laravel-webhook-server 2>&1 | grep -c "^name" && test -f routes/webhooks.php && test -f config/webhook-server.php && [ "$(php artisan route:list --path=webhooks 2>&1 | grep -c '/webhooks/mollie/')" -ge 1 ]</automated>
  </verify>
  <acceptance_criteria>
    - `composer show spatie/laravel-webhook-server` exit 0 (dep is geïnstalleerd)
    - `composer show spatie/laravel-webhook-client` exit 0 (dep was al aanwezig)
    - `test -f config/webhook-server.php`
    - `test -f routes/webhooks.php`
    - `grep -c "/webhooks/mollie/{connection_id}" routes/webhooks.php` == 1
    - `grep -c "MollieWebhookController" routes/webhooks.php` == 1
    - `grep -c "routes/webhooks.php" bootstrap/app.php` == 1
    - `grep -c "then:" bootstrap/app.php` >= 1
    - `php artisan route:list --path=webhooks` exit 0
  </acceptance_criteria>
  <done>Composer-deps OK, configs gepublished, route-file bestaat, bootstrap laadt webhooks-routes binnen 'api'-middleware-group.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: ForwardMollieWebhookToConsumer-job + MollieWebhookController</name>
  <files>
    app/Jobs/ForwardMollieWebhookToConsumer.php,
    app/Http/Controllers/Webhooks/MollieWebhookController.php
  </files>
  <behavior>
    **ForwardMollieWebhookToConsumer-job:**
    - `implements ShouldQueue`, `use Dispatchable, Queueable, InteractsWithQueue, SerializesModels`
    - Constructor: `public Connection $connection, public array $payload`
    - `handle()`: lees `$consumer = $this->connection->account->consumer`. Als `webhook_callback_url` null → silently return (Consumer heeft geen callback geconfigureerd; loggen is optioneel).
    - Anders: `\Spatie\WebhookServer\WebhookCall::create()->url($consumer->webhook_callback_url)->payload($this->payload)->useSecret((string) $consumer->webhook_callback_secret)->dispatch()`
    - **Belangrijk:** `webhook_callback_secret` is encrypted-cast op Consumer-model (Plan 05a-01) — accessor levert plain string.

    **MollieWebhookController** — 6-stappen-flow per D-08:

    1. Signature-verify via `MollieWebhookSignature::verify($request, config('services.mollie.webhook_secret'))`. Bij `\Mollie\Api\Exceptions\InvalidSignatureException` of returnt `false` → 400 + audit-failed-row (`name='mollie', exception=...`) + return.
    2. Connection-lookup: `Connection::where('id', $connection_id)->where('provider', 'mollie')->whereNull('revoked_at')->first()`. Niet gevonden → 410 Gone (Mollie retried niet bij permanent-4xx). Audit-failed-row met `exception='unknown_or_revoked_connection'`.
    3. Payload-validatie: `$payload = $request->json()->all()`. Mist `id`-key → 400 + audit-failed.
    4. Anti-spoofing: bind `MollieConnectionContext::set($connection)` + roep `Mollie::client()->payments->get($payload['id'])`. Bij Throwable (Mollie-404 etc.) → 400 + audit-failed `exception='spoof_check_failed: ...'` + return. **NB:** voor non-payments-webhooks (subscriptions, refunds) zou een andere endpoint nodig zijn — voor v0.2 nemen we aan dat alle webhook-`id`s payment-IDs zijn (Mollie's webhook-payload bevat altijd het Payment dat het event triggerde, ook bij subscription-renewals). Documenteer in code-comment dat dit een v0.2-aanname is en in v0.3+ moet uitbreiden naar resource-type-detectie via `id`-prefix (`tr_`=payment, `sub_`=subscription, `re_`=refund).
    5. Inkomend audit naar Spatie's `webhook_calls`: `\Spatie\WebhookClient\Models\WebhookCall::create(['name'=>'mollie','url'=>$request->fullUrl(),'headers'=>$request->headers->all(),'payload'=>$payload])`.
    6. Fan-out: `ForwardMollieWebhookToConsumer::dispatch($connection, $payload)`.
    7. Return 202 Accepted + JSON `{status:'accepted'}`.

    **NB:** GEEN `MollieConnectionContext::forget()` na fan-out — context is scoped binding (per-request); Laravel cleant 'm op next request.

    **Audit-failed helper** als private method op de controller:
    ```php
    private function auditFailedWebhook(Request $request, string $exception): void
    {
        \Spatie\WebhookClient\Models\WebhookCall::create([
            'name' => 'mollie',
            'url' => $request->fullUrl(),
            'headers' => $request->headers->all(),
            'payload' => $request->json()->all() ?: ['_raw' => substr($request->getContent(), 0, 1000)],
            'exception' => $exception,
        ]);
    }
    ```

    Beperk `_raw` op 1000 chars om DB-bloat bij abusive bodies te voorkomen.
  </behavior>
  <read_first>
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-CONTEXT.md sectie `<decisions>` D-07, D-08
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-PATTERNS.md regels 296-401 (MollieWebhookController) + 920-960 (ForwardMollieWebhookToConsumer)
    - .docs/partners/mollie/webhooks-overview.md sectie 'webhook payload format' + 'event types' — kritisch voor anti-spoofing-id-prefix beslissing (W2: bevestig of subscription/refund-events `tr_*`, `sub_*`, of `re_*` prefix sturen). Bij non-`tr_` prefixes voor non-payment events: óf scope-clip naar payment-events-only in v0.2 met expliciete reject-rule, óf pas anti-spoofing-fetch aan met id-prefix-detectie. Pre-flight Task 0 V3-uitkomst is leidend.
    - app/Http/Controllers/Api/V1/OAuth/CallbackController.php (template voor publieke endpoint met eigen auth-check)
    - packages/mollie-api/src/Webhooks/MollieWebhookSignature.php (verify + sign API)
    - app/Models/Connection.php (account()-relation, fingerprint())
    - app/Models/Consumer.php (na Plan 05a-01: webhook_callback_url + webhook_callback_secret)
    - app/Mollie/MollieConnectionContext.php
    - vendor/spatie/laravel-webhook-server/src/WebhookCall.php (verifieer publieke API: ::create(), ->url(), ->payload(), ->useSecret(), ->dispatch())
    - vendor/spatie/laravel-webhook-client/src/Models/WebhookCall.php (verifieer fillable + table-name)
  </read_first>
  <action>
    **Stap 1 — Maak directory:**

    ```bash
    mkdir -p app/Http/Controllers/Webhooks app/Jobs
    ```

    **Stap 2 — Maak `app/Jobs/ForwardMollieWebhookToConsumer.php`:**

    ```php
    <?php

    namespace App\Jobs;

    use App\Models\Connection;
    use Illuminate\Bus\Queueable;
    use Illuminate\Contracts\Queue\ShouldQueue;
    use Illuminate\Foundation\Bus\Dispatchable;
    use Illuminate\Queue\InteractsWithQueue;
    use Illuminate\Queue\SerializesModels;
    use Spatie\WebhookServer\WebhookCall;

    /**
     * Fan-out van een geverifieerde Mollie-webhook naar de Consumer-callback-URL.
     *
     * Consumer moet `webhook_callback_url` + `webhook_callback_secret` (encrypted)
     * geconfigureerd hebben (Plan 05a-01 schema). Geen URL → silent skip (geen retry).
     * Spatie's webhook-server doet retry/backoff per zijn config defaults.
     */
    class ForwardMollieWebhookToConsumer implements ShouldQueue
    {
        use Dispatchable;
        use InteractsWithQueue;
        use Queueable;
        use SerializesModels;

        /**
         * @param  array<string, mixed>  $payload
         */
        public function __construct(
            public Connection $connection,
            public array $payload,
        ) {
        }

        public function handle(): void
        {
            $consumer = $this->connection->account?->consumer;

            if ($consumer === null || ! $consumer->webhook_callback_url) {
                return;
            }

            WebhookCall::create()
                ->url($consumer->webhook_callback_url)
                ->payload($this->payload)
                ->useSecret((string) $consumer->webhook_callback_secret)
                ->dispatch();
        }
    }
    ```

    **Stap 3 — Maak `app/Http/Controllers/Webhooks/MollieWebhookController.php`:**

    ```php
    <?php

    namespace App\Http\Controllers\Webhooks;

    use App\Http\Controllers\Controller;
    use App\Jobs\ForwardMollieWebhookToConsumer;
    use App\Models\Connection;
    use App\Mollie\MollieConnectionContext;
    use Emeq\MollieApi\Facades\Mollie;
    use Emeq\MollieApi\Webhooks\MollieWebhookSignature;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use Mollie\Api\Exceptions\InvalidSignatureException;
    use Spatie\WebhookClient\Models\WebhookCall;
    use Throwable;

    /**
     * Mollie Connect webhook ingress.
     *
     * Flow per 05a-CONTEXT.md D-08:
     *  1. Signature-verify (X-Mollie-Signature, HMAC-SHA256, platform-secret)
     *  2. Connection-lookup (provider=mollie, niet revoked)
     *  3. Payload-id-check
     *  4. Anti-spoofing: fetch resource via deze Connection's access_token
     *  5. Inkomend audit naar Spatie's webhook_calls
     *  6. Fan-out via ForwardMollieWebhookToConsumer
     *  7. 202 Accepted
     *
     * v0.2-aanname: alle webhook-id's zijn Payment-id's (tr_*). Subscriptions/Refunds
     * triggeren ook een Payment-event waardoor de id geldig is. v0.3+ moet
     * resource-type-detectie via id-prefix toevoegen voor edge-cases.
     */
    class MollieWebhookController extends Controller
    {
        public function __invoke(Request $request, int $connection_id): JsonResponse
        {
            // 1. Signature-verify
            try {
                $valid = MollieWebhookSignature::verify(
                    $request,
                    (string) config('services.mollie.webhook_secret'),
                );
            } catch (InvalidSignatureException $e) {
                $this->auditFailedWebhook($request, "invalid_signature: {$e->getMessage()}");
                return response()->json(['error' => 'invalid_signature'], 400);
            }
            if (! $valid) {
                $this->auditFailedWebhook($request, 'missing_signature_header');
                return response()->json(['error' => 'missing_signature'], 400);
            }

            // 2. Connection-lookup
            $connection = Connection::query()
                ->where('id', $connection_id)
                ->where('provider', 'mollie')
                ->whereNull('revoked_at')
                ->first();

            if ($connection === null) {
                $this->auditFailedWebhook($request, 'unknown_or_revoked_connection');
                return response()->json(['error' => 'connection_gone'], 410);
            }

            // 3. Payload-id-check
            $payload = $request->json()->all();
            if (! is_array($payload) || ! isset($payload['id']) || ! is_string($payload['id'])) {
                $this->auditFailedWebhook($request, 'missing_payload_id');
                return response()->json(['error' => 'missing_id'], 400);
            }

            // 4. Anti-spoofing: bind context + fetch resource
            app(MollieConnectionContext::class)->set($connection);
            try {
                Mollie::client()->payments->get($payload['id']);
            } catch (Throwable $e) {
                $this->auditFailedWebhook($request, 'spoof_check_failed: '.$e->getMessage());
                return response()->json(['error' => 'resource_ownership_failed'], 400);
            }

            // 5. Inkomend audit
            WebhookCall::create([
                'name' => 'mollie',
                'url' => $request->fullUrl(),
                'headers' => $request->headers->all(),
                'payload' => $payload,
            ]);

            // 6. Fan-out
            ForwardMollieWebhookToConsumer::dispatch($connection, $payload);

            // 7. 202 Accepted
            return response()->json(['status' => 'accepted'], 202);
        }

        private function auditFailedWebhook(Request $request, string $exception): void
        {
            WebhookCall::create([
                'name' => 'mollie',
                'url' => $request->fullUrl(),
                'headers' => $request->headers->all(),
                'payload' => $request->json()->all() ?: ['_raw' => substr($request->getContent(), 0, 1000)],
                'exception' => $exception,
            ]);
        }
    }
    ```

    **Stap 4 — Lint + smoke:**

    ```bash
    vendor/bin/pint --dirty --format agent
    php artisan route:list --path=webhooks
    php -l app/Http/Controllers/Webhooks/MollieWebhookController.php app/Jobs/ForwardMollieWebhookToConsumer.php
    ```

    Verwacht: route:list toont nu `POST /webhooks/mollie/{connection_id}` met `MollieWebhookController` als target. Geen runtime test in deze task — Task 3 levert de TDD-cycle.
  </action>
  <verify>
    <automated>php artisan route:list --path=webhooks 2>&1 | grep -c "MollieWebhookController" && php -l app/Http/Controllers/Webhooks/MollieWebhookController.php app/Jobs/ForwardMollieWebhookToConsumer.php</automated>
  </verify>
  <acceptance_criteria>
    - `test -f app/Http/Controllers/Webhooks/MollieWebhookController.php`
    - `test -f app/Jobs/ForwardMollieWebhookToConsumer.php`
    - `grep -c "class MollieWebhookController" app/Http/Controllers/Webhooks/MollieWebhookController.php` == 1
    - `grep -c "class ForwardMollieWebhookToConsumer" app/Jobs/ForwardMollieWebhookToConsumer.php` == 1
    - `grep -c "MollieWebhookSignature::verify" app/Http/Controllers/Webhooks/MollieWebhookController.php` == 1
    - `grep -c "ForwardMollieWebhookToConsumer::dispatch" app/Http/Controllers/Webhooks/MollieWebhookController.php` == 1
    - `grep -c "Mollie::client()->payments->get" app/Http/Controllers/Webhooks/MollieWebhookController.php` == 1
    - `grep -cE "(invalid_signature|missing_signature|connection_gone|missing_id|resource_ownership_failed)" app/Http/Controllers/Webhooks/MollieWebhookController.php` >= 5
    - `grep -c "WebhookCall::create" app/Http/Controllers/Webhooks/MollieWebhookController.php` >= 2 (1 incoming-audit + 1 audit-failed)
    - `grep -c "Spatie\\\\WebhookServer\\\\WebhookCall" app/Jobs/ForwardMollieWebhookToConsumer.php` == 1
    - `grep -c "useSecret" app/Jobs/ForwardMollieWebhookToConsumer.php` == 1
    - `php artisan route:list --path=webhooks` toont route met MollieWebhookController als target
    - `php -l` exit 0 voor beide files
  </acceptance_criteria>
  <done>Controller + job staan; webhook-route resolved naar de controller; alle 6 D-08-stappen zijn in code aanwezig.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: 3 webhook feature-tests — signature + fan-out + anti-spoofing</name>
  <files>
    tests/Feature/Webhooks/MollieWebhookSignatureTest.php,
    tests/Feature/Webhooks/MollieWebhookFanOutTest.php,
    tests/Feature/Webhooks/MollieWebhookAntiSpoofingTest.php
  </files>
  <behavior>
    **`MollieWebhookSignatureTest`** (~5 cases) — bewijst MOLL-04 SC-3 signature-paden:
    1. `test_valid_signature_returns_202_and_writes_inbound_audit_row` — `MollieWebhookSignature::sign()` gebruikt om geldige signature te genereren; mock Mollie's payments->get om succesvol te returnen (zie SDK-fake hieronder); assert 202 + 1 row in `webhook_calls` met `name='mollie'`, `exception=null`
    2. `test_tampered_signature_returns_400_and_writes_failed_audit_and_no_dispatch` — signature met verkeerde secret; assert 400 + 1 row in `webhook_calls` met `exception` like `invalid_signature%` + `Bus::assertNotDispatched(ForwardMollieWebhookToConsumer::class)`
    3. `test_missing_signature_header_returns_400_with_missing_signature` — geen X-Mollie-Signature header; assert 400 + audit `exception='missing_signature_header'`
    4. `test_unknown_connection_id_returns_410_gone` — geldige signature maar connection_id bestaat niet (of revoked_at gezet); assert 410 + audit `exception='unknown_or_revoked_connection'`
    5. `test_payload_without_id_returns_400_missing_id` — geldige sig + bestaande Connection maar `{}`-payload; 400 + audit `exception='missing_payload_id'`

    **`MollieWebhookFanOutTest`** (~3 cases) — bewijst HUB-03 fan-out:
    1. `test_valid_webhook_dispatches_forward_job_with_connection_and_payload` — `Bus::fake()`; doe geldige webhook-call; `Bus::assertDispatched(ForwardMollieWebhookToConsumer::class, fn ($job) => $job->connection->id === $connection->id && $job->payload['id'] === 'tr_test123')`
    2. `test_forward_job_handle_calls_spatie_webhook_server_with_consumer_callback` — directly instantiate de job met een Connection waarvan de Consumer een `withWebhookCallback`-state heeft; `Queue::fake()`; roep `$job->handle()` aan; assert dat `Spatie\WebhookServer\WebhookCall::create()->url(...)->payload(...)->useSecret(...)->dispatch()` werd aangeroepen door de gequeued job te tellen via `Queue::assertPushed(\Spatie\WebhookServer\Jobs\CallWebhookJob::class)` (Spatie dispatcht intern deze job)
    3. `test_forward_job_silently_returns_when_consumer_has_no_callback_url` — Consumer zonder webhook_callback_url; `Queue::fake()`; `$job->handle()`; assert `Queue::assertNothingPushed()`

    **`MollieWebhookAntiSpoofingTest`** (~2 cases) — bewijst D-08 stap 3:
    1. `test_webhook_for_id_that_returns_404_from_mollie_returns_400_resource_ownership_failed` — mock Mollie's `payments->get` om `Emeq\MollieApi\Exceptions\NotFoundException` te gooien; geldige signature; assert 400 + audit `exception` like `spoof_check_failed%` + geen fan-out
    2. `test_webhook_for_id_that_returns_auth_error_from_mollie_returns_400` — mock om `AuthenticationException` te gooien; idem 400 + audit + no fan-out

    **SDK-mock-strategie:** Mollie's `MollieApiClient` is bound via `MollieServiceProvider` als non-singleton — `app(\Mollie\Api\MollieApiClient::class)` returnt een fresh instance via `Mollie::client()`. Voor tests: bind een mock op de container vóór de webhook-call:

    ```php
    use Mollie\Api\MollieApiClient;
    use Mollie\Api\Endpoints\PaymentEndpoint;
    use Mollie\Api\Resources\Payment;

    protected function fakeMolliePaymentGet(string $id, ?\Throwable $throwable = null): void
    {
        $paymentEndpoint = $this->createMock(PaymentEndpoint::class);
        if ($throwable !== null) {
            $paymentEndpoint->method('get')->with($id)->willThrowException($throwable);
        } else {
            $payment = new Payment($this->createMock(MollieApiClient::class));
            $payment->id = $id;
            $payment->status = 'paid';
            $paymentEndpoint->method('get')->with($id)->willReturn($payment);
        }

        $client = $this->createMock(MollieApiClient::class);
        $client->payments = $paymentEndpoint;

        // Bind ONZE Emeq\MollieApi\Mollie zodat ::client() onze mock returnt
        $mollie = $this->createMock(\Emeq\MollieApi\Mollie::class);
        $mollie->method('client')->willReturn($client);
        $this->app->instance(\Emeq\MollieApi\Mollie::class, $mollie);
    }
    ```

    Als die exact mock-API in vendor niet werkt (Payment-properties readonly etc.), gebruik dan een eenvoudige stub-class:

    ```php
    $stub = new class extends \Mollie\Api\MollieApiClient {
        public function __construct() { /* skip parent */ }
        public PaymentEndpoint $payments;
    };
    $stub->payments = new class($throwable) {
        public function __construct(private ?\Throwable $t) {}
        public function get(string $id): mixed {
            if ($this->t) throw $this->t;
            return (object) ['id' => $id, 'status' => 'paid'];
        }
    };
    ```

    Executor mag de exacte mock-shape kiezen — belangrijk is dat `Mollie::client()->payments->get($id)` controllable is per test.

    **Audit-row helpers:**
    - `webhook_calls`-tabel = Spatie webhook-client schema (`name, url, headers, payload, exception, attachments, ...`).
    - Assertions: `$this->assertDatabaseHas('webhook_calls', ['name' => 'mollie', 'exception' => fn ($v) => str_starts_with($v, 'invalid_signature')])` — of haal row direct op met `WebhookCall::query()->latest()->first()` en assert properties.

    **Bus::fake / Queue::fake:** gebruik `Bus::fake()` voor controller-level dispatch-assertions (`Bus::assertDispatched(ForwardMollieWebhookToConsumer::class)`). Gebruik `Queue::fake()` voor job-handle-level assertions over Spatie's eigen interne dispatch.
  </behavior>
  <read_first>
    - app/Http/Controllers/Webhooks/MollieWebhookController.php (Task 2 output)
    - app/Jobs/ForwardMollieWebhookToConsumer.php (Task 2 output)
    - packages/mollie-api/src/Webhooks/MollieWebhookSignature.php (verify + sign API)
    - packages/mollie-api/src/Exceptions/NotFoundException.php
    - packages/mollie-api/src/Exceptions/AuthenticationException.php
    - packages/mollie-api/src/Mollie.php (Mollie::class binding)
    - packages/mollie-api/src/MollieServiceProvider.php (singleton vs bind)
    - vendor/spatie/laravel-webhook-client/src/Models/WebhookCall.php (fillable + table)
    - vendor/spatie/laravel-webhook-server/src/WebhookCall.php (publieke API)
    - vendor/mollie/mollie-api-php/src/MollieApiClient.php (regels 156-210 voor de constructor)
    - tests/Feature/Api/V1/Mollie/MolliePassThroughResolutionTest.php (Plan 05a-01 — pattern voor Consumer/Account/Connection/PAT-setup)
    - tests/Feature/Api/OAuth/CallbackTest.php (Plan 04 — publieke endpoint, valid/tampered/expired paths)
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-PATTERNS.md regels 1078-1119 (test-skeletons)
  </read_first>
  <action>
    **Stap 1 — Maak directory:**

    ```bash
    mkdir -p tests/Feature/Webhooks
    ```

    **Stap 2 — Setup test-secret:** in elke test set `config(['services.mollie.webhook_secret' => 'whsec_test_xyz'])` in setUp() of per case.

    **Stap 3 — Genereer test-files:**
    ```bash
    php artisan make:test --phpunit Webhooks/MollieWebhookSignatureTest --no-interaction
    php artisan make:test --phpunit Webhooks/MollieWebhookFanOutTest --no-interaction
    php artisan make:test --phpunit Webhooks/MollieWebhookAntiSpoofingTest --no-interaction
    ```

    **Stap 4 — Schrijf 5 SignatureTest-cases.** Skelet:

    ```php
    <?php

    namespace Tests\Feature\Webhooks;

    use App\Jobs\ForwardMollieWebhookToConsumer;
    use App\Models\Account;
    use App\Models\Connection;
    use App\Models\Consumer;
    use Emeq\MollieApi\Webhooks\MollieWebhookSignature;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Illuminate\Support\Facades\Bus;
    use Spatie\WebhookClient\Models\WebhookCall;
    use Tests\TestCase;

    class MollieWebhookSignatureTest extends TestCase
    {
        use RefreshDatabase;

        private string $secret = 'whsec_test_xyz';

        protected function setUp(): void
        {
            parent::setUp();
            config(['services.mollie.webhook_secret' => $this->secret]);
        }

        public function test_valid_signature_returns_202_and_writes_inbound_audit_row(): void
        {
            Bus::fake();

            $connection = $this->makeMollieConnection();
            $payload = json_encode(['id' => 'tr_test123']);
            $signature = MollieWebhookSignature::sign($payload, $this->secret);

            // Mock Mollie::client()->payments->get om succesvol te returnen
            $this->fakeMolliePaymentGetSuccess('tr_test123');

            $response = $this->call(
                'POST',
                "/webhooks/mollie/{$connection->id}",
                [], [], [],
                ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
                $payload,
            );

            $response->assertStatus(202);
            $this->assertDatabaseHas('webhook_calls', ['name' => 'mollie']);
            $this->assertSame(1, WebhookCall::query()->whereNull('exception')->count());
        }

        public function test_tampered_signature_returns_400_and_no_dispatch(): void
        {
            Bus::fake();
            $connection = $this->makeMollieConnection();
            $payload = json_encode(['id' => 'tr_test123']);
            $tampered = MollieWebhookSignature::sign($payload, 'wrong_secret');

            $response = $this->call(
                'POST',
                "/webhooks/mollie/{$connection->id}",
                [], [], [],
                ['HTTP_X_MOLLIE_SIGNATURE' => $tampered, 'CONTENT_TYPE' => 'application/json'],
                $payload,
            );

            $response->assertStatus(400);
            Bus::assertNotDispatched(ForwardMollieWebhookToConsumer::class);
            $this->assertDatabaseHas('webhook_calls', ['name' => 'mollie']);
            $row = WebhookCall::query()->latest('id')->first();
            $this->assertNotNull($row->exception);
            $this->assertStringStartsWith('invalid_signature', $row->exception);
        }

        // ... 3 meer cases ...

        private function makeMollieConnection(): Connection
        {
            $consumer = Consumer::factory()->create();
            $account = Account::factory()->for($consumer)->create();
            return Connection::factory()->forMollie()->active()->for($account)->create();
        }

        private function fakeMolliePaymentGetSuccess(string $id): void
        {
            // Implementatie per de mock-strategie uit behavior-sectie.
            // Executor kiest stub vs createMock zoals werkbaar.
        }
    }
    ```

    Vul de overige 3 cases (`test_missing_signature_header_returns_400`, `test_unknown_connection_id_returns_410`, `test_payload_without_id_returns_400`) in volgens behavior-sectie.

    **Stap 5 — Schrijf 3 FanOutTest-cases** — focus op `Bus::assertDispatched` en `Queue::assertPushed` voor Spatie's interne `CallWebhookJob`. Gebruik `ConsumerFactory::withWebhookCallback()` (Plan 05a-01 state) om een Consumer met callback-config te maken.

    **Stap 6 — Schrijf 2 AntiSpoofingTest-cases** — gooi `NotFoundException` resp. `AuthenticationException` uit de fake `payments->get`.

    **Stap 7 — Run tests:**

    ```bash
    vendor/bin/pint --dirty --format agent
    php artisan test --compact --filter='MollieWebhook'
    php artisan test --compact   # volledige suite
    ```
  </action>
  <verify>
    <automated>php artisan test --compact --filter='MollieWebhook'</automated>
  </verify>
  <acceptance_criteria>
    - `grep -cE "public function test_" tests/Feature/Webhooks/MollieWebhookSignatureTest.php` >= 5
    - `grep -cE "public function test_" tests/Feature/Webhooks/MollieWebhookFanOutTest.php` >= 3
    - `grep -cE "public function test_" tests/Feature/Webhooks/MollieWebhookAntiSpoofingTest.php` >= 2
    - **Totaal nieuw: >= 10 tests**
    - `grep -c "MollieWebhookSignature::sign" tests/Feature/Webhooks/MollieWebhookSignatureTest.php` >= 1
    - `grep -c "Bus::fake" tests/Feature/Webhooks/MollieWebhookFanOutTest.php` >= 1
    - `grep -c "Bus::assertDispatched" tests/Feature/Webhooks/MollieWebhookFanOutTest.php` >= 1
    - `grep -cE "(NotFoundException|AuthenticationException)" tests/Feature/Webhooks/MollieWebhookAntiSpoofingTest.php` >= 2
    - `grep -c "withWebhookCallback" tests/Feature/Webhooks/MollieWebhookFanOutTest.php` >= 1
    - `php artisan test --compact --filter='MollieWebhook'` exit 0
    - `php artisan test --compact` exit 0 (geen regressies — Plan 05a-01 + Plan 05a-02 task 1+2 ook groen)
  </acceptance_criteria>
  <done>10+ tests groen die alle 6 D-08-stappen + fan-out + anti-spoofing + signature-paden bewijzen.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Mollie-platform → Hub-webhook (publieke ingress) | Signature is de auth; missende/tampered → 400 + audit, geen state-mutatie |
| Hub-webhook → Mollie-API (anti-spoofing fetch) | Webhook-id moet bij deze Connection's access_token horen |
| Hub-job → Consumer-callback (HMAC-signed fan-out) | Consumer mag verifiëren met Hub-uitgegeven secret (NIET Mollie's secret) |
| Spatie webhook_calls audit-tabel | Bevat headers met ev. PII; access via tinker/admin |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-05a-07 | Spoofing | Aanvaller stuurt verzonnen webhook met willekeurige `id` naar `/webhooks/mollie/{X}` | high | mitigate | Signature-verify (HMAC met platform-secret) + anti-spoofing-fetch (resource moet via deze Connection's access_token ophaalbaar zijn). Bewezen door MollieWebhookSignatureTest + MollieWebhookAntiSpoofingTest. |
| T-05a-08 | Tampering | Replay van geldige webhook na Connection-revoke | medium | mitigate | Connection-lookup filtert op `whereNull('revoked_at')`; revoked → 410 Gone. Mollie retried niet bij 410. |
| T-05a-09 | Information disclosure | webhook_callback_secret leakt via process-listing of crash-dump | medium | mitigate | Encrypted-at-rest (Plan 05a-01 cast); plain alleen in-memory tijdens job-handle. Spatie webhook-server gebruikt 'm voor HMAC-sign en houdt 'm niet vast in retry-state. |
| T-05a-10 | Spoofing | Aanvaller poogt fan-out-job dispatchen door direct in queue te schrijven | low | accept | Hub-queue is internal (Redis intern, geen externe queue-API). Geen extra mitigatie nodig. Past Phase 7 jobs hetzelfde aan. |
| T-05a-11 | Information disclosure | Audit-row `headers` JSON bevat eventuele Bearer-token van Mollie's request (sturen ze die?) | low | accept | Mollie's webhook-request bevat geen Authorization-header (signature is de auth). Audit-headers bevatten alleen X-Mollie-Signature + standaard HTTP-headers. Geen secrets. |
| T-05a-12 | DoS | Aanvaller spam't webhooks met geldig-uitziende-maar-tampered signatures | medium | accept | Throttle:api (60/min per IP) zit op `'api'`-middleware-group. Anti-spoofing-fetch zou bij valid sig + invalid id ook Mollie's API hitten — 1 fetch per request, dus geen amplification. Past in v0.2 throttle-budget. Backlog: per-route throttle voor webhooks. |
| T-05a-13 | Repudiation | Webhook geaccepteerd maar fan-out-job faalt zonder retry-poging | low | mitigate | Spatie webhook-server doet retry/backoff per zijn defaults (3 tries, exponential); failed jobs landen in `failed_jobs`-tabel. |
</threat_model>

<verification>
- routes/webhooks.php geregistreerd in bootstrap/app.php
- spatie/laravel-webhook-server geïnstalleerd
- MollieWebhookController + ForwardMollieWebhookToConsumer bestaan met alle 6 D-08-stappen
- 10+ webhook-feature-tests groen
- Volledige `php artisan test --compact` exit 0
- Geen wijziging onder packages/mollie-api/**
- pint clean
</verification>

<success_criteria>
- MOLL-04 SC-3: tampered signature → 400 + niet doorgegeven aan Consumer (bewezen)
- HUB-03 webhook-deel: fan-out via Spatie webhook-server naar Consumer-callback (bewezen)
- D-07, D-08, D-09 ingelost
</success_criteria>

<output>
Na completion: `.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-02-SUMMARY.md` per template, met expliciete vermelding van:
- Composer-deps die nieuw geïnstalleerd zijn (vermoedelijk spatie/laravel-webhook-server)
- Anti-spoofing v0.2-aanname (alle webhook-id's = Payment-id; v0.3+ moet resource-type-detectie via id-prefix)
- Eventuele afwijking in mock-strategie voor `Mollie::client()->payments->get()` (zie behavior-sectie task 3)
- Bevestiging dat geen Mollie-SDK-wijziging nodig was (de SDK-helpers MollieWebhookSignature::sign/verify dekken alles)
- Trigger `docs-sync` skill als follow-up: nieuwe webhook-route + nieuwe Spatie-config + Consumer-schema-wijziging raken `.docs/`
</output>
</content>
</invoke>