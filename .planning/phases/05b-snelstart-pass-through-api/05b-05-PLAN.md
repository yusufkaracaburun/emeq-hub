---
phase: 05b-snelstart-pass-through-api
plan: 05
type: execute
wave: 3
depends_on: [05b-01, 05b-02, 05b-03, 05b-04]
files_modified:
  - app/Http/Middleware/ResolveSnelstartAccount.php
  - app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php
  - bootstrap/app.php
  - routes/api.php
  - tests/Feature/Api/V1/Snelstart/PassThroughResolutionTest.php
  - tests/Feature/Api/V1/Snelstart/PassThroughEchoPingTest.php
  - tests/Feature/Api/V1/Snelstart/PassThroughOdataRelatiesTest.php
  - tests/Feature/Api/V1/Snelstart/PassThroughErrorMappingTest.php
  - tests/Feature/Api/V1/Snelstart/PassThroughAuditNoSecretsTest.php
  - tests/Feature/Api/V1/Snelstart/HeaderForwardingTest.php
  - tests/Feature/Api/V1/SanctumAbilityTest.php
  - tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php
autonomous: true
requirements: [HUB-05]
tags:
  - laravel
  - snelstart
  - middleware
  - pass-through
  - audit-log
  - saloon
  - scramble
  - phpunit

must_haves:
  truths:
    - "HUB-05 SC-3: `GET /v1/snelstart/echo/ping` met geldige PAT + `X-Account-Id` proxied naar Snelstart's `/echo/{input}` en returnt 200 — bewijst dat de credential-resolver-binding werkt"
    - "HUB-05 SC-4: `GET /v1/snelstart/relaties?$top=5` proxied verbatim — query-string blijft intact, OData-pad werkt"
    - "HUB-05 SC-5 (pass-through-deel): A's PAT → B's `X-Account-Id` → 404"
    - "HUB-05 SC-6: ontbrekende `X-Account-Id`-header → 400 `missing_account_header`; mismatched header → 404 `account_not_found`"
    - "HUB-05 SC-7: elke pass-through-call landt 1 rij in `pass_through_calls` met `consumer_id, account_id, connection_id, provider, method, path, status, duration_ms, request_fingerprint, response_size_bytes, upstream_error` — raw credentials nergens"
    - "HUB-05 SC-8: `/docs/api`(.json) bevat de catch-all `/v1/snelstart/{path}`-route + de 3 provisioning-routes"
    - "OPTIONS/HEAD/TRACE op `/v1/snelstart/{path}` → 405"
    - "Snelstart 401/403 → Hub 502 met `upstream_error` short-code `snelstart_auth` in audit-rij"
    - "Header-whitelist actief: `Authorization`, `X-Account-Id`, `Cookie`, `User-Agent` worden gestript voor de SDK-call"
    - "Phase 3 `SanctumAbilityTest::test_token_without_required_ability_is_rejected` (incomplete placeholder) is nu een passing 403-test tegen `/v1/snelstart/{path}`"
  artifacts:
    - path: "app/Http/Middleware/ResolveSnelstartAccount.php"
      provides: "Middleware-alias `resolve.snelstart.account` — leest `X-Account-Id`, scoped Account/Connection-lookup, bindt resolver per-request"
      contains: "class ResolveSnelstartAccount"
    - path: "app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php"
      provides: "`__invoke(Request, string $path)` dispatcht op method en spreekt Snelstart via SDK; schrijft audit-rij; mapt errors"
      contains: "class PassThroughController"
    - path: "bootstrap/app.php"
      provides: "Middleware-alias-registratie `resolve.snelstart.account` → ResolveSnelstartAccount"
      contains: "alias"
    - path: "routes/api.php"
      provides: "Catch-all `Route::any('/snelstart/{path}', PassThroughController::class)->where('path', '.*')->middleware(['auth:sanctum', 'resolve.snelstart.account'])`"
      contains: "Route::any.*snelstart"
  key_links:
    - from: "ResolveSnelstartAccount middleware"
      to: "Container binding van SnelstartCredentialResolver"
      via: "app()->instance(SnelstartCredentialResolver::class, new HubSnelstartCredentialResolver($connection))"
      pattern: "app\\(\\)->instance"
    - from: "PassThroughController"
      to: "Snelstart SDK"
      via: "$snelstart = app(Snelstart::class); $snelstart->connector()->send(new RawSnelstartRequest(...))"
      pattern: "RawSnelstartRequest"
    - from: "PassThroughController"
      to: "pass_through_calls audit-tabel"
      via: "PassThroughCall::create([...]) na SDK-call returnt, vóór Hub-response"
      pattern: "PassThroughCall::create"
    - from: "PassThroughController catch-block"
      to: "UpstreamErrorMapper"
      via: "UpstreamErrorMapper::mapException($e)"
      pattern: "UpstreamErrorMapper::"
---

<objective>
Het echte pass-through-werk: middleware + controller + routes + audit-write + zes feature-tests + Scramble-discovery-test + completering van Phase 3's `SanctumAbilityTest`-placeholder.

Purpose: HUB-05 success criteria 3, 4, 5 (pass-through-deel), 6, 7, 8.

Output:
- 1 middleware (`ResolveSnelstartAccount`)
- 1 controller (`PassThroughController`) met audit-write inline
- 1 route + 1 middleware-alias-registratie
- 6 nieuwe feature-tests onder `tests/Feature/Api/V1/Snelstart/`
- 1 documentation-test onder `tests/Feature/Documentation/`
- 1 update aan `tests/Feature/Api/SanctumAbilityTest.php` (placeholder → passing test)

**Niet** in dit plan: nieuwe support-classes (alles uit Plans 01-03 wordt geconsumeerd); aanpassingen aan SDK (`packages/snelstart-api/`) — strikt verboden door de SDK-grens-invariant uit PROJECT.md.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md
@CLAUDE.md
@app/Http/Controllers/Api/V1/PingController.php
@app/Http/Controllers/Api/V1/AccountController.php
@app/Models/Connection.php
@app/Sanctum/TokenAbilities.php
@app/Services/Snelstart/HubSnelstartCredentialResolver.php
@app/Support/Snelstart/UpstreamErrorMapper.php
@app/Support/Snelstart/HeaderForwarder.php
@app/Models/PassThroughCall.php
@routes/api.php
@bootstrap/app.php
@packages/snelstart-api/src/Snelstart.php
@packages/snelstart-api/src/Http/Request/RawSnelstartRequest.php
@packages/snelstart-api/src/Http/SnelstartConnector.php
@packages/snelstart-api/src/Contracts/SnelstartCredentialResolver.php
@packages/snelstart-api/tests/Unit/Http/SnelstartConnectorTest.php
@tests/Feature/Api/SanctumAbilityTest.php

<interfaces>
<!-- Alle contracten die dit plan consumeert. Niets hiervan wordt aangepast. -->

From app/Services/Snelstart/HubSnelstartCredentialResolver.php (Plan 02):
```php
final readonly class HubSnelstartCredentialResolver implements SnelstartCredentialResolver {
    public function __construct(private Connection $connection);
    public function resolve(): SnelstartCredentials;
}
```

From app/Support/Snelstart/UpstreamErrorMapper.php (Plan 03):
```php
final class UpstreamErrorMapper {
    /** @return array{status:int, body:array, headers:array, short_code:?string} */
    public static function mapException(\Throwable $exception): array;
}
```

From app/Support/Snelstart/HeaderForwarder.php (Plan 03):
```php
final class HeaderForwarder {
    /** @return array<string,string> */
    public static function forward(\Illuminate\Http\Request $request): array;
}
```

From app/Models/PassThroughCall.php (Plan 01):
```php
class PassThroughCall extends Model {
    public $timestamps = false;
    // Fillable: consumer_id, account_id, connection_id, provider, method, path,
    //          status, duration_ms, request_fingerprint, response_size_bytes,
    //          upstream_error, created_at
}
```

From packages/snelstart-api/src/Snelstart.php (SDK entry-point):
```php
class Snelstart {
    public function connector(): SnelstartConnector;
    // resolves the bound SnelstartCredentialResolver under the hood
}
```

From packages/snelstart-api/src/Http/Request/RawSnelstartRequest.php:
```php
class RawSnelstartRequest extends BaseRequest {
    public function __construct(
        \Saloon\Enums\Method $method,
        string $endpoint,                     // bv. '/relaties' of '/echo/test'
        array $query = [],                    // ['$top' => 5]
        ?array $body = null,
        array $headers = [],
    );
}
```

Saloon faking (Hub vendor):
```php
// vendor/saloonphp/saloon/src/Http/Faking/MockClient.php
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

// Wire a global mock so every Snelstart connector built during the test cycle is faked.
MockClient::global([
    RawSnelstartRequest::class => MockResponse::make(['pong' => 'ok'], 200),
]);
// Or scoped to a specific connector instance via $connector->withMockClient(new MockClient([...]))
```

SDK error-mapping recall (Plan 03 mapt deze naar HTTP):
- `AuthenticationException` ← Snelstart 401/403
- `ServerException` ← Snelstart 5xx
- `ValidationException` ← Snelstart 400
- `NotFoundException` ← Snelstart 404
- `RateLimitException` ← Snelstart 429
- `\Saloon\Exceptions\Request\FatalRequestException` ← netwerk
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: `ResolveSnelstartAccount`-middleware + alias-registratie</name>
  <files>app/Http/Middleware/ResolveSnelstartAccount.php, bootstrap/app.php</files>
  <behavior>
    Een middleware die op pass-through-routes draait NA `auth:sanctum` en VÓÓR `PassThroughController`. Het:
    1. Leest `X-Account-Id`-header. **Ontbreekt of leeg** → JSON 400 `{error:'missing_account_header', message:'Vereiste header X-Account-Id ontbreekt.'}`
    2. `Account::where('consumer_id', $request->user()->getKey())->where('external_id', $headerValue)->first()`. **Geen match** → 404 `{error:'account_not_found', message:'Account niet gevonden voor deze Consumer.'}` (CONTEXT.md beslissing: 404 niet 403 — voorkomt info-disclosure)
    3. `Connection::where('account_id', $account->getKey())->where('provider', 'snelstart')->whereNull('revoked_at')->first()`. **Geen match** → 404 `{error:'connection_not_found', message:'Geen actieve Snelstart-Connection voor dit Account.'}`
    4. `app()->instance(SnelstartCredentialResolver::class, new HubSnelstartCredentialResolver($connection))` — per-request binding, niet singleton
    5. `$request->attributes->set('snelstart_account', $account)` en `set('snelstart_connection', $connection)` voor de controller en audit-write
    6. `return $next($request)`

    Registreer alias `resolve.snelstart.account` in `bootstrap/app.php` via:
    ```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SetNoIndexHeaders::class);
        $middleware->api(prepend: ['throttle:api']);
        $middleware->alias([
            'resolve.snelstart.account' => \App\Http\Middleware\ResolveSnelstartAccount::class,
        ]);
    })
    ```
    Bestaande append/api-prepend-regels NIET wijzigen — alleen `$middleware->alias([...])` toevoegen.
  </behavior>
  <read_first>
    - bootstrap/app.php (huidige `withMiddleware`-block — alias toevoegen zonder bestaande lines te breken)
    - app/Http/Middleware/SetNoIndexHeaders.php (pattern voor een no-deps middleware in dit project — class skeleton)
    - app/Services/Snelstart/HubSnelstartCredentialResolver.php (Plan 02 output — constructor neemt Connection)
    - app/Models/Connection.php (`whereNull('revoked_at')` pattern)
    - .planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md `<decisions> ### Resolver binding — Per-request scoped middleware`
  </read_first>
  <action>
    Maak `app/Http/Middleware/ResolveSnelstartAccount.php` met `php artisan make:middleware ResolveSnelstartAccount --no-interaction` en pas aan tot:

    ```php
    <?php

    namespace App\Http\Middleware;

    use App\Models\Account;
    use App\Models\Connection;
    use App\Services\Snelstart\HubSnelstartCredentialResolver;
    use Closure;
    use Emeq\SnelstartApi\Contracts\SnelstartCredentialResolver;
    use Illuminate\Http\Request;
    use Symfony\Component\HttpFoundation\Response;

    class ResolveSnelstartAccount
    {
        public function handle(Request $request, Closure $next): Response
        {
            $accountHeader = $request->header('X-Account-Id');

            if (! is_string($accountHeader) || '' === $accountHeader) {
                return response()->json([
                    'error'   => 'missing_account_header',
                    'message' => 'Vereiste header X-Account-Id ontbreekt.',
                ], 400);
            }

            $consumerId = $request->user()?->getKey();

            $account = Account::query()
                ->where('consumer_id', $consumerId)
                ->where('external_id', $accountHeader)
                ->first();

            if (null === $account) {
                return response()->json([
                    'error'   => 'account_not_found',
                    'message' => 'Account niet gevonden voor deze Consumer.',
                ], 404);
            }

            $connection = Connection::query()
                ->where('account_id', $account->getKey())
                ->where('provider', 'snelstart')
                ->whereNull('revoked_at')
                ->first();

            if (null === $connection) {
                return response()->json([
                    'error'   => 'connection_not_found',
                    'message' => 'Geen actieve Snelstart-Connection voor dit Account.',
                ], 404);
            }

            app()->instance(
                SnelstartCredentialResolver::class,
                new HubSnelstartCredentialResolver($connection),
            );

            $request->attributes->set('snelstart_account', $account);
            $request->attributes->set('snelstart_connection', $connection);

            return $next($request);
        }
    }
    ```

    Update `bootstrap/app.php` om de alias te registreren. **Behoud de bestaande regels** voor `SetNoIndexHeaders` en `throttle:api`-prepend; voeg alleen `$middleware->alias([...])` toe onderaan het `withMiddleware`-block.

    Run pint: `vendor/bin/pint --dirty --format agent`.
  </action>
  <verify>
    <automated>php artisan route:list --path=v1 2>&1 >/dev/null && php -l app/Http/Middleware/ResolveSnelstartAccount.php bootstrap/app.php</automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "class ResolveSnelstartAccount" app/Http/Middleware/ResolveSnelstartAccount.php` == 1
    - `grep -c "app()->instance" app/Http/Middleware/ResolveSnelstartAccount.php` == 1
    - `grep -c "HubSnelstartCredentialResolver" app/Http/Middleware/ResolveSnelstartAccount.php` >= 2
    - `grep -cE "(missing_account_header|account_not_found|connection_not_found)" app/Http/Middleware/ResolveSnelstartAccount.php` == 3
    - `grep -c "resolve.snelstart.account" bootstrap/app.php` == 1
    - `grep -c "ResolveSnelstartAccount::class" bootstrap/app.php` == 1
    - `php artisan route:list` exit 0 (alias-registratie heeft geen syntax errors gebroken)
  </acceptance_criteria>
  <done>Middleware bestaat, alias is geregistreerd, container-binding per-request gebeurt, JSON-error-responses zijn consistent.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: `PassThroughController` + audit-write + route</name>
  <files>app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php, routes/api.php</files>
  <behavior>
    `__invoke(Request $request, string $path): \Illuminate\Http\Response|\Illuminate\Http\JsonResponse`:

    1. **Method-check**: alleen `GET`, `POST`, `PATCH`, `DELETE`. OPTIONS/HEAD/TRACE → 405 `{error:'method_not_allowed', message:'HTTP method niet toegestaan op pass-through-route.'}`.

    2. **Ability-check** (CONTEXT.md `### PAT-ability-policy`):
       - GET: `snelstart:read || snelstart:write || *`
       - POST/PATCH/DELETE: `snelstart:write || *`
       Fail → 403 `{error:'insufficient_ability', message:'Token mist vereiste ability voor deze methode.'}`.

    3. **Snelstart-endpoint bouwen**: `$endpoint = '/'.ltrim($path, '/')`. Query-string komt uit `$request->query()` (PHP-superglobal-shape `array<string, scalar>`); body komt uit `$request->json()->all()` voor non-GET (null voor GET/DELETE).

    4. **Headers**: `$forwarded = HeaderForwarder::forward($request);`

    5. **SDK-call**: `$snelstart = app(\Emeq\SnelstartApi\Snelstart::class); $start = microtime(true); try { $response = $snelstart->connector()->send(new RawSnelstartRequest(method: \Saloon\Enums\Method::from($request->method()), endpoint: $endpoint, query: $request->query(), body: $request->isMethod('GET') || $request->isMethod('DELETE') ? null : $request->json()->all(), headers: $forwarded)); $status = $response->status(); $body = $response->body(); $contentType = $response->header('Content-Type') ?? 'application/json'; $upstreamError = null; } catch (\Throwable $e) { $mapped = UpstreamErrorMapper::mapException($e); $status = $mapped['status']; $body = json_encode($mapped['body']); $contentType = 'application/json'; $upstreamError = $mapped['short_code']; $extraHeaders = $mapped['headers']; }`

    6. **Audit-write** (CONTEXT.md `### Audit-timing — Synchroon na response` — schrijven NA SDK-call, vóór `return`):
       ```php
       PassThroughCall::create([
           'consumer_id'         => $request->user()->getKey(),
           'account_id'          => $request->attributes->get('snelstart_account')->getKey(),
           'connection_id'       => $request->attributes->get('snelstart_connection')->getKey(),
           'provider'            => 'snelstart',
           'method'              => $request->method(),
           'path'                => $endpoint.($request->getQueryString() ? '?'.$request->getQueryString() : ''),
           'status'              => $status,
           'duration_ms'         => (int) round((microtime(true) - $start) * 1000),
           'request_fingerprint' => $request->isMethod('GET') || $request->isMethod('DELETE')
               ? null
               : substr(hash('sha256', json_encode($request->json()->all() ?? [])), 0, 12),
           'response_size_bytes' => isset($body) ? strlen($body) : null,
           'upstream_error'      => $upstreamError,
           'created_at'          => now(),
       ]);
       ```

    7. **Response terug**: bij happy path: `return response($body, $status)->withHeaders(['Content-Type' => $contentType])`. Bij error-pad: `return response()->json($mapped['body'], $status)->withHeaders($mapped['headers'])`.

    8. **Géén** raw credentials in audit; `path` bevat alleen Snelstart-endpoint + query-string (geen Bearer of `X-Account-Id` — die zijn header-only en gaan niet de `path`-kolom in).

    **Route** in `routes/api.php` toevoegen binnen de bestaande `Route::middleware('auth:sanctum')->group(...)`:

    ```php
    Route::any('/snelstart/{path}', \App\Http\Controllers\Api\V1\Snelstart\PassThroughController::class)
        ->where('path', '.*')
        ->middleware('resolve.snelstart.account')
        ->name('api.snelstart.passthrough');
    ```

    De `auth:sanctum` zit al op de wrapping `group`. `resolve.snelstart.account` komt erna en faalt voor non-pass-through-routes als die per ongeluk de alias zouden gebruiken. Geen `abilities:`-middleware in de route-definitie — ability-check zit inline in de controller per HTTP-method.

    Import bovenaan `routes/api.php` toevoegen (of FQN inline laten zoals hierboven).
  </behavior>
  <read_first>
    - app/Http/Controllers/Api/V1/PingController.php (single-action `__invoke` pattern + `extends Controller`)
    - app/Http/Controllers/Api/V1/AccountController.php (Plan 04 — `guardAbility` pattern voor ability-tabel-check inline)
    - app/Support/Snelstart/UpstreamErrorMapper.php (Plan 03 — return-shape `{status, body, headers, short_code}`)
    - app/Support/Snelstart/HeaderForwarder.php (Plan 03 — `::forward(Request): array<string,string>`)
    - app/Models/PassThroughCall.php (Plan 01 — fillable-array + `$timestamps = false`)
    - packages/snelstart-api/src/Http/Request/RawSnelstartRequest.php (constructor: method/endpoint/query/body/headers)
    - packages/snelstart-api/src/Snelstart.php (entry-point: `app(Snelstart::class)->connector()->send($request)`)
    - vendor/saloonphp/saloon/src/Enums/Method.php (`Saloon\Enums\Method::from('GET')` etc.)
    - routes/api.php (Plan 04 — uitbreiden zonder bestaande routes te breken)
    - .planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md `### Audit-timing` + `### Audit-log — Nieuwe pass_through_calls-tabel`
  </read_first>
  <action>
    Maak `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php` met `php artisan make:controller --invokable Api/V1/Snelstart/PassThroughController --no-interaction`. Vul de class in per behavior-sectie. Belangrijke implementatie-noten:

    - `$snelstart = app(\Emeq\SnelstartApi\Snelstart::class)` faalt als de resolver-binding nog niet gezet is — de middleware zorgt daarvoor. Geen extra null-check nodig.
    - `\Saloon\Enums\Method::from($request->method())` is veilig — Laravel validateert al methods bij routing, en de OPTIONS/HEAD/TRACE-check filteren we expliciet vóór de SDK-call.
    - `$request->query()` returnt een array. Voor `RawSnelstartRequest` is `array<string, scalar|null>` vereist — Laravel's query-parameters zijn altijd strings dus dat klopt.
    - `$request->getQueryString()` returnt de raw query-string (zonder leading `?`) — gebruik die om de `path`-kolom in `pass_through_calls` te vullen.
    - Voor de happy-path response: het is geen `JsonResponse` maar een raw passthrough — dus `response($body, $status)`. Snelstart returnt application/json voor 99% van de endpoints; voorzichtigheid met OData die ook XML kan doen — voor 5b kunnen we `Content-Type` 1:1 doorzetten van de upstream response.

    Voorbeeld-skeleton (verkort; vul cases volledig in):

    ```php
    <?php

    namespace App\Http\Controllers\Api\V1\Snelstart;

    use App\Http\Controllers\Controller;
    use App\Models\PassThroughCall;
    use App\Sanctum\TokenAbilities;
    use App\Support\Snelstart\HeaderForwarder;
    use App\Support\Snelstart\UpstreamErrorMapper;
    use Emeq\SnelstartApi\Http\Request\RawSnelstartRequest;
    use Emeq\SnelstartApi\Snelstart;
    use Illuminate\Http\Request;
    use Saloon\Enums\Method;
    use Symfony\Component\HttpFoundation\Response;
    use Throwable;

    class PassThroughController extends Controller
    {
        public function __invoke(Request $request, string $path): Response
        {
            $method = strtoupper($request->method());

            if (! in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true)) {
                return response()->json([
                    'error'   => 'method_not_allowed',
                    'message' => 'HTTP method niet toegestaan op pass-through-route.',
                ], 405);
            }

            $required = 'GET' === $method
                ? [TokenAbilities::SNELSTART_READ, TokenAbilities::SNELSTART_WRITE, TokenAbilities::ADMIN]
                : [TokenAbilities::SNELSTART_WRITE, TokenAbilities::ADMIN];

            $token = $request->user()?->currentAccessToken();
            $hasAbility = $token && collect($required)->contains(fn (string $a) => $token->can($a));

            if (! $hasAbility) {
                return response()->json([
                    'error'   => 'insufficient_ability',
                    'message' => 'Token mist vereiste ability voor deze methode.',
                ], 403);
            }

            $endpoint = '/'.ltrim($path, '/');
            $query    = $request->query();
            $headers  = HeaderForwarder::forward($request);
            $body     = in_array($method, ['POST', 'PATCH'], true) ? ($request->json()->all() ?: []) : null;

            $start = microtime(true);
            $upstreamError = null;
            $responseBody = '';
            $status = 0;
            $contentType = 'application/json';
            $extraHeaders = [];

            try {
                /** @var Snelstart $snelstart */
                $snelstart = app(Snelstart::class);

                $sdkRequest = new RawSnelstartRequest(
                    method: Method::from($method),
                    endpoint: $endpoint,
                    query: $query,
                    body: $body,
                    headers: $headers,
                );

                $sdkResponse = $snelstart->connector()->send($sdkRequest);

                $status       = $sdkResponse->status();
                $responseBody = $sdkResponse->body();
                $contentType  = $sdkResponse->header('Content-Type') ?? 'application/json';
            } catch (Throwable $e) {
                $mapped        = UpstreamErrorMapper::mapException($e);
                $status        = $mapped['status'];
                $responseBody  = json_encode($mapped['body'], JSON_THROW_ON_ERROR);
                $contentType   = 'application/json';
                $extraHeaders  = $mapped['headers'];
                $upstreamError = $mapped['short_code'];
            }

            PassThroughCall::create([
                'consumer_id'         => $request->user()->getKey(),
                'account_id'          => $request->attributes->get('snelstart_account')->getKey(),
                'connection_id'       => $request->attributes->get('snelstart_connection')->getKey(),
                'provider'            => 'snelstart',
                'method'              => $method,
                'path'                => $endpoint.($request->getQueryString() ? '?'.$request->getQueryString() : ''),
                'status'              => $status,
                'duration_ms'         => (int) round((microtime(true) - $start) * 1000),
                'request_fingerprint' => null === $body
                    ? null
                    : substr(hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)), 0, 12),
                'response_size_bytes' => strlen($responseBody),
                'upstream_error'      => $upstreamError,
                'created_at'          => now(),
            ]);

            return response($responseBody, $status)->withHeaders(array_merge(
                ['Content-Type' => $contentType],
                $extraHeaders,
            ));
        }
    }
    ```

    Voeg de route toe in `routes/api.php` (binnen de bestaande `auth:sanctum`-group, NA de Plan-04-routes):

    ```php
    Route::any('/snelstart/{path}', \App\Http\Controllers\Api\V1\Snelstart\PassThroughController::class)
        ->where('path', '.*')
        ->middleware('resolve.snelstart.account')
        ->name('api.snelstart.passthrough');
    ```

    Run pint en route-list smoke:
    ```
    vendor/bin/pint --dirty --format agent
    php artisan route:list --path=v1 --except-vendor
    ```
  </action>
  <verify>
    <automated>php artisan route:list --path=v1 --except-vendor 2>&1 | grep -cE "snelstart/\\{path\\}"</automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "class PassThroughController" app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php` == 1
    - `grep -c "RawSnelstartRequest" app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php` >= 2
    - `grep -c "UpstreamErrorMapper::mapException" app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php` == 1
    - `grep -c "HeaderForwarder::forward" app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php` == 1
    - `grep -c "PassThroughCall::create" app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php` == 1
    - `grep -ciE "(method_not_allowed|insufficient_ability)" app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php` == 2
    - `grep -c "Route::any.*snelstart.*path" routes/api.php` == 1
    - `grep -c "resolve.snelstart.account" routes/api.php` == 1
    - `php artisan route:list --path=v1 --except-vendor` toont route met `api.snelstart.passthrough`
    - `php -l app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php routes/api.php` exit 0
    - **Geen** wijziging onder `packages/snelstart-api/**` (`git diff --stat packages/snelstart-api/ | wc -l` == 0)
  </acceptance_criteria>
  <done>Controller, route, en alle integraties (mapper, forwarder, audit-write) staan. `php artisan route:list` toont de catch-all met `Route::any` op `/v1/snelstart/{path}`.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Feature-tests resolution + echo/ping + OData + error-mapping + audit + header-forwarding + SanctumAbility-completion</name>
  <files>
    tests/Feature/Api/V1/Snelstart/PassThroughResolutionTest.php,
    tests/Feature/Api/V1/Snelstart/PassThroughEchoPingTest.php,
    tests/Feature/Api/V1/Snelstart/PassThroughOdataRelatiesTest.php,
    tests/Feature/Api/V1/Snelstart/PassThroughErrorMappingTest.php,
    tests/Feature/Api/V1/Snelstart/PassThroughAuditNoSecretsTest.php,
    tests/Feature/Api/V1/Snelstart/HeaderForwardingTest.php,
    tests/Feature/Api/SanctumAbilityTest.php
  </files>
  <behavior>
    Alle 6 nieuwe feature-tests onder namespace `Tests\Feature\Api\V1\Snelstart`. Authenticatie via Bearer-PAT (zelfde pattern als Plan 04). Snelstart-SDK gefaked via Saloon's `MockClient::global(...)` zodat geen netwerk-call de test verlaat.

    **Test-baseline-helper** (mag inline in `setUp()` van elke test of in een trait `Tests\Concerns\WithFakedSnelstart`):
    ```php
    use Saloon\Http\Faking\MockClient;
    use Saloon\Http\Faking\MockResponse;
    use Emeq\SnelstartApi\Http\Request\RawSnelstartRequest;

    protected function setUp(): void
    {
        parent::setUp();
        MockClient::destroyGlobal(); // schoon starten
    }
    ```

    **`PassThroughResolutionTest`** (~7 cases — focus op middleware-flow, geen SDK-mock nodig omdat de middleware faalt vóór de SDK-call):
    1. `test_missing_x_account_id_header_returns_400_with_missing_account_header` — GET `/v1/snelstart/echo/ping` zonder header → 400, `error === 'missing_account_header'`
    2. `test_unknown_x_account_id_returns_404_with_account_not_found` — Consumer heeft geen Account; header met willekeurige string → 404, `error === 'account_not_found'`
    3. `test_other_consumers_account_id_returns_404_not_403` — Consumer A heeft Account met external_id `school-A`; Consumer B's PAT + header `X-Account-Id: school-A` → 404, `error === 'account_not_found'` (info-disclosure-policy)
    4. `test_account_without_active_snelstart_connection_returns_404_with_connection_not_found` — Account bestaat, Connection bestaat maar `revoked_at` is gezet → 404, `error === 'connection_not_found'`
    5. `test_account_with_only_mollie_connection_returns_404_with_connection_not_found` — Account heeft alleen een `forMollie()`-Connection → 404
    6. `test_options_method_returns_405_with_method_not_allowed` — bouw `Account` + `Connection`; OPTIONS `/v1/snelstart/echo/ping` met `X-Account-Id`; assert 405, `error === 'method_not_allowed'`
    7. `test_unauthenticated_request_returns_401` — geen Bearer → 401 (Sanctum-default; geen JSON-envelope-eis)

    **`PassThroughEchoPingTest`** — bewijst HUB-05 SC-3 (~3 cases):
    1. `test_get_echo_ping_proxies_through_sdk_with_credential_resolver_binding_and_returns_200` —
       - Maak Consumer + Account + `forSnelstart()`-Connection + PAT met `snelstart:read`
       - Mock global: `MockClient::global([RawSnelstartRequest::class => MockResponse::make(['pong'=>'ok','echoed'=>'ping'], 200)])`
       - GET `/v1/snelstart/echo/ping` met `X-Account-Id: <account.external_id>`
       - Assert 200, response-body bevat `pong` of `echoed`
       - Assert dat er één `PassThroughCall`-rij geschreven is met `method='GET'`, `path` start met `/echo/ping`, `status=200`, `request_fingerprint IS NULL` (GET heeft geen body)
    2. `test_credential_resolver_was_bound_to_the_right_connections_credentials_during_call` —
       - Setup: 2 Connections (van 2 Accounts, beide bij dezelfde Consumer) met verschillende `client_key` waarden
       - Roep `/v1/snelstart/echo/ping` aan met `X-Account-Id` van Account A; assert via `app(\Emeq\SnelstartApi\Contracts\SnelstartCredentialResolver::class)->resolve()->clientKey === <client_key_A>` is niet meer mogelijk na request (binding is per-request); maar de SDK-side van de mock kan zien welke `headers` waren ingesteld — fallback: mock met callable die de gebruikte authenticator-state capture't via een spy variable
       - **Pragmatisch**: gebruik `MockClient::global([RawSnelstartRequest::class => function (\Saloon\Http\PendingRequest $pr) { /* assert hier dat de pending request via de juiste authenticator is gegaan */ return MockResponse::make([], 200); }])`. Of: assert dat de `PassThroughCall.connection_id` overeenkomt met Account A's Connection-id.
    3. `test_token_with_only_mollie_read_ability_returns_403_on_snelstart_get` — assert 403 `error === 'insufficient_ability'`

    **`PassThroughOdataRelatiesTest`** — bewijst HUB-05 SC-4 (~3 cases):
    1. `test_get_relaties_with_top_5_query_string_is_proxied_verbatim_to_sdk` —
       - Mock: `MockClient::global([RawSnelstartRequest::class => function (\Saloon\Http\PendingRequest $pr) { test()->assertSame('5', $pr->query()->get('$top')); return MockResponse::make(['value'=>[/* fake odata rows */]], 200); }])` — of in een PHPUnit-context: gebruik `function (\Saloon\Http\PendingRequest $pr) use (&$capturedQuery) { $capturedQuery = $pr->query()->all(); ... }` en assert daarna
       - GET `/v1/snelstart/relaties?$top=5` met `X-Account-Id`
       - Assert 200, response bevat de fake odata-rows
       - Assert audit-rij heeft `path === '/relaties?%24top=5'` of `/relaties?$top=5` (controleer wat Laravel's `getQueryString()` precies returnt voor URL-encoded `$`)
    2. `test_complex_odata_query_with_filter_and_select_is_proxied` — query `?$filter=Email eq 'a@b.nl'&$select=Id,Naam&$top=10`; assert pending-request bevat alle drie query-params
    3. `test_response_content_type_is_passthrough` — Snelstart returnt `Content-Type: application/json`; Hub-response heeft idem; mock een upstream `application/xml` (atom-OData) en assert dat de Hub-response 'm 1:1 doorzet

    **`PassThroughErrorMappingTest`** — bewijst HUB-05 SC-7 short-codes + correct status (~6 cases):
    1. `test_snelstart_401_maps_to_502_with_snelstart_auth_short_code` — Mock returns 401 → Hub returns 502, `error === 'upstream_error'`, audit-rij heeft `upstream_error === 'snelstart_auth'`
    2. `test_snelstart_503_maps_to_502_with_snelstart_5xx_short_code`
    3. `test_snelstart_400_passes_through_as_400_with_upstream_validation` — Snelstart body `{"errorCode":"ALG-0100"}` → Hub returns 400, body bevat `upstream_validation` of `error_codes:['ALG-0100']`; audit `upstream_error IS NULL`
    4. `test_snelstart_404_passes_through_as_404`
    5. `test_snelstart_429_passes_through_with_retry_after_header` — Mock returns 429 + `Retry-After: 30`; Hub-response is 429 + `Retry-After: 30`-header; audit `upstream_error IS NULL`
    6. `test_network_timeout_maps_to_504_with_snelstart_timeout_short_code` — gebruik `MockClient::global([RawSnelstartRequest::class => fn () => throw new \Saloon\Exceptions\Request\FatalRequestException(/* minimal pending stub */, 'timeout')])` — of vergelijkbaar; Hub returns 504; audit `upstream_error === 'snelstart_timeout'`

    **`PassThroughAuditNoSecretsTest`** — bewijst HUB-05 SC-7 *raw credentials nergens* (~3 cases):
    1. `test_audit_row_after_successful_passthrough_contains_no_raw_client_key` —
       - Setup met een plain `client_key = 'CK-test-rawkey-DO-NOT-LEAK'` via `Connection::factory()->forSnelstart()->create(['client_key'=>'CK-test-rawkey-DO-NOT-LEAK'])`
       - Maak een pass-through-call; haal alle string-kolommen op:
         ```php
         $row = \DB::table('pass_through_calls')->latest('id')->first();
         foreach ((array) $row as $col => $val) {
             if (is_string($val)) {
                 $this->assertStringNotContainsString('CK-test-rawkey-DO-NOT-LEAK', $val, "Column {$col} leakt clientKey");
             }
         }
         ```
    2. `test_audit_row_does_not_contain_subscription_key`
    3. `test_audit_row_does_not_contain_request_body_for_post` — POST `/v1/snelstart/relaties` met body `{"naam":"SECRET-FROM-BODY"}`; mock 201; assert audit-row geen `SECRET-FROM-BODY` (alleen fingerprint)

    **`HeaderForwardingTest`** — bewijst T-05b-09 mitigation (~3 cases):
    1. `test_authorization_header_is_stripped_before_sdk_call` —
       - Mock met callable die `$pr->headers()->all()` capture't; doe call met `Authorization: Bearer xyz` + `X-Account-Id`
       - Assert dat captured headers GEEN `Authorization`-key bevatten (alleen de 4 whitelisted + de SDK's eigen auth-headers)
    2. `test_x_account_id_header_is_stripped_before_sdk_call`
    3. `test_user_agent_and_cookie_are_stripped_before_sdk_call`

    **`SanctumAbilityTest`-completion** — open de bestaande file `tests/Feature/Api/SanctumAbilityTest.php` en vervang de `markTestIncomplete`-implementation van `test_token_without_required_ability_is_rejected` met een passing test tegen `/v1/snelstart/echo/ping`:

    ```php
    public function test_token_without_required_ability_is_rejected(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        Connection::factory()->forSnelstart()->for($account)->create();

        $token = $consumer->createToken('mollie-only', [TokenAbilities::MOLLIE_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/snelstart/echo/ping')
            ->assertStatus(403)
            ->assertJsonPath('error', 'insufficient_ability');
    }
    ```

    Imports toevoegen voor `Account` en `Connection`. Plaats deze update als laatste task-stap voor sanity (Phase 3 placeholder daadwerkelijk closed).

    Run alle nieuwe + bestaande tests:
    ```
    vendor/bin/pint --dirty --format agent
    php artisan test --compact --filter='PassThrough|SanctumAbility|HeaderForwarding'
    php artisan test --compact   # volledige suite
    ```
  </behavior>
  <read_first>
    - tests/Feature/Api/SanctumAbilityTest.php (huidige placeholder `markTestIncomplete` op regel 47)
    - tests/Feature/Api/V1/StoreConnectionTest.php (Plan 04 — pattern voor encryption-at-rest grep-assertion)
    - packages/snelstart-api/tests/Unit/Auth/ClientKeyAuthenticatorTest.php (pattern voor `MockClient::global` met spy-callable)
    - vendor/saloonphp/saloon/src/Http/Faking/MockClient.php (`::global()`, `::destroyGlobal()`)
    - vendor/saloonphp/saloon/src/Http/Faking/MockResponse.php (`::make(array $body, int $status, array $headers)`)
    - vendor/saloonphp/saloon/src/Exceptions/Request/FatalRequestException.php (constructor — voor de timeout-test)
    - app/Http/Middleware/ResolveSnelstartAccount.php (Task 1 output)
    - app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php (Task 2 output)
    - database/factories/ConnectionFactory.php (`forSnelstart()` + `forMollie()`-states)
  </read_first>
  <action>
    Genereer test-bestanden:
    ```
    php artisan make:test --phpunit Api/V1/Snelstart/PassThroughResolutionTest --no-interaction
    php artisan make:test --phpunit Api/V1/Snelstart/PassThroughEchoPingTest --no-interaction
    php artisan make:test --phpunit Api/V1/Snelstart/PassThroughOdataRelatiesTest --no-interaction
    php artisan make:test --phpunit Api/V1/Snelstart/PassThroughErrorMappingTest --no-interaction
    php artisan make:test --phpunit Api/V1/Snelstart/PassThroughAuditNoSecretsTest --no-interaction
    php artisan make:test --phpunit Api/V1/Snelstart/HeaderForwardingTest --no-interaction
    ```

    Schrijf alle 25+ test-cases per de behavior-sectie hierboven. Voor de SDK-spy-pattern (Saloon `MockClient::global` met callable die de pending-request capture't), gebruik:

    ```php
    $captured = null;
    MockClient::global([
        RawSnelstartRequest::class => function (\Saloon\Http\PendingRequest $pendingRequest) use (&$captured) {
            $captured = [
                'url'     => $pendingRequest->getUrl(),
                'query'   => $pendingRequest->query()->all(),
                'headers' => $pendingRequest->headers()->all(),
                'method'  => $pendingRequest->getMethod()->value,
            ];

            return MockResponse::make(['ok' => true], 200);
        },
    ]);
    ```

    Voor de `FatalRequestException`-pad: als de constructor lastig is, gooi de exception in de mock-callable in plaats van een MockResponse — Saloon zal 'm doorlaten:
    ```php
    MockClient::global([
        RawSnelstartRequest::class => fn () => throw new \Saloon\Exceptions\Request\FatalRequestException(
            new \Exception('Connection timed out'),
            $this->createMock(\Saloon\Http\PendingRequest::class)
        ),
    ]);
    ```

    Open `tests/Feature/Api/SanctumAbilityTest.php` en vervang het bestaande `markTestIncomplete`-block met de passing 403-test (zie behavior-sectie). Import `App\Models\Account` en `App\Models\Connection`. Verwijder de comment-regels die naar Phase 5b verwijzen — die placeholder is nu gesloten.

    Pint + tests:
    ```
    vendor/bin/pint --dirty --format agent
    php artisan test --compact
    ```
  </action>
  <verify>
    <automated>php artisan test --compact --filter='PassThrough|SanctumAbility|HeaderForwarding'</automated>
  </verify>
  <acceptance_criteria>
    - `grep -cE "public function test_" tests/Feature/Api/V1/Snelstart/PassThroughResolutionTest.php` >= 7
    - `grep -cE "public function test_" tests/Feature/Api/V1/Snelstart/PassThroughEchoPingTest.php` >= 3
    - `grep -cE "public function test_" tests/Feature/Api/V1/Snelstart/PassThroughOdataRelatiesTest.php` >= 3
    - `grep -cE "public function test_" tests/Feature/Api/V1/Snelstart/PassThroughErrorMappingTest.php` >= 6
    - `grep -cE "public function test_" tests/Feature/Api/V1/Snelstart/PassThroughAuditNoSecretsTest.php` >= 3
    - `grep -cE "public function test_" tests/Feature/Api/V1/Snelstart/HeaderForwardingTest.php` >= 3
    - **Totaal nieuw in deze task: >= 25 test-cases**
    - `grep -c "markTestIncomplete" tests/Feature/Api/SanctumAbilityTest.php` == 0 (placeholder gesloten)
    - `grep -c "insufficient_ability" tests/Feature/Api/SanctumAbilityTest.php` >= 1
    - `php artisan test --compact --filter='PassThrough|SanctumAbility|HeaderForwarding'` exit 0
    - Volledige suite: `php artisan test --compact` exit 0 (geen regressies Phase 3 + Plan 04 tests)
    - `grep -v '^//' tests/Feature/Api/V1/Snelstart/PassThroughAuditNoSecretsTest.php | grep -c "CK-test-rawkey-DO-NOT-LEAK"` >= 1 (raw key-fixture wordt expliciet getest tegen audit-leaks)
  </acceptance_criteria>
  <done>25+ tests groen. Alle HUB-05 success criteria 3-7 bewezen door tests. Phase 3-placeholder `SanctumAbilityTest::test_token_without_required_ability_is_rejected` is een passing test (geen `markTestIncomplete` meer).</done>
</task>

<task type="auto" tdd="true">
  <name>Task 4: Scramble route-discovery test (HUB-05 SC-8)</name>
  <files>tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php</files>
  <behavior>
    Scramble (`dedoc/scramble`) is in deze branch al gepubliceerd (zie STATE.md "Quick Tasks"). Het exposeert `/docs/api`-UI + `/docs/api.json` als OpenAPI-spec. Dit test bewijst HUB-05 SC-8: alle nieuwe routes verschijnen.

    Cases (~4):
    1. `test_openapi_spec_contains_post_v1_accounts_route` — `getJson('/docs/api.json?token='.config('scramble.access_token'))` (Scramble zit achter een token-gate uit AppServiceProvider, zie regel 24-32). Assert response 200 + JSON-path `paths./v1/accounts.post` exists
    2. `test_openapi_spec_contains_post_v1_connections_route` — JSON-path `paths./v1/connections.post`
    3. `test_openapi_spec_contains_show_and_delete_v1_connections_id_routes` — `paths./v1/connections/{connection}.get` en `.delete`
    4. `test_openapi_spec_contains_snelstart_passthrough_catchall` — `paths./v1/snelstart/{path}` exists; assert dat **minstens één** HTTP-method onder dit path beschikbaar is. Catch-all `Route::any` met `where('path','.*')` rendert in Scramble typisch als één operation per method — accepteer dat ze er allemaal als aparte verbs onder hetzelfde path-template zijn (GET/POST/PATCH/DELETE).

    Als de Scramble-spec de `auth:sanctum`-routes alleen toont voor authenticated requests (Scramble heeft een `withDocumentTransformers` die `bearer`-security toevoegt — zie AppServiceProvider), dan moet de test wellicht een geldige token meegeven. Default-gedrag: Scramble's OpenAPI-spec is publiek (achter `Gate::viewApiDocs` met `?token=` query) — gebruik `config('scramble.access_token')`.

    Als `config('scramble.access_token')` in test-omgeving niet gezet is, set 'm in `setUp()`:
    ```php
    config(['scramble.access_token' => 'test-scramble-token']);
    ```

    Edge case voor CONTEXT.md `<specifics>` flag (*"Validate that catch-all renders as one OpenAPI operation"*): als blijkt dat Scramble géén route-info voor `Route::any` met `where('path','.*')` genereert (sommige OpenAPI-generators slaan catch-alls over), documenteer dit als een gevonden gap in de plan-summary en de execute-sessie kan een follow-up `BLOCKER` opperen (bv. expliciete per-resource-routes naast de catch-all). Voorlopig: schrijf de test optimistisch en zie wat 'ie zegt — markTestIncomplete als de spec leeg blijkt, met een duidelijke message.
  </behavior>
  <read_first>
    - app/Providers/AppServiceProvider.php (Scramble-config: `viewApiDocs`-gate + `Scramble::configure()->withDocumentTransformers`)
    - .planning/STATE.md (regel 89: Scramble is op deze branch gepubliceerd + check `vendor/dedoc/scramble/routes.php` voor de exact JSON-endpoint URL)
    - routes/api.php (Plan 04 + Task 2 output — alle routes die in spec moeten verschijnen)
  </read_first>
  <action>
    `php artisan make:test --phpunit Documentation/ScrambleRouteDiscoveryTest --no-interaction`

    Schrijf de 4 cases. Namespace `Tests\Feature\Documentation`. Geen `RefreshDatabase` vereist (Scramble draait op route-registratie, niet DB-state).

    Verifieer eerst handmatig welke URL Scramble exposeert: `php artisan route:list --path=docs` toont de Scramble-routes. Verwacht is `/docs/api.json` of `/docs/api/{group}.json` of `/docs/api`. Pas test-pad daaraan aan.

    Run pint + test:
    ```
    vendor/bin/pint --dirty --format agent
    php artisan test --compact --filter=ScrambleRouteDiscoveryTest
    ```

    Als de test faalt door Scramble-quirks (catch-all niet gerenderd, of token-gate strikt), schrijf een ADR `scramble-passthrough-route-discovery.md` (optioneel — alleen bij gap) en markeer de specifieke case als `markTestSkipped` met een referentie naar de ADR. Plan-execute mag deze beslissing nemen, maar **niet stilzwijgend de hele test schrappen** — Plan-acceptance vereist dat dit bestand bestaat met >=3 actieve cases of een geldige skip-ADR.
  </action>
  <verify>
    <automated>php artisan test --compact --filter=ScrambleRouteDiscoveryTest</automated>
  </verify>
  <acceptance_criteria>
    - `test -f tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php`
    - `grep -cE "public function test_" tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php` >= 3 (een case mag legitiem `markTestSkipped` zijn voor de catch-all met expliciete ADR-referentie, mits >= 3 cases active blijven of >=3 cases bestaan)
    - `grep -cE "(paths|openapi|/docs/api|v1/accounts|v1/connections|v1/snelstart)" tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php` >= 4
    - `php artisan test --compact --filter=ScrambleRouteDiscoveryTest` exit 0
  </acceptance_criteria>
  <done>Test bewijst dat Scramble alle nieuwe v1-routes ziet (HUB-05 SC-8). Catch-all-case mag een gedocumenteerde `markTestSkipped` zijn wanneer Scramble het pattern niet rendert — niet stilzwijgend.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Consumer-request → Hub-controller (X-Account-Id auth-bound) | Cross-Consumer-account-id → 404 zonder existence-disclosure (T-05b-13 + uitbreiding voor pass-through) |
| Hub-controller → SDK-request (headers + body) | Whitelist-headers + geen credential-injection mogelijk |
| SDK-response → Hub-response | Upstream-error-rewrap (502 voor auth/5xx); audit-row capture zonder body-snapshot |
| SDK-exception → Audit-tabel (`upstream_error`) | Alleen short-code; geen exception-message of stack-trace in DB |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05b-18 | Spoofing | Cross-Consumer pass-through via `X-Account-Id` | mitigate | `ResolveSnelstartAccount` scoped op `consumer_id` van de geauthenticeerde Consumer; 404 bij mismatch (Task 1). Bewezen door PassThroughResolutionTest cases 2+3. |
| T-05b-19 | Tampering | Consumer injecteert path-traversal `../../auth/token` | mitigate | `Route::any('/snelstart/{path}')->where('path','.*')` accepteert het, maar de SDK plakt 'm op de Snelstart-base-URL (`https://b2bapi.snelstart.nl/v2`) — een `../../`-prefix wordt door Snelstart's gateway als 404 of 400 geretourneerd; Hub mapt naar passthrough. Geen lokale state wordt geraakt (geen file-IO). Edge case: een Consumer kan Snelstart's eigen auth-token-endpoint `/v2/oauth/token` proberen aan te roepen — gaat al onder de SDK-authenticatie-laag waardoor de uitgaande request mét Bearer-token de auth-server hit; Snelstart's auth-server retourneert dan een 400/401 → Hub remapt naar 502. Acceptable. |
| T-05b-20 | Information disclosure | Audit-rij bevat `request_fingerprint` reverseerbaar | accept | `sha256[0..12]` is 48-bit, voor een gericht body als `{"naam":"X"}` rainbow-tabel mogelijk maar low-value (Consumer's eigen body, geen secret). Voor veel-volume systemen: bump naar 24 of 32 chars. Out-of-scope. |
| T-05b-21 | Information disclosure | `pass_through_calls.path` bevat OData filter met PII (bv. Email-adres) | mitigate | Phase 9 admin-UI maskeert `path` of biedt een filter. Voor 5b: accept — Consumer is bewust van zijn eigen query, en raw credentials zijn de hoofdzorg. ADR `pass-through-calls-table.md` noemt dit als toekomstige retention-concern. |
| T-05b-22 | Repudiation | Audit-rij niet geschreven bij async/queue-error | accept | Audit-write is synchroon (CONTEXT.md `### Audit-timing`). Bij DB-uitval lekt een pass-through-call zonder audit-rij — DB-uitval is een breder incident, niet specifiek voor 5b. Geen async-queue dus geen verlies-risico vanaf nu. |
| T-05b-23 | Elevation of privilege | OPTIONS/HEAD/TRACE-method-trick | mitigate | Controller filtert in regel 1 op `['GET','POST','PATCH','DELETE']` whitelist; OPTIONS/HEAD/TRACE → 405. Test 6 in `PassThroughResolutionTest`. |
| T-05b-24 | Information disclosure | Scramble `/docs/api` exposed publiek | accept | Scramble heeft een `viewApiDocs`-Gate met `?token=`-query (AppServiceProvider). In productie: scramble.access_token uit env-var. Niet 5b-specifiek. |
</threat_model>

<verification>
- Wave 3 vereist: Wave 1 + 2 zijn geland en getest groen
- Alle 6 nieuwe Snelstart-feature-test-files groen (>= 25 test-cases)
- Scramble-route-discovery-test groen (>= 3 active cases)
- `SanctumAbilityTest::test_token_without_required_ability_is_rejected` is passing (geen `markTestIncomplete`)
- Volledige `php artisan test --compact` blijft groen
- `php artisan route:list --path=v1` toont 6 v1-routes (`ping`, 4 provisioning, 1 catch-all)
- `git diff --stat packages/snelstart-api/ | wc -l` == 0 (SDK-grens-invariant)
- Pint clean
</verification>

<success_criteria>
**Alle 8 HUB-05 success criteria afgedekt door deze Phase**:
- SC-1 ✅ (Plan 04 StoreAccountTest)
- SC-2 ✅ (Plan 04 StoreConnectionTest)
- SC-3 ✅ (Plan 05 PassThroughEchoPingTest)
- SC-4 ✅ (Plan 05 PassThroughOdataRelatiesTest)
- SC-5 ✅ (Plan 04 cross-Consumer tests + Plan 05 PassThroughResolutionTest case 3)
- SC-6 ✅ (Plan 05 PassThroughResolutionTest cases 1-4)
- SC-7 ✅ (Plan 05 PassThroughAuditNoSecretsTest + ErrorMappingTest)
- SC-8 ✅ (Plan 05 ScrambleRouteDiscoveryTest)

Phase 5b is daarmee klaar voor HUB-05 → "Validated" verplaatsing in PROJECT.md tijdens `/gsd-transition`.
</success_criteria>

<output>
Na completion: `.planning/phases/05b-snelstart-pass-through-api/05b-05-SUMMARY.md` per template, met expliciete vermelding van:
- HUB-05 SC-1 t/m SC-8 status (all green)
- Aantal tests toegevoegd in deze fase (Plans 01-05 totaal — orde van grootte 50-60 nieuwe tests)
- `SanctumAbilityTest`-placeholder afgesloten
- Eventuele Scramble-catch-all-quirks ontdekt tijdens execute → opnemen in STATE.md "Blockers/Concerns" voor Phase 5a-planning
- Aanbeveling voor `/gsd-transition`: HUB-05 naar Validated; REQUIREMENTS.md HUB-05-tekst bijwerken (`webhook_calls` → `pass_through_calls` met verwijzing naar ADR Plan 01)
- Trigger `docs-sync` skill als follow-up in execute-sessie (ADRs toegevoegd door Plans 01 + 03; STATE.md decisions-blok bijwerken voor v0.2-tracking)
</output>
