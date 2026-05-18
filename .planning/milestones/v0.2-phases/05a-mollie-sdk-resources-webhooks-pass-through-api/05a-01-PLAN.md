---
phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php
  - app/Http/Middleware/ResolveMollieAccount.php
  - app/Support/Mollie/MollieUpstreamErrorMapper.php
  - app/Support/Mollie/MollieHeaderForwarder.php
  - app/Models/Consumer.php
  - database/factories/ConsumerFactory.php
  - database/migrations/2026_05_16_000001_add_webhook_callback_to_consumers_table.php
  - bootstrap/app.php
  - config/services.php
  - .env.example
  - tests/Unit/Support/Mollie/MollieUpstreamErrorMapperTest.php
  - tests/Feature/Api/V1/Mollie/MolliePassThroughResolutionTest.php
  - tests/Concerns/BindsMollieConnectionContext.php
autonomous: true
requirements: [HUB-03]
tags:
  - laravel
  - mollie
  - middleware
  - error-mapping
  - encryption
  - phpunit

must_haves:
  decisions: [D-01, D-03, D-05, D-09, D-13, D-14]
  truths:
    - "HUB-03 SC-1 (foundation): Een `AbstractMolliePassThroughController`-base bestaat met ability-guard (mollie:read voor GET, mollie:write voor write), 415-guard (POST/PATCH alleen application/json), try/catch met MollieUpstreamErrorMapper, audit-write naar pass_through_calls met provider='mollie' (NULL fingerprint bij lege body, path zonder query-string, query_keys-kolom)"
    - "HUB-03 SC-1 (foundation): Een `ResolveMollieAccount`-middleware bestaat met alias `resolve.mollie.account` die X-Account-Id resolved naar Account+Connection (provider='mollie', revoked_at=null), MollieConnectionContext::set() aanroept en account+connection op request->attributes zet"
    - "HUB-03 SC-1 (foundation): MollieUpstreamErrorMapper mapt SDK-exceptions volgens D-13 — 401/403→502 cloaked, 422→422, 404→404, 429→429+RetryAfter, 5xx→502, timeout→504"
    - "Cross-Consumer X-Account-Id → 404 (info-disclosure-mitigatie); ontbrekende header → 400; geen actieve mollie-Connection → 404"
    - "Consumer-model heeft webhook_callback_url + webhook_callback_secret kolommen; secret is encrypted-at-rest (cast 'encrypted'); never raw in toArray()"
    - "Migration is forward-only conform PROJECT.md invariant"
  artifacts:
    - path: "app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php"
      provides: "Abstract base met handle(Request, $endpoint, callable $sdkCall): Response — alle cross-cutting concerns"
      contains: "abstract class AbstractMolliePassThroughController"
    - path: "app/Http/Middleware/ResolveMollieAccount.php"
      provides: "Middleware met alias resolve.mollie.account; mirror van ResolveSnelstartAccount maar via MollieConnectionContext::set()"
      contains: "class ResolveMollieAccount"
    - path: "app/Support/Mollie/MollieUpstreamErrorMapper.php"
      provides: "Static ::mapException(Throwable): array{status,body,headers,short_code} per D-13 tabel"
      contains: "final class MollieUpstreamErrorMapper"
    - path: "app/Support/Mollie/MollieHeaderForwarder.php"
      provides: "Static ::forward(Request): array<string,string> — beperkte whitelist (geen If-Match)"
      contains: "final class MollieHeaderForwarder"
    - path: "database/migrations/2026_05_16_000001_add_webhook_callback_to_consumers_table.php"
      provides: "Schema-toevoeging webhook_callback_url + webhook_callback_secret op consumers"
      contains: "webhook_callback_url"
    - path: "bootstrap/app.php"
      provides: "Middleware-alias resolve.mollie.account → ResolveMollieAccount::class"
      contains: "resolve.mollie.account"
  key_links:
    - from: "ResolveMollieAccount middleware"
      to: "MollieConnectionContext (scoped singleton uit Phase 4)"
      via: "app(MollieConnectionContext::class)->set($connection)"
      pattern: "MollieConnectionContext"
    - from: "AbstractMolliePassThroughController"
      to: "MollieUpstreamErrorMapper"
      via: "MollieUpstreamErrorMapper::mapException($throwable)"
      pattern: "MollieUpstreamErrorMapper::mapException"
    - from: "AbstractMolliePassThroughController"
      to: "pass_through_calls audit-tabel"
      via: "PassThroughCall::create([... 'provider' => 'mollie' ...])"
      pattern: "'provider'\\s*=>\\s*'mollie'"
---

<objective>
Cross-cutting infrastructure voor Mollie pass-through: abstract controller-base, tenant-resolver-middleware, error-mapper, header-forwarder, encrypted webhook-callback-velden op Consumer. Geen routes, geen resource-controllers — alleen de "leeglopen" basis waar plans 05a-03..05a-05 op bouwen.

Purpose: HUB-03 foundation. CONTEXT D-01 (per-resource controllers + abstract base), D-03 (mirror-middleware via MollieConnectionContext::set ipv app->instance), D-05 (audit-fixes uit 5b REVIEW), D-09 (Consumer-niveau callback-URL), D-13 (Mollie-error-envelope), D-14 (PAT-abilities).

Output: 4 nieuwe app-klassen + 1 middleware-alias + 1 migration + 1 model-aanpassing + 1 factory-state + 2 unit/feature-tests + 1 test-trait. Geen routes, geen Mollie-SDK-calls.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-CONTEXT.md
@.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-PATTERNS.md
@.planning/phases/05b-snelstart-pass-through-api/05b-REVIEW.md
@.docs/decisions/mollie-passthrough-api.md
@.docs/decisions/pass-through-calls-table.md
@.docs/decisions/upstream-error-mapping.md
@.docs/partners/mollie/errors.md
@CLAUDE.md
@.ai/rules/global.md
@.ai/rules/engineering.md
@app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php
@app/Http/Middleware/ResolveSnelstartAccount.php
@app/Support/Snelstart/UpstreamErrorMapper.php
@app/Support/Snelstart/HeaderForwarder.php
@app/Models/Consumer.php
@app/Models/Connection.php
@app/Models/Account.php
@app/Models/PassThroughCall.php
@app/Mollie/MollieConnectionContext.php
@app/Mollie/HubMollieCredentialResolver.php
@app/Sanctum/TokenAbilities.php
@app/Providers/AppServiceProvider.php
@bootstrap/app.php
@database/factories/ConnectionFactory.php
@database/factories/ConsumerFactory.php
@database/migrations/2026_05_15_000001_create_pass_through_calls_table.php
@database/migrations/2026_05_15_000002_add_query_keys_to_pass_through_calls_table.php
@database/migrations/2026_05_15_000001_add_oauth_state_to_connections_table.php
@packages/mollie-api/src/Mollie.php
@packages/mollie-api/src/MollieServiceProvider.php
@packages/mollie-api/src/Exceptions/AuthenticationException.php
@packages/mollie-api/src/Exceptions/NotFoundException.php
@packages/mollie-api/src/Exceptions/RateLimitException.php
@packages/mollie-api/src/Exceptions/ServerException.php
@packages/mollie-api/src/Exceptions/ValidationException.php
@packages/mollie-api/src/Exceptions/MollieException.php
@packages/mollie-api/src/Exceptions/MollieExceptionMapper.php

<interfaces>
<!-- Bestaande contracten die dit plan consumeert. NIET wijzigen. -->

From app/Mollie/MollieConnectionContext.php (Phase 4):
```php
final class MollieConnectionContext {
    public function set(Connection $connection): void;
    public function current(): Connection;          // throws RuntimeException als ongeset
    public function has(): bool;
}
```
Binding: `$this->app->scoped(MollieConnectionContext::class)` in AppServiceProvider regel 25.

From app/Models/PassThroughCall.php (Plan 05b-01):
```php
class PassThroughCall extends Model {
    public $timestamps = false;
    // Fillable: consumer_id, account_id, connection_id, provider, method, path,
    //           query_keys, status, duration_ms, request_fingerprint,
    //           response_size_bytes, upstream_error, created_at
}
```

From app/Sanctum/TokenAbilities.php:
```php
final class TokenAbilities {
    public const MOLLIE_READ = 'mollie:read';
    public const MOLLIE_WRITE = 'mollie:write';
    public const ADMIN = '*';
}
```

From packages/mollie-api/src/Exceptions/* (Phase 2 SDK output):
```php
class MollieException extends \RuntimeException {}
class AuthenticationException extends MollieException {}
class NotFoundException extends MollieException {}
class RateLimitException extends MollieException {
    // Verifieer bij implement: heeft GETTER voor retry-after-seconds?
    // Vendor mollie/mollie-api-php gooit \Mollie\Api\Exceptions\ApiException;
    // SDK MollieExceptionMapper wrap't die — check exact welke property/method.
}
class ServerException extends MollieException {}
class ValidationException extends MollieException {
    public function getField(): ?string;  // verifieer
}
```

From .docs/partners/mollie/errors.md (HTTP-statuscodes Mollie returnt):
- 200/201: success
- 400: invalid-request (Mollie's "ValidationException")
- 401: unauthorized — Mollie's auth failed (cloaked door Hub naar 502)
- 403: forbidden — scope ontbreekt (cloaked naar 502)
- 404: not-found
- 405: method-not-allowed
- 422: unprocessable-entity (sometimes also "validation"-shape)
- 429: too-many-requests + Retry-After
- 5xx: server-error
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: MollieUpstreamErrorMapper + MollieHeaderForwarder + Unit-tests</name>
  <files>
    app/Support/Mollie/MollieUpstreamErrorMapper.php,
    app/Support/Mollie/MollieHeaderForwarder.php,
    tests/Unit/Support/Mollie/MollieUpstreamErrorMapperTest.php
  </files>
  <behavior>
    Mirror van Snelstart's `UpstreamErrorMapper` + `HeaderForwarder` met aangepaste mapping-tabel per D-13 en beperktere header-whitelist (Mollie kent geen If-Match).

    **MollieUpstreamErrorMapper::mapException(\Throwable $exception): array** — returnt `['status' => int, 'body' => array, 'headers' => array<string,string>, 'short_code' => ?string]`. Per-exception mapping (volgorde van match):

    | Exception | Hub status | error-key | short_code |
    |-----------|-----------|-----------|------------|
    | `Emeq\MollieApi\Exceptions\ValidationException` | 422 | `validation_failed` | `null` (user-input) |
    | `Emeq\MollieApi\Exceptions\AuthenticationException` | 502 | `mollie_auth_failed` | `mollie_auth` |
    | `Emeq\MollieApi\Exceptions\NotFoundException` | 404 | `not_found` | `null` |
    | `Emeq\MollieApi\Exceptions\RateLimitException` | 429 + `Retry-After` (indien beschikbaar) | `rate_limited` | `null` |
    | `Emeq\MollieApi\Exceptions\ServerException` | 502 | `mollie_unavailable` | `mollie_5xx` |
    | `Emeq\MollieApi\Exceptions\MollieException` (base/onbekend) | 502 | `mollie_error` | `mollie_unknown` |
    | onverwachte `\Throwable` (catch-all) | 502 | `mollie_error` | `mollie_unknown` |

    **Idem-flow voor vendor-exception:** als de catch op `\Throwable` een ruwe `\Mollie\Api\Exceptions\ApiException` ontvangt (niet door SDK gemapped), eerst probeer mappen via `\Emeq\MollieApi\Exceptions\MollieExceptionMapper::map($exception)` indien class-exists, anders fallback naar catch-all.

    **Timeout-pad:** Mollie-SDK gebruikt Guzzle, niet Saloon. Een netwerk-timeout komt door als `\GuzzleHttp\Exception\ConnectException` of `\GuzzleHttp\Exception\TransferException`. Map die naar 504 + `mollie_timeout` short_code. Verifieer bij implement welke vendor-exception precies bovenkomt.

    **MollieHeaderForwarder::forward(Request $request): array<string,string>** — whitelist:
    - `Accept` (Mollie returnt application/json default; Consumer kan `application/hal+json` vragen)
    - `Content-Type`
    - **GEEN** `Idempotency-Key` (per D-06 wordt dit via SDK-config gepropageerd, niet via header-forward)
    - **GEEN** `If-Match`/`If-None-Match` (Mollie heeft geen ETag-pad)

    **Unit-test `MollieUpstreamErrorMapperTest`** — minimaal 7 test-methods, 1 per exception-type uit de tabel + 1 voor catch-all `\RuntimeException`. Per test: instantieer exception, roep mapper aan, assert `status`, `body['error']`, `short_code`. Geen DB, geen HTTP — pure unit.
  </behavior>
  <read_first>
    - app/Support/Snelstart/UpstreamErrorMapper.php (template — exact mirror, andere mapping-tabel)
    - app/Support/Snelstart/HeaderForwarder.php (template — beperktere whitelist voor Mollie)
    - .docs/decisions/mollie-passthrough-api.md (architectuur-baseline; bevat error-envelope-rationale)
    - .docs/decisions/upstream-error-mapping.md (Snelstart-precedent — provider-agnostisch pattern)
    - .docs/partners/mollie/errors.md (HTTP-statuscodes + error-shapes)
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-CONTEXT.md sectie `<decisions>` D-13
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-PATTERNS.md sectie `MollieUpstreamErrorMapper.php` (regels 502-624)
    - packages/mollie-api/src/Exceptions/AuthenticationException.php
    - packages/mollie-api/src/Exceptions/NotFoundException.php
    - packages/mollie-api/src/Exceptions/RateLimitException.php (verifieer of er een `getRetryAfter()` of property bestaat — als niet: returnt mapper geen Retry-After-header)
    - packages/mollie-api/src/Exceptions/ServerException.php
    - packages/mollie-api/src/Exceptions/ValidationException.php (verifieer `getField()` exists)
    - packages/mollie-api/src/Exceptions/MollieException.php
    - packages/mollie-api/src/Exceptions/MollieExceptionMapper.php (entry-point voor vendor→SDK exception-remap)
  </read_first>
  <action>
    **Stap 1 — MollieHeaderForwarder.php (eenvoudigst, geen TDD nodig — pure transform):**

    ```bash
    mkdir -p app/Support/Mollie tests/Unit/Support/Mollie
    ```

    Schrijf `app/Support/Mollie/MollieHeaderForwarder.php`:

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Support\Mollie;

    use Illuminate\Http\Request;

    /**
     * Whitelist headers die we naar de Mollie-SDK forwarden. Mollie kent geen
     * ETag/If-Match-pad (in tegenstelling tot Snelstart), dus de whitelist is
     * beperkter. Idempotency-Key gaat NIET via deze forwarder — die wordt
     * via SDK-config gepropageerd (D-06).
     */
    final class MollieHeaderForwarder
    {
        /** @var list<string> */
        private const ALLOWED = ['Accept', 'Content-Type'];

        /** @return array<string, string> */
        public static function forward(Request $request): array
        {
            $out = [];
            foreach (self::ALLOWED as $name) {
                $value = $request->header($name);
                if (is_string($value) && $value !== '') {
                    $out[$name] = $value;
                }
            }
            return $out;
        }
    }
    ```

    **Stap 2 — TDD voor MollieUpstreamErrorMapper. RED FIRST.**

    `php artisan make:test --unit Support/Mollie/MollieUpstreamErrorMapperTest --no-interaction`

    Open `tests/Unit/Support/Mollie/MollieUpstreamErrorMapperTest.php` en schrijf 7 falende tests vóór de mapper bestaat:

    ```php
    <?php

    namespace Tests\Unit\Support\Mollie;

    use App\Support\Mollie\MollieUpstreamErrorMapper;
    use Emeq\MollieApi\Exceptions\AuthenticationException;
    use Emeq\MollieApi\Exceptions\MollieException;
    use Emeq\MollieApi\Exceptions\NotFoundException;
    use Emeq\MollieApi\Exceptions\RateLimitException;
    use Emeq\MollieApi\Exceptions\ServerException;
    use Emeq\MollieApi\Exceptions\ValidationException;
    use PHPUnit\Framework\TestCase;

    class MollieUpstreamErrorMapperTest extends TestCase
    {
        public function test_validation_exception_maps_to_422_validation_failed(): void
        {
            $result = MollieUpstreamErrorMapper::mapException(new ValidationException('amount.value invalid'));

            $this->assertSame(422, $result['status']);
            $this->assertSame('validation_failed', $result['body']['error']);
            $this->assertNull($result['short_code']);
        }

        public function test_authentication_exception_maps_to_502_with_short_code_mollie_auth(): void
        {
            $result = MollieUpstreamErrorMapper::mapException(new AuthenticationException('401 from Mollie'));

            $this->assertSame(502, $result['status']);
            $this->assertSame('mollie_auth_failed', $result['body']['error']);
            $this->assertSame('mollie_auth', $result['short_code']);
        }

        public function test_not_found_exception_maps_to_404_not_found(): void
        {
            $result = MollieUpstreamErrorMapper::mapException(new NotFoundException('payment not found'));

            $this->assertSame(404, $result['status']);
            $this->assertSame('not_found', $result['body']['error']);
            $this->assertNull($result['short_code']);
        }

        public function test_rate_limit_exception_maps_to_429_rate_limited(): void
        {
            $result = MollieUpstreamErrorMapper::mapException(new RateLimitException('rate limited'));

            $this->assertSame(429, $result['status']);
            $this->assertSame('rate_limited', $result['body']['error']);
            $this->assertNull($result['short_code']);
            // Retry-After header — mag null zijn als Mollie's RateLimitException geen retry-after exposeert
        }

        public function test_server_exception_maps_to_502_with_short_code_mollie_5xx(): void
        {
            $result = MollieUpstreamErrorMapper::mapException(new ServerException('500 from Mollie'));

            $this->assertSame(502, $result['status']);
            $this->assertSame('mollie_unavailable', $result['body']['error']);
            $this->assertSame('mollie_5xx', $result['short_code']);
        }

        public function test_base_mollie_exception_maps_to_502_mollie_error_unknown(): void
        {
            $result = MollieUpstreamErrorMapper::mapException(new MollieException('unknown'));

            $this->assertSame(502, $result['status']);
            $this->assertSame('mollie_error', $result['body']['error']);
            $this->assertSame('mollie_unknown', $result['short_code']);
        }

        public function test_unexpected_throwable_maps_to_502_mollie_error_unknown(): void
        {
            $result = MollieUpstreamErrorMapper::mapException(new \RuntimeException('whoops'));

            $this->assertSame(502, $result['status']);
            $this->assertSame('mollie_error', $result['body']['error']);
            $this->assertSame('mollie_unknown', $result['short_code']);
        }
    }
    ```

    Run: `php artisan test --compact --filter=MollieUpstreamErrorMapperTest` — MOET FAILEN met "class not found".

    **Stap 3 — GREEN. Maak `app/Support/Mollie/MollieUpstreamErrorMapper.php`:**

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Support\Mollie;

    use Emeq\MollieApi\Exceptions\AuthenticationException;
    use Emeq\MollieApi\Exceptions\MollieException;
    use Emeq\MollieApi\Exceptions\NotFoundException;
    use Emeq\MollieApi\Exceptions\RateLimitException;
    use Emeq\MollieApi\Exceptions\ServerException;
    use Emeq\MollieApi\Exceptions\ValidationException;
    use Throwable;

    /**
     * Mapt Mollie-SDK-exceptions (Emeq\MollieApi\Exceptions\*) naar
     * een Hub-HTTP-response (status + JSON-body + extra headers + audit-short-code).
     *
     * Policy-bron: 05a-CONTEXT.md D-13 + .docs/decisions/mollie-passthrough-api.md.
     * 401/403 worden bewust naar 502 cloaked om Mollie-auth-state niet te
     * onthullen aan de Consumer.
     */
    final class MollieUpstreamErrorMapper
    {
        /**
         * @return array{status: int, body: array<string, mixed>, headers: array<string, string>, short_code: ?string}
         */
        public static function mapException(Throwable $exception): array
        {
            if ($exception instanceof ValidationException) {
                $body = [
                    'error' => 'validation_failed',
                    'message' => $exception->getMessage(),
                    'upstream_status' => 422,
                ];
                if (method_exists($exception, 'getField') && ($field = $exception->getField()) !== null) {
                    $body['field'] = $field;
                }
                return [
                    'status' => 422,
                    'body' => $body,
                    'headers' => [],
                    'short_code' => null,
                ];
            }

            if ($exception instanceof AuthenticationException) {
                return [
                    'status' => 502,
                    'body' => [
                        'error' => 'mollie_auth_failed',
                        'message' => 'Upstream auth failed',
                        'upstream_status' => 401,
                        'upstream_detail' => 'authentication_failed',
                    ],
                    'headers' => [],
                    'short_code' => 'mollie_auth',
                ];
            }

            if ($exception instanceof NotFoundException) {
                return [
                    'status' => 404,
                    'body' => [
                        'error' => 'not_found',
                        'message' => $exception->getMessage(),
                        'upstream_status' => 404,
                    ],
                    'headers' => [],
                    'short_code' => null,
                ];
            }

            if ($exception instanceof RateLimitException) {
                $headers = [];
                // Verifieer bij implement: heeft RateLimitException een retry-after-getter?
                // Bv. method_exists($exception, 'getRetryAfter') || ->retryAfterSeconds property.
                // Als WEL → $headers['Retry-After'] = (string) $value;
                // Als NIET → laat headers leeg; Mollie's docs zeggen client mag 60s default-aanhouden.
                return [
                    'status' => 429,
                    'body' => [
                        'error' => 'rate_limited',
                        'message' => $exception->getMessage(),
                        'upstream_status' => 429,
                    ],
                    'headers' => $headers,
                    'short_code' => null,
                ];
            }

            if ($exception instanceof ServerException) {
                return [
                    'status' => 502,
                    'body' => [
                        'error' => 'mollie_unavailable',
                        'message' => 'Mollie returned 5xx',
                        'upstream_status' => 503,
                        'upstream_detail' => 'server_error',
                    ],
                    'headers' => [],
                    'short_code' => 'mollie_5xx',
                ];
            }

            // MollieException (base) + onverwachte \Throwable → catch-all
            return [
                'status' => 502,
                'body' => [
                    'error' => 'mollie_error',
                    'message' => 'Unexpected upstream failure',
                    'upstream_status' => 0,
                    'upstream_detail' => 'unknown',
                ],
                'headers' => [],
                'short_code' => 'mollie_unknown',
            ];
        }
    }
    ```

    **Stap 4 — Run tests groen:**
    ```
    vendor/bin/pint --dirty --format agent
    php artisan test --compact --filter=MollieUpstreamErrorMapperTest
    ```
    7/7 moet groen zijn.
  </action>
  <verify>
    <automated>php artisan test --compact --filter=MollieUpstreamErrorMapperTest</automated>
  </verify>
  <acceptance_criteria>
    - `test -f app/Support/Mollie/MollieUpstreamErrorMapper.php`
    - `test -f app/Support/Mollie/MollieHeaderForwarder.php`
    - `grep -c "final class MollieUpstreamErrorMapper" app/Support/Mollie/MollieUpstreamErrorMapper.php` == 1
    - `grep -c "final class MollieHeaderForwarder" app/Support/Mollie/MollieHeaderForwarder.php` == 1
    - `grep -cE "(ValidationException|AuthenticationException|NotFoundException|RateLimitException|ServerException|MollieException)" app/Support/Mollie/MollieUpstreamErrorMapper.php` >= 6
    - `grep -cE "'mollie_auth'|'mollie_5xx'|'mollie_unknown'" app/Support/Mollie/MollieUpstreamErrorMapper.php` == 3
    - `grep -v '^//' app/Support/Mollie/MollieHeaderForwarder.php | grep -v '^#' | grep -c "Idempotency-Key"` == 0 (per D-06 NIET in whitelist)
    - `grep -v '^//' app/Support/Mollie/MollieHeaderForwarder.php | grep -v '^#' | grep -c "If-Match"` == 0
    - `grep -cE "public function test_" tests/Unit/Support/Mollie/MollieUpstreamErrorMapperTest.php` >= 7
    - `php artisan test --compact --filter=MollieUpstreamErrorMapperTest` exit 0
  </acceptance_criteria>
  <done>Mapper en forwarder bestaan, 7 unit-tests groen, mapping-tabel matched D-13 exact.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Migration + Consumer-model + factory voor webhook-callback-velden</name>
  <files>
    database/migrations/2026_05_16_000001_add_webhook_callback_to_consumers_table.php,
    app/Models/Consumer.php,
    database/factories/ConsumerFactory.php
  </files>
  <behavior>
    Voeg twee kolommen toe aan `consumers`-tabel voor de Hub-uitgegeven Consumer-callback-URL en HMAC-secret (D-09):

    - `webhook_callback_url` — string nullable. Plain (geen secret).
    - `webhook_callback_secret` — text nullable, encrypted-at-rest via Eloquent `encrypted` cast. Door Hub uitgegeven (NIET Mollie's secret) zodat de Consumer Hub's signature kan verifiëren.

    **Migration is forward-only conform PROJECT.md invariant** — `down()` mag bestaan voor lokaal-rollback maar wordt nooit in productie gebruikt.

    **Consumer-model:** `webhook_callback_url` en `webhook_callback_secret` aan `#[Fillable]` toevoegen + `casts()`-method overriden om `webhook_callback_secret => 'encrypted'` te zetten.

    **Factory-state:** `withWebhookCallback(string $url = 'https://example.test/hooks')` op `ConsumerFactory` zodat tests een Consumer met callback-config kunnen seeden.

    **Encryption-bewijs in feature-test (komt in Task 4):** raw secret is nooit zichtbaar in `DB::table('consumers')->value('webhook_callback_secret')` — alleen via model-accessor.
  </behavior>
  <read_first>
    - database/migrations/2026_05_15_000001_add_oauth_state_to_connections_table.php (template — kolommen-toevoegen-pattern)
    - database/migrations/2026_05_14_000001_create_consumers_table.php (huidige consumers-schema)
    - app/Models/Consumer.php (huidige #[Fillable] + class-shape)
    - app/Models/Connection.php (template voor casts() met 'encrypted' — regels 53-66)
    - database/factories/ConsumerFactory.php (huidige states)
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-CONTEXT.md sectie `<decisions>` D-09
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-PATTERNS.md regels 723-770
    - .ai/rules/global.md (encryption-at-rest invariant)
  </read_first>
  <action>
    **Stap 1 — Migration aanmaken:**

    ```bash
    php artisan make:migration add_webhook_callback_to_consumers_table --table=consumers --no-interaction
    ```

    De Artisan-make-command genereert een file met huidige timestamp; **hernoem of overschrijf** zodat de filename `2026_05_16_000001_add_webhook_callback_to_consumers_table.php` is (zelfde datum-naming als 5b's `2026_05_15_*`-pattern).

    Inhoud:

    ```php
    <?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration
    {
        public function up(): void
        {
            Schema::table('consumers', function (Blueprint $table): void {
                $table->string('webhook_callback_url')->nullable()->after('slug');
                $table->text('webhook_callback_secret')->nullable()->after('webhook_callback_url');
            });
        }

        public function down(): void
        {
            Schema::table('consumers', function (Blueprint $table): void {
                $table->dropColumn(['webhook_callback_url', 'webhook_callback_secret']);
            });
        }
    };
    ```

    **Stap 2 — Update `app/Models/Consumer.php`:**

    Vervang de bestaande #[Fillable]-attribuut + voeg `casts()`-method toe:

    ```php
    <?php

    namespace App\Models;

    use Database\Factories\ConsumerFactory;
    use Illuminate\Database\Eloquent\Attributes\Fillable;
    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Relations\HasMany;
    use Illuminate\Foundation\Auth\User as Authenticatable;
    use Laravel\Sanctum\HasApiTokens;

    #[Fillable(['name', 'slug', 'webhook_callback_url', 'webhook_callback_secret'])]
    class Consumer extends Authenticatable
    {
        /** @use HasFactory<ConsumerFactory> */
        use HasApiTokens, HasFactory;

        /** @return array<string, string> */
        protected function casts(): array
        {
            return [
                'webhook_callback_secret' => 'encrypted',
            ];
        }

        public function accounts(): HasMany
        {
            return $this->hasMany(Account::class);
        }
    }
    ```

    **Stap 3 — Voeg state toe aan `database/factories/ConsumerFactory.php`:**

    Voeg onderaan de class een nieuwe state toe (laat bestaande definition() en eventueel andere states staan):

    ```php
    public function withWebhookCallback(?string $url = null, ?string $secret = null): static
    {
        return $this->state(fn (array $attributes) => [
            'webhook_callback_url' => $url ?? 'https://example.test/hooks',
            'webhook_callback_secret' => $secret ?? 'whsec_'.\Illuminate\Support\Str::random(32),
        ]);
    }
    ```

    **Stap 4 — Migrate + smoke-test encryption:**

    ```bash
    php artisan migrate
    vendor/bin/pint --dirty --format agent
    php artisan test --compact
    ```

    Volledige bestaande suite moet groen blijven (encryption-at-rest tests die al bestaan voor Connection blijven groen; nieuwe Consumer-secret-cast wordt in Task 4 indirect getest via MolliePassThroughResolutionTest die een Consumer aanmaakt).
  </action>
  <verify>
    <automated>php artisan migrate --pretend 2>&1 | grep -c "add_webhook_callback_to_consumers_table" && php artisan test --compact</automated>
  </verify>
  <acceptance_criteria>
    - `test -f database/migrations/2026_05_16_000001_add_webhook_callback_to_consumers_table.php`
    - `grep -cE "webhook_callback_url|webhook_callback_secret" database/migrations/2026_05_16_000001_add_webhook_callback_to_consumers_table.php` >= 4 (2 in up, 2 in down)
    - `grep -c "Schema::table('consumers'" database/migrations/2026_05_16_000001_add_webhook_callback_to_consumers_table.php` >= 1
    - `grep -c "webhook_callback_url" app/Models/Consumer.php` >= 1
    - `grep -c "webhook_callback_secret" app/Models/Consumer.php` >= 2 (in #[Fillable] én in casts())
    - `grep -c "'encrypted'" app/Models/Consumer.php` == 1
    - `grep -c "withWebhookCallback" database/factories/ConsumerFactory.php` == 1
    - `php artisan migrate --pretend` exit 0 en toont nieuwe migration
    - `php artisan test --compact` exit 0 (geen regressies)
  </acceptance_criteria>
  <done>Migration draait, Consumer-model heeft fillable + encrypted cast, factory heeft withWebhookCallback-state.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: ResolveMollieAccount-middleware + AbstractMolliePassThroughController + alias-registratie + test-trait</name>
  <files>
    app/Http/Middleware/ResolveMollieAccount.php,
    app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php,
    bootstrap/app.php,
    config/services.php,
    .env.example,
    tests/Concerns/BindsMollieConnectionContext.php,
    tests/Feature/Api/V1/Mollie/MolliePassThroughResolutionTest.php
  </files>
  <behavior>
    **ResolveMollieAccount-middleware** — exact mirror van `ResolveSnelstartAccount` voor de tenant-resolutie-stappen, MAAR met cruciale afwijking voor de binding (D-03):

    1. Lees `X-Account-Id`. Ontbreekt of leeg → 400 `{error:'missing_account_header', message:'Vereiste header X-Account-Id ontbreekt.'}`
    2. `Account::where('consumer_id', $request->user()->getKey())->where('external_id', $headerValue)->first()`. Geen match → 404 `{error:'account_not_found', message:'Account niet gevonden voor deze Consumer.'}`
    3. `Connection::where('account_id', $account->getKey())->where('provider', 'mollie')->whereNull('revoked_at')->first()`. Geen match → 404 `{error:'connection_not_found', message:'Geen actieve Mollie-Connection voor dit Account.'}`
    4. **NIET** `app()->instance(MollieCredentialResolver::class, ...)` rebinden zoals Snelstart-middleware doet. WEL: `app(MollieConnectionContext::class)->set($connection)`. AppServiceProvider regel 25 doet `$this->app->scoped(MollieConnectionContext::class)` (per-request singleton); `HubMollieCredentialResolver::resolve()` leest die context via constructor-injection.
    5. **GEEN** `app()->forgetInstance(Mollie::class)` aanroep. Reden: `Mollie::class` is wel singleton (zie `packages/mollie-api/src/MollieServiceProvider.php` regel 31), MAAR `Mollie::client()` bouwt een NIEUWE `MollieApiClient` per call (regel 60 — `new MollieApiClient()`) en roept `$this->credentials()` per call aan, die op zijn beurt `$this->resolver->resolve()` doet — dus elke `Mollie::client()`-call leest fresh context. Geen forget nodig. (Verifieer dit door `Mollie.php` regels 50-75 te lezen vóór implement; als de client toch wordt gecached, voeg `app()->forgetInstance(Mollie::class)` toe.)
    6. `$request->attributes->set('mollie_account', $account)` + `$request->attributes->set('mollie_connection', $connection)`
    7. `return $next($request)`

    **AbstractMolliePassThroughController** — abstract base waar alle resource-controllers van plans 05a-03..05 op bouwen. Levert een `protected function handle(Request $request, string $endpoint, callable $sdkCall): Response` method:

    1. Method-determinatie via `$request->method()`
    2. Ability-guard inline (per D-14):
       - GET → any-of `[MOLLIE_READ, MOLLIE_WRITE, ADMIN]`
       - POST/PATCH/DELETE → any-of `[MOLLIE_WRITE, ADMIN]`
       - Faal → 403 `{error:'insufficient_ability', message:'Token mist vereiste ability voor deze methode.'}`
    3. Content-Type-guard voor POST/PATCH (D-05):
       - Vereist `application/json`-prefix
       - Faal → 415 `{error:'unsupported_content_type', message:'Pass-through accepteert alleen application/json voor POST/PATCH.'}`
    4. Try-block: `$result = $sdkCall($request);` — concrete subclass levert de Mollie-call. Result-shape: `array<string, mixed>` (Mollie-resource `->toArray()`-output). Default response-status: 200 voor GET/PATCH/DELETE; 201 voor POST. Concrete subclass kan via wrapper-array `['status' => 201, 'body' => [...]]` overrulen — als `$result` numerieke index 'status' bevat én array 'body' bevat, gebruik die; anders is `$result` de body en is status 200/201 op basis van method.
    5. Catch (\Throwable $e): `MollieUpstreamErrorMapper::mapException($e)` → status + JSON-body + headers + short_code.
    6. Audit-write naar `pass_through_calls` met **alle drie 5b-CRITICAL-fixes**:
       - `provider` = `'mollie'`
       - `path` = `$endpoint` (template, GEEN query-string, GEEN PII)
       - `query_keys` = `$request->query() !== [] ? implode(',', array_keys($request->query())) : null`
       - `request_fingerprint` = NULL bij empty/null body, anders `substr(hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)), 0, 12)`
    7. Return: `response($responseBody, $status)->withHeaders(['Content-Type' => 'application/json'] + $extraHeaders)`

    **Alias-registratie in `bootstrap/app.php`:** Behoud bestaande `'resolve.snelstart.account'` en `'abilities'`/`'ability'`-aliassen. Voeg toe `'resolve.mollie.account' => ResolveMollieAccount::class`.

    **`config/services.php` aanpassing:** Voeg sub-key `'webhook_secret' => env('MOLLIE_WEBHOOK_SECRET')` toe binnen het bestaande `'mollie'`-blok (zonder bestaande Connect-keys uit Phase 4 te wijzigen). Als `'mollie'`-blok nog niet bestaat: voeg het hele blok toe.

    **`.env.example` aanpassing:** Voeg toe (gescheiden blok):
    ```
    # Mollie webhook signing — platform-secret van Connect (D-08)
    MOLLIE_WEBHOOK_SECRET=
    ```

    **Test-trait `Tests\Concerns\BindsMollieConnectionContext`:** kleine helper om in tests een Connection in de context te zetten zonder middleware te triggeren — handig voor tests die direct Mollie::client() aanroepen.

    **Feature-test `MolliePassThroughResolutionTest`** — bewijst de middleware-flow zonder een echte SDK-call te doen. Deze test vereist een geregistreerde route — gebruik daarvoor een tijdelijke test-only route binnen `setUp()`:

    ```php
    Route::middleware(['auth:sanctum', 'resolve.mollie.account'])
        ->get('/v1/__test__/mollie-resolution', fn (Request $r) => response()->json([
            'account' => $r->attributes->get('mollie_account')?->external_id,
            'connection_id' => $r->attributes->get('mollie_connection')?->id,
        ]));
    ```

    Cases (~7):
    1. `test_missing_x_account_id_header_returns_400_missing_account_header`
    2. `test_unknown_x_account_id_returns_404_account_not_found`
    3. `test_other_consumers_account_id_returns_404_not_403` (info-disclosure-policy)
    4. `test_account_without_active_mollie_connection_returns_404_connection_not_found` (revoked_at gezet)
    5. `test_account_with_only_snelstart_connection_returns_404_connection_not_found` (provider-filter)
    6. `test_unauthenticated_request_returns_401`
    7. `test_happy_path_sets_mollie_account_and_mollie_connection_attributes_and_mollie_connection_context`
  </behavior>
  <read_first>
    - app/Http/Middleware/ResolveSnelstartAccount.php (template — exact mirror, andere binding-pad regel 66-74)
    - app/Mollie/MollieConnectionContext.php (verifieer set/current/has API)
    - app/Mollie/HubMollieCredentialResolver.php (verifieer dat 'ie via constructor-injection MollieConnectionContext krijgt — dan klopt D-03 dat geen forgetInstance nodig is)
    - app/Providers/AppServiceProvider.php (regel 25: scoped MollieConnectionContext; regel 34: bind MollieCredentialResolver → HubMollieCredentialResolver)
    - packages/mollie-api/src/Mollie.php (verifieer Mollie::client() bouwt fresh per call — regels 50-75)
    - packages/mollie-api/src/MollieServiceProvider.php (verifieer Mollie::class IS singleton, regel 31)
    - bootstrap/app.php (huidige aliases — toevoegen zonder bestaande te breken)
    - config/services.php (huidig 'mollie'-blok van Phase 4)
    - .env.example (huidige MOLLIE_*-keys van Phase 4 — voor structuur-consistency)
    - app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php (template voor try/catch + audit-write + ability-guard + 415-guard — alle 5b-CRITICAL-fixes ingebakken)
    - app/Models/PassThroughCall.php (fillable-array)
    - app/Sanctum/TokenAbilities.php (MOLLIE_READ + MOLLIE_WRITE + ADMIN constants)
    - tests/Concerns/PrimesSnelstartTokenCache.php (template voor trait-shape)
    - tests/Feature/Api/V1/Snelstart/PassThroughResolutionTest.php (template — 7 mirror-cases)
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-CONTEXT.md sectie `<decisions>` D-01, D-03, D-05, D-14
    - .planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-PATTERNS.md regels 80-200 (AbstractMolliePassThroughController) + 405-498 (ResolveMollieAccount)
  </read_first>
  <action>
    **Stap 1 — Maak directory-structuur:**

    ```bash
    mkdir -p app/Http/Controllers/Api/V1/Mollie tests/Concerns tests/Feature/Api/V1/Mollie
    ```

    **Stap 2 — Maak `app/Http/Middleware/ResolveMollieAccount.php`:**

    ```bash
    php artisan make:middleware ResolveMollieAccount --no-interaction
    ```

    Vul in:

    ```php
    <?php

    namespace App\Http\Middleware;

    use App\Models\Account;
    use App\Models\Connection;
    use App\Mollie\MollieConnectionContext;
    use Closure;
    use Illuminate\Http\Request;
    use Symfony\Component\HttpFoundation\Response;

    /**
     * Mirror van ResolveSnelstartAccount voor Mollie. Verschilt in stap 4:
     * gebruikt MollieConnectionContext::set() ipv container-rebind, omdat
     * AppServiceProvider regel 25 MollieConnectionContext als scoped binding
     * registreert en HubMollieCredentialResolver erop leest. Geen forgetInstance
     * van Mollie::class nodig — Mollie::client() bouwt elke call een verse
     * MollieApiClient via fresh resolve() (zie packages/mollie-api/src/Mollie.php).
     *
     * Beslissingen in 05a-CONTEXT.md §<decisions> D-03.
     */
    class ResolveMollieAccount
    {
        public function handle(Request $request, Closure $next): Response
        {
            $accountHeader = $request->header('X-Account-Id');

            if (! is_string($accountHeader) || $accountHeader === '') {
                return response()->json([
                    'error' => 'missing_account_header',
                    'message' => 'Vereiste header X-Account-Id ontbreekt.',
                ], 400);
            }

            $consumerId = $request->user()?->getKey();

            $account = Account::query()
                ->where('consumer_id', $consumerId)
                ->where('external_id', $accountHeader)
                ->first();

            if ($account === null) {
                return response()->json([
                    'error' => 'account_not_found',
                    'message' => 'Account niet gevonden voor deze Consumer.',
                ], 404);
            }

            $connection = Connection::query()
                ->where('account_id', $account->getKey())
                ->where('provider', 'mollie')
                ->whereNull('revoked_at')
                ->first();

            if ($connection === null) {
                return response()->json([
                    'error' => 'connection_not_found',
                    'message' => 'Geen actieve Mollie-Connection voor dit Account.',
                ], 404);
            }

            app(MollieConnectionContext::class)->set($connection);

            $request->attributes->set('mollie_account', $account);
            $request->attributes->set('mollie_connection', $connection);

            return $next($request);
        }
    }
    ```

    **Stap 3 — Maak `app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php`:**

    ```php
    <?php

    namespace App\Http\Controllers\Api\V1\Mollie;

    use App\Http\Controllers\Controller;
    use App\Models\Account;
    use App\Models\Connection;
    use App\Models\PassThroughCall;
    use App\Sanctum\TokenAbilities;
    use App\Support\Mollie\MollieUpstreamErrorMapper;
    use Illuminate\Http\Request;
    use Symfony\Component\HttpFoundation\Response;
    use Throwable;

    /**
     * Abstract base voor Mollie-pass-through-controllers. Concrete subclasses
     * leveren een SDK-call via de $sdkCall callable; deze base regelt
     * ability-guard (D-14), 415-guard (D-05), exception-mapping (D-13),
     * audit-write naar pass_through_calls (D-05) en response-render.
     *
     * Beslissingen: 05a-CONTEXT.md §<decisions> D-01, D-05, D-13, D-14.
     */
    abstract class AbstractMolliePassThroughController extends Controller
    {
        /**
         * Voer een Mollie-SDK-call uit binnen het pass-through-frame.
         *
         * @param  string  $endpoint  Endpoint-template ZONDER query-string, bv.
         *                            '/v2/payments' of '/v2/payments/{id}'.
         *                            Komt verbatim in de pass_through_calls.path-kolom.
         * @param  callable(Request): array<string,mixed>  $sdkCall  Levert de
         *                            Mollie-resource-array (uit ->toArray()) terug.
         *                            Mag een wrapper-array {status, body} returnen
         *                            om non-default status (bv. 201) te forceren.
         */
        protected function handle(Request $request, string $endpoint, callable $sdkCall): Response
        {
            $method = strtoupper($request->method());

            // 1. Ability-guard (D-14)
            $required = $method === 'GET'
                ? [TokenAbilities::MOLLIE_READ, TokenAbilities::MOLLIE_WRITE, TokenAbilities::ADMIN]
                : [TokenAbilities::MOLLIE_WRITE, TokenAbilities::ADMIN];

            $token = $request->user()?->currentAccessToken();
            $hasAbility = $token !== null && collect($required)->contains(fn (string $ability) => $token->can($ability));

            if (! $hasAbility) {
                return response()->json([
                    'error' => 'insufficient_ability',
                    'message' => 'Token mist vereiste ability voor deze methode.',
                ], Response::HTTP_FORBIDDEN);
            }

            // 2. 415-guard voor write-methods (D-05)
            $body = null;
            if (in_array($method, ['POST', 'PATCH'], true)) {
                $contentType = strtolower((string) $request->header('Content-Type', ''));
                if (! str_starts_with($contentType, 'application/json')) {
                    return response()->json([
                        'error' => 'unsupported_content_type',
                        'message' => 'Pass-through accepteert alleen application/json voor POST/PATCH.',
                    ], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
                }
                $body = $request->json()->all();
            }

            // 3. SDK-call + exception-mapping
            $start = microtime(true);
            $upstreamError = null;
            $responseBody = '';
            $status = $method === 'POST' ? 201 : 200;
            $contentType = 'application/json';
            $extraHeaders = [];

            try {
                $result = $sdkCall($request);
                // Concrete subclass kan {status, body} wrapper returnen voor non-default status
                if (is_array($result) && isset($result['status'], $result['body']) && is_int($result['status']) && is_array($result['body'])) {
                    $status = $result['status'];
                    $responseBody = json_encode($result['body'], JSON_THROW_ON_ERROR);
                } else {
                    $responseBody = json_encode($result, JSON_THROW_ON_ERROR);
                }
            } catch (Throwable $e) {
                $mapped = MollieUpstreamErrorMapper::mapException($e);
                $status = $mapped['status'];
                $responseBody = json_encode($mapped['body'], JSON_THROW_ON_ERROR);
                $extraHeaders = $mapped['headers'];
                $upstreamError = $mapped['short_code'];
            }

            // 4. Audit-write (D-05 — alle drie 5b-CRITICAL-fixes ingebakken)
            /** @var Account $account */
            $account = $request->attributes->get('mollie_account');
            /** @var Connection $connection */
            $connection = $request->attributes->get('mollie_connection');
            $query = $request->query();

            PassThroughCall::create([
                'consumer_id' => $request->user()->getKey(),
                'account_id' => $account->getKey(),
                'connection_id' => $connection->getKey(),
                'provider' => 'mollie',
                'method' => $method,
                'path' => $endpoint,                       // CRITICAL: template, GEEN query-string
                'query_keys' => $query !== [] ? implode(',', array_keys($query)) : null,
                'status' => $status,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'request_fingerprint' => (is_array($body) && $body !== [])
                    ? substr(hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)), 0, 12)
                    : null,                                 // CRITICAL: NULL bij lege body
                'response_size_bytes' => strlen($responseBody),
                'upstream_error' => $upstreamError,
                'created_at' => now(),
            ]);

            return response($responseBody, $status)->withHeaders(array_merge(
                ['Content-Type' => $contentType],
                $extraHeaders,
            ));
        }
    }
    ```

    **Stap 4 — Update `bootstrap/app.php`:**

    Voeg `'resolve.mollie.account' => ResolveMollieAccount::class` toe aan de bestaande alias-array. Gebruik exacte structuur:

    ```php
    use App\Http\Middleware\ResolveMollieAccount;
    use App\Http\Middleware\ResolveSnelstartAccount;
    // ... rest of imports

    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SetNoIndexHeaders::class);
        $middleware->api(prepend: ['throttle:api']);
        $middleware->alias([
            'resolve.snelstart.account' => ResolveSnelstartAccount::class,
            'resolve.mollie.account' => ResolveMollieAccount::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ```

    **Stap 5 — Update `config/services.php`:**

    Voeg `'webhook_secret' => env('MOLLIE_WEBHOOK_SECRET')` toe binnen het bestaande `'mollie'`-blok (Phase 4 had al `connect_client_id`/`connect_client_secret`/`connect_redirect_uri`). Behoud alle bestaande keys.

    **Stap 6 — Update `.env.example`:**

    Voeg toe (na bestaande MOLLIE-keys):

    ```
    # Mollie webhook signing — platform-secret van Mollie Connect (D-08)
    MOLLIE_WEBHOOK_SECRET=
    ```

    **Stap 7 — Maak `tests/Concerns/BindsMollieConnectionContext.php`:**

    ```php
    <?php

    namespace Tests\Concerns;

    use App\Models\Connection;
    use App\Mollie\MollieConnectionContext;

    /**
     * Helper voor tests die de MollieConnectionContext direct moeten vullen
     * zonder de ResolveMollieAccount-middleware te triggeren (bv. unit-stijl
     * tests die Mollie::client() rechtstreeks aanroepen). Gebruik vóór elke
     * SDK-call in de test.
     */
    trait BindsMollieConnectionContext
    {
        protected function bindMollieConnection(Connection $connection): void
        {
            app(MollieConnectionContext::class)->set($connection);
        }
    }
    ```

    **Stap 8 — Maak `tests/Feature/Api/V1/Mollie/MolliePassThroughResolutionTest.php` (RED first):**

    ```bash
    php artisan make:test --phpunit Api/V1/Mollie/MolliePassThroughResolutionTest --no-interaction
    ```

    7 cases. Gebruik een tijdelijke test-route zodat de middleware geactiveerd wordt zonder dat een resource-controller bestaat:

    ```php
    <?php

    namespace Tests\Feature\Api\V1\Mollie;

    use App\Models\Account;
    use App\Models\Connection;
    use App\Models\Consumer;
    use App\Mollie\MollieConnectionContext;
    use App\Sanctum\TokenAbilities;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Route;
    use Tests\TestCase;

    class MolliePassThroughResolutionTest extends TestCase
    {
        use RefreshDatabase;

        protected function setUp(): void
        {
            parent::setUp();

            Route::middleware(['auth:sanctum', 'resolve.mollie.account'])
                ->get('/v1/__test__/mollie-resolution', function (Request $request) {
                    return response()->json([
                        'account_external_id' => $request->attributes->get('mollie_account')?->external_id,
                        'connection_id' => $request->attributes->get('mollie_connection')?->getKey(),
                        'context_has' => app(MollieConnectionContext::class)->has(),
                    ]);
                });
        }

        public function test_missing_x_account_id_header_returns_400_missing_account_header(): void
        {
            [$consumer, $token] = $this->setupConsumerWithToken([TokenAbilities::MOLLIE_READ]);

            $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson('/v1/__test__/mollie-resolution')
                ->assertStatus(400)
                ->assertJsonPath('error', 'missing_account_header');
        }

        public function test_unknown_x_account_id_returns_404_account_not_found(): void
        {
            [$consumer, $token] = $this->setupConsumerWithToken([TokenAbilities::MOLLIE_READ]);

            $this->withHeader('Authorization', "Bearer {$token}")
                ->withHeader('X-Account-Id', 'nonexistent-school')
                ->getJson('/v1/__test__/mollie-resolution')
                ->assertStatus(404)
                ->assertJsonPath('error', 'account_not_found');
        }

        public function test_other_consumers_account_id_returns_404_not_403(): void
        {
            [$consumerA, $tokenA] = $this->setupConsumerWithToken([TokenAbilities::MOLLIE_READ]);
            $consumerB = Consumer::factory()->create();
            Account::factory()->for($consumerB)->create(['external_id' => 'school-B']);

            // Consumer A's PAT met Consumer B's account-external-id
            $this->withHeader('Authorization', "Bearer {$tokenA}")
                ->withHeader('X-Account-Id', 'school-B')
                ->getJson('/v1/__test__/mollie-resolution')
                ->assertStatus(404)
                ->assertJsonPath('error', 'account_not_found'); // NIET 403 — info-disclosure-policy
        }

        public function test_account_without_active_mollie_connection_returns_404_connection_not_found(): void
        {
            [$consumer, $token] = $this->setupConsumerWithToken([TokenAbilities::MOLLIE_READ]);
            $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
            Connection::factory()->forMollie()->active()->for($account)->create([
                'revoked_at' => now()->subMinute(),
            ]);

            $this->withHeader('Authorization', "Bearer {$token}")
                ->withHeader('X-Account-Id', 'school-A')
                ->getJson('/v1/__test__/mollie-resolution')
                ->assertStatus(404)
                ->assertJsonPath('error', 'connection_not_found');
        }

        public function test_account_with_only_snelstart_connection_returns_404_connection_not_found(): void
        {
            [$consumer, $token] = $this->setupConsumerWithToken([TokenAbilities::MOLLIE_READ]);
            $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
            Connection::factory()->forSnelstart()->for($account)->create();

            $this->withHeader('Authorization', "Bearer {$token}")
                ->withHeader('X-Account-Id', 'school-A')
                ->getJson('/v1/__test__/mollie-resolution')
                ->assertStatus(404)
                ->assertJsonPath('error', 'connection_not_found');
        }

        public function test_unauthenticated_request_returns_401(): void
        {
            $this->getJson('/v1/__test__/mollie-resolution')->assertStatus(401);
        }

        public function test_happy_path_sets_attributes_and_mollie_connection_context(): void
        {
            [$consumer, $token] = $this->setupConsumerWithToken([TokenAbilities::MOLLIE_READ]);
            $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
            $connection = Connection::factory()->forMollie()->active()->for($account)->create();

            $this->withHeader('Authorization', "Bearer {$token}")
                ->withHeader('X-Account-Id', 'school-A')
                ->getJson('/v1/__test__/mollie-resolution')
                ->assertOk()
                ->assertJsonPath('account_external_id', 'school-A')
                ->assertJsonPath('connection_id', $connection->getKey())
                ->assertJsonPath('context_has', true);
        }

        /** @return array{0: Consumer, 1: string} */
        private function setupConsumerWithToken(array $abilities): array
        {
            $consumer = Consumer::factory()->create();
            $plainToken = $consumer->createToken('test', $abilities)->plainTextToken;

            return [$consumer, $plainToken];
        }
    }
    ```

    **Stap 9 — RED → GREEN cycle:**

    ```bash
    vendor/bin/pint --dirty --format agent
    php artisan test --compact --filter='MolliePassThroughResolutionTest|MollieUpstreamErrorMapperTest'
    php artisan test --compact   # volledige suite — geen regressies
    ```

    Verwacht: 7/7 nieuwe resolution-cases groen + 7/7 mapper-cases groen + alle bestaande tests blijven groen.
  </action>
  <verify>
    <automated>php artisan test --compact --filter='MolliePassThroughResolutionTest|MollieUpstreamErrorMapperTest' && php artisan route:list 2>&1 | grep -c "__test__/mollie-resolution"</automated>
  </verify>
  <acceptance_criteria>
    - `test -f app/Http/Middleware/ResolveMollieAccount.php`
    - `test -f app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php`
    - `test -f tests/Concerns/BindsMollieConnectionContext.php`
    - `grep -c "class ResolveMollieAccount" app/Http/Middleware/ResolveMollieAccount.php` == 1
    - `grep -c "MollieConnectionContext::class" app/Http/Middleware/ResolveMollieAccount.php` == 1
    - `grep -c "->set(\\\$connection)" app/Http/Middleware/ResolveMollieAccount.php` == 1
    - `grep -cE "(missing_account_header|account_not_found|connection_not_found)" app/Http/Middleware/ResolveMollieAccount.php` == 3
    - `grep -c "where('provider', 'mollie')" app/Http/Middleware/ResolveMollieAccount.php` == 1
    - `grep -v '^//' app/Http/Middleware/ResolveMollieAccount.php | grep -v '^[[:space:]]*\*' | grep -c "forgetInstance"` == 0
    - `grep -c "abstract class AbstractMolliePassThroughController" app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php` == 1
    - `grep -c "MollieUpstreamErrorMapper::mapException" app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php` == 1
    - `grep -c "PassThroughCall::create" app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php` == 1
    - `grep -c "'provider' => 'mollie'" app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php` == 1
    - `grep -cE "(insufficient_ability|unsupported_content_type)" app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php` == 2
    - `grep -c "resolve.mollie.account" bootstrap/app.php` == 1
    - `grep -c "ResolveMollieAccount::class" bootstrap/app.php` == 1
    - `grep -c "MOLLIE_WEBHOOK_SECRET" .env.example` >= 1
    - `grep -c "webhook_secret" config/services.php` >= 1
    - `grep -cE "public function test_" tests/Feature/Api/V1/Mollie/MolliePassThroughResolutionTest.php` >= 7
    - `php artisan route:list` toont de tijdelijke __test__-route NIET in productie (tijdelijke route is alleen in setUp() actief — niet in route:list-output)
    - `php artisan test --compact` exit 0
  </acceptance_criteria>
  <done>Middleware + abstract controller + alias + config + test-trait + 7 resolution-tests groen. Foundation is klaar voor plans 05a-02 (webhooks) en 05a-03..05 (resources).</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Consumer-request → Hub-controller (X-Account-Id auth-bound) | Cross-Consumer-account-id → 404 zonder existence-disclosure |
| Hub-middleware → MollieConnectionContext (scoped) | Per-request binding mag niet leaken naar volgende request in dezelfde queue-worker |
| Eloquent `casts()` → Postgres | webhook_callback_secret encrypted-at-rest |
| Mollie-SDK-exception → audit-tabel | Alleen short-code; geen exception-message of stack-trace |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-05a-01 | Spoofing | Cross-Consumer pass-through via X-Account-Id (gebruikt Consumer A's PAT met Consumer B's account-id) | high | mitigate | ResolveMollieAccount filtert `Account::where('consumer_id', $request->user()->getKey())`; mismatch → 404 (NIET 403, info-disclosure-policy). Bewezen door MolliePassThroughResolutionTest case 3. |
| T-05a-02 | Information disclosure | Audit-rij bevat raw request-body via fingerprint-collision op lege body (5b CR-03) | medium | mitigate | request_fingerprint = NULL bij `$body === null || $body === []`. AbstractMolliePassThroughController regel 4 audit-write. Bewezen indirect — geen test in dit plan, maar pattern is identiek aan 5b's hardening (zie `260514-qxk` quick-task). |
| T-05a-03 | Information disclosure | Audit-rij `path`-kolom bevat query-string met PII (5b CR-02) | medium | mitigate | path = $endpoint (template); query_keys-kolom houdt alleen key-namen (geen waardes). AbstractMolliePassThroughController audit-write. |
| T-05a-04 | Tampering | Consumer doet POST/PATCH met text/xml body → silent body-corruption (5b CR-01) | medium | mitigate | 415-guard in AbstractMolliePassThroughController vóór SDK-call: alleen application/json voor POST/PATCH. |
| T-05a-05 | Information disclosure | webhook_callback_secret leakt via `$consumer->toArray()` of DB-dump | medium | mitigate | Eloquent `encrypted` cast op `webhook_callback_secret`; raw bytes in DB zijn ciphertext. Phase 3 ConnectionEncryptionTest-pattern bewijst dit voor Connection — Consumer volgt zelfde cast-mechanisme. |
| T-05a-06 | Information disclosure | Mollie-401/403 lekt naar Consumer (auth-state-disclosure tussen Hub-PAT en Mollie-access_token) | medium | mitigate | MollieUpstreamErrorMapper cloaked AuthenticationException naar 502 + `mollie_auth_failed` (D-13). Bewezen door MollieUpstreamErrorMapperTest. |
</threat_model>

<verification>
- Alle 4 nieuwe app-files bestaan met de exacte class-namen
- 14+ tests groen (7 resolution + 7 mapper)
- Volledige suite blijft groen — geen regressies
- Migration draait + Consumer-model heeft fillable + cast
- bootstrap/app.php heeft beide middleware-aliassen (snelstart + mollie)
- Geen wijziging onder `packages/mollie-api/**` (`git diff --stat packages/mollie-api/ | wc -l` == 0)
- pint clean
</verification>

<success_criteria>
- HUB-03 foundation: alle cross-cutting infrastructuur staat klaar voor plans 05a-02..05a-05
- D-01, D-03, D-05, D-09, D-13, D-14 ingelost in code
- Geen route-laag, geen Mollie-SDK-call — die komt in volgende plans
</success_criteria>

<output>
Na completion: `.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-01-SUMMARY.md` per template, met expliciete vermelding van:
- 4 cross-cutting app-classes geland
- 14+ tests groen
- Eventuele afwijkingen tijdens vendor-Mollie-exception-verifie (RateLimitException retry-after-getter, ValidationException::getField())
- Bevestiging dat `Mollie::client()` per call fresh resolved (D-03 verifieer-punt)
- Trigger `docs-sync` skill als follow-up indien Consumer-schema-wijziging documentatie raakt
</output>
</content>
</invoke>