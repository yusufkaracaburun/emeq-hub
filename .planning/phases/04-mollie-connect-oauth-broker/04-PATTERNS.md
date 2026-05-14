# Phase 4: Mollie Connect OAuth-broker — Pattern Map

**Mapped:** 2026-05-14
**Files analyzed:** 18 new/modified files
**Analogs found:** 14 met sterke match / 18 totaal (4 hebben geen directe analog — zie "No Analog Found")

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `app/OAuth/Contracts/OAuthFlow.php` | contract (interface) | request-response | `packages/mollie-api/src/Contracts/MollieCredentialResolver.php` | role-match (Hub-laag i.p.v. SDK-laag) |
| `app/OAuth/Mollie/MollieConnectOAuthFlow.php` | implementation (service) | request-response (HTTP-out) | `vendor/emeq/snelstart-api/src/Auth/ClientKeyAuthenticator.php` | role-match (token-exchange service) |
| `app/OAuth/Testing/FakeOAuthFlow.php` | implementation (test-fixture) | request-response | `packages/mollie-api/src/Testing/FakeMollieCredentialResolver.php` | exact (test-fixture in runtime-namespace) |
| `app/OAuth/OAuthFlowRegistry.php` | utility (registry) | request-response | — (geen analog in repo) | geen analog |
| `app/Mollie/HubMollieCredentialResolver.php` | implementation (binding) | request-response | `packages/mollie-api/src/Testing/FakeMollieCredentialResolver.php` (interface-impl) | role-match |
| `app/Mollie/MollieConnectionContext.php` | service (per-request state) | request-response | — (geen analog in repo) | geen analog |
| `app/Http/Controllers/Api/V1/OAuth/InitController.php` | controller | request-response | `app/Http/Controllers/Api/V1/PingController.php` | exact (single-action `__invoke`) |
| `app/Http/Controllers/Api/V1/OAuth/CallbackController.php` | controller | request-response | `app/Http/Controllers/Api/V1/PingController.php` | exact (single-action `__invoke`) |
| `app/Console/Commands/PruneOAuthPendingConnections.php` | console-command | CLI batch | `app/Console/Commands/HubConsumerCreate.php` | exact (Command + signature + exit-codes) |
| `database/migrations/*_add_oauth_state_to_connections_table.php` | migration | DDL alter-table | `database/migrations/2026_05_14_151327_add_active_unique_to_connections.php` | exact (alter-only migration op `connections`) |
| `app/Models/Connection.php` (modify) | model | CRUD + encryption | `app/Models/Connection.php` (zichzelf) | exact (uitbreiding bestaande Fillable + casts) |
| `app/Sanctum/TokenAbilities.php` (modify, optioneel) | utility (constants) | — | `app/Sanctum/TokenAbilities.php` (zichzelf) | exact |
| `app/Providers/AppServiceProvider.php` (modify) | service-provider-binding | bootstrap | `app/Providers/AppServiceProvider.php` (zichzelf) | exact (Gate + RateLimiter pattern) |
| `routes/api.php` (modify) | route | request-response | `routes/api.php` (zichzelf, `/v1/ping` block) | exact |
| `config/services.php` (modify) | config | — | `config/services.php` (zichzelf, `slack` block) | exact |
| `database/factories/ConnectionFactory.php` (modify) | factory | test-data + states | `database/factories/ConnectionFactory.php` (zichzelf, `forSnelstart/forMollie`) | exact |
| `tests/Feature/Api/OAuth/InitTest.php` | test | request-response | `tests/Feature/Api/PingTest.php` | exact |
| `tests/Feature/Api/OAuth/CallbackTest.php` | test | request-response | `tests/Feature/Api/PingTest.php` | exact |
| `tests/Feature/OAuth/MollieConnectOAuthFlowTest.php` | test | HTTP-fake | `tests/Feature/ConnectionEncryptionTest.php` + `Http::fake()` | role-match |
| `tests/Feature/OAuth/OAuthFlowContractTest.php` | test | contract via FakeOAuthFlow | `tests/Feature/ConnectionEncryptionTest.php` (PHPUnit-feature) | role-match |
| `tests/Feature/Console/PruneOAuthPendingConnectionsTest.php` | test | CLI exit-code | `tests/Feature/Console/HubConsumerCreateTest.php` | exact |

## Pattern Assignments

### `app/OAuth/Contracts/OAuthFlow.php` (contract, interface)

**Analog:** `packages/mollie-api/src/Contracts/MollieCredentialResolver.php` — enige bestaande Contracts-interface in deze codebase. Hub-variant van hetzelfde pattern, maar in `App\OAuth\Contracts` (D-13: contract is Hub-laag, niet SDK-laag).

**Imports + interface-shape** (`packages/mollie-api/src/Contracts/MollieCredentialResolver.php:1-29`):
```php
<?php

declare(strict_types=1);

namespace Emeq\MollieApi\Contracts;

use Emeq\MollieApi\Data\MollieCredentials;

interface MollieCredentialResolver
{
    public function resolve(): MollieCredentials;
}
```

**Copy-pattern voor `OAuthFlow`:**
```php
<?php

declare(strict_types=1);

namespace App\OAuth\Contracts;

use App\Models\Account;
use App\Models\Connection;

interface OAuthFlow
{
    /**
     * Bouw de authorize-URL die de browser naar de partner stuurt.
     *
     * @param  list<string>  $scopes
     */
    public function getAuthorizationUrl(Account $account, array $scopes, string $state): string;

    /**
     * Ruil authorization-code in voor access/refresh tokens en schrijf
     * encrypted naar de Connection. Zet status='active' en oauth_state=null.
     */
    public function exchangeCode(Connection $connection, string $code): Connection;

    /**
     * Lazy-refresh de access_token bij naderende expiry. Idempotent —
     * mag meermaals aangeroepen worden binnen het refresh-window.
     */
    public function refreshToken(Connection $connection): Connection;

    /**
     * Trek de koppeling in bij de partner én zet status='revoked' lokaal.
     */
    public function revoke(Connection $connection): void;
}
```

**Landmines:**
- `declare(strict_types=1);` blijft consistent met SDK-contracts.
- Geen `OAuthFlow` in `packages/mollie-api/` — D-13 invariant.
- Method-returns: `getAuthorizationUrl` returnt `string`, exchange/refresh returnen de gemuteerde `Connection` (fluent), `revoke` is `void`.

---

### `app/OAuth/Mollie/MollieConnectOAuthFlow.php` (implementation, request-response HTTP-out)

**Analog:** `vendor/emeq/snelstart-api/src/Auth/ClientKeyAuthenticator.php` (Snelstart-SDK token-exchange-flow) + `packages/mollie-api/src/Webhooks/MollieWebhookSignature.php` (Mollie-domein, HMAC-pattern). D-15 zegt: Mollie's SDK heeft géén OAuth-helpers, dus directe `Http::post('https://api.mollie.com/oauth2/tokens', [...])` via Laravel's HTTP-facade.

**Concrete signature-shape:**
```php
<?php

declare(strict_types=1);

namespace App\OAuth\Mollie;

use App\Models\Account;
use App\Models\Connection;
use App\OAuth\Contracts\OAuthFlow;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;

final class MollieConnectOAuthFlow implements OAuthFlow
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
    ) {}

    public function getAuthorizationUrl(Account $account, array $scopes, string $state): string
    {
        return 'https://my.mollie.com/oauth2/authorize?'.http_build_query([
            'client_id' => $this->config->get('services.mollie.connect.client_id'),
            'redirect_uri' => $this->config->get('services.mollie.connect.redirect_uri'),
            'state' => $state,
            'scope' => implode(' ', $scopes),
            'response_type' => 'code',
            'approval_prompt' => 'auto',
        ]);
    }

    public function exchangeCode(Connection $connection, string $code): Connection
    {
        $response = $this->http->asForm()->post('https://api.mollie.com/oauth2/tokens', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->config->get('services.mollie.connect.redirect_uri'),
            'client_id' => $this->config->get('services.mollie.connect.client_id'),
            'client_secret' => $this->config->get('services.mollie.connect.client_secret'),
        ])->throw()->json();

        $connection->fill([
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'],
            'expires_at' => now()->addSeconds((int) $response['expires_in']),
            'scopes' => explode(' ', (string) ($response['scope'] ?? '')),
            'status' => 'active',
            'oauth_state' => null,
            'oauth_state_expires_at' => null,
        ])->save();

        return $connection;
    }

    public function refreshToken(Connection $connection): Connection
    {
        return Cache::lock("oauth:refresh:{$connection->id}", 30)->block(15, function () use ($connection) {
            $connection->refresh();

            if ($connection->expires_at && $connection->expires_at->gt(now()->addMinutes(5))) {
                return $connection; // andere request heeft net ge-refreshd
            }

            $response = $this->http->asForm()->post('https://api.mollie.com/oauth2/tokens', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $connection->refresh_token,
                'client_id' => $this->config->get('services.mollie.connect.client_id'),
                'client_secret' => $this->config->get('services.mollie.connect.client_secret'),
            ])->throw()->json();

            $connection->fill([
                'access_token' => $response['access_token'],
                'refresh_token' => $response['refresh_token'] ?? $connection->refresh_token,
                'expires_at' => now()->addSeconds((int) $response['expires_in']),
            ])->save();

            return $connection;
        });
    }

    public function revoke(Connection $connection): void
    {
        // Mollie: DELETE /oauth2/tokens met Basic-auth-header op client_id:client_secret
        $this->http->withBasicAuth(
            (string) $this->config->get('services.mollie.connect.client_id'),
            (string) $this->config->get('services.mollie.connect.client_secret'),
        )->delete('https://api.mollie.com/oauth2/tokens', [
            'token_type_hint' => 'access_token',
            'token' => $connection->access_token,
        ]);

        $connection->update(['status' => 'revoked', 'revoked_at' => now()]);
    }
}
```

**Landmines:**
- D-15 expliciet: geen Saloon, geen `mollie/mollie-api-php`-helpers — `Http::post()` direct. Authorize-URL host = `my.mollie.com` (browser-facing), token-endpoint host = `api.mollie.com`. Niet door elkaar halen.
- D-05: `Cache::lock("oauth:refresh:{$connectionId}", 30)`. `block(15, ...)` zorgt dat parallel-requests wachten i.p.v. dubbele refresh-roundtrips doen.
- D-06: na lock-acquire eerst `connection->refresh()` + check of expiry > 5 min — als andere request al refresh deed, return zonder HTTP-call.
- Tokens nooit in logs: `->throw()` is acceptabel, maar geen `Log::info($response)` met de body. Bij failure een `RuntimeException` met alleen `$response->status()` + correlatie-ID.
- `expires_in` uit Mollie is seconden vanaf nu; `now()->addSeconds($n)` produceert de juiste `expires_at`-timestamp voor de bestaande cast `'expires_at' => 'datetime'`.

---

### `app/OAuth/Testing/FakeOAuthFlow.php` (test-fixture, runtime-class in `app/`)

**Analog:** `packages/mollie-api/src/Testing/FakeMollieCredentialResolver.php` — bewezen pattern: een test-fixture die in `src/` (productie-autoload-tree) leeft i.p.v. in `tests/`. Reden: feature-tests in elke consumer-app moeten 'm kunnen binden via container, zonder PSR-4-truc.

**Imports + class-shape** (`packages/mollie-api/src/Testing/FakeMollieCredentialResolver.php:1-47, 99-106`):
```php
<?php

declare(strict_types=1);

namespace Emeq\MollieApi\Testing;

use Emeq\MollieApi\Contracts\MollieCredentialResolver;
use Emeq\MollieApi\Data\MollieCredentials;

final class FakeMollieCredentialResolver implements MollieCredentialResolver
{
    private array $sequence;
    private int $index = 0;

    public function __construct(MollieCredentials ...$credentials) { /* ... */ }

    public function resolve(): MollieCredentials
    {
        $credentials = $this->sequence[$this->index % count($this->sequence)];
        $this->index++;
        return $credentials;
    }
}
```

**Copy-pattern voor `FakeOAuthFlow` (D-12: teller `wasCalled()` + deterministic fake tokens):**
```php
<?php

declare(strict_types=1);

namespace App\OAuth\Testing;

use App\Models\Account;
use App\Models\Connection;
use App\OAuth\Contracts\OAuthFlow;

final class FakeOAuthFlow implements OAuthFlow
{
    /** @var array<string, int> */
    private array $callCounts = [
        'getAuthorizationUrl' => 0,
        'exchangeCode' => 0,
        'refreshToken' => 0,
        'revoke' => 0,
    ];

    public function getAuthorizationUrl(Account $account, array $scopes, string $state): string
    {
        $this->callCounts['getAuthorizationUrl']++;

        return 'https://fake.oauth.local/authorize?state='.$state;
    }

    public function exchangeCode(Connection $connection, string $code): Connection
    {
        $this->callCounts['exchangeCode']++;

        $nonce = bin2hex(random_bytes(8));

        $connection->fill([
            'access_token' => "access_test_fake_{$nonce}",
            'refresh_token' => "refresh_test_fake_{$nonce}",
            'expires_at' => now()->addHour(),
            'scopes' => ['payments.read', 'payments.write'],
            'status' => 'active',
            'oauth_state' => null,
            'oauth_state_expires_at' => null,
        ])->save();

        return $connection;
    }

    public function refreshToken(Connection $connection): Connection
    {
        $this->callCounts['refreshToken']++;

        $nonce = bin2hex(random_bytes(8));

        $connection->fill([
            'access_token' => "access_test_fake_{$nonce}",
            'expires_at' => now()->addHour(),
        ])->save();

        return $connection;
    }

    public function revoke(Connection $connection): void
    {
        $this->callCounts['revoke']++;

        $connection->update(['status' => 'revoked', 'revoked_at' => now()]);
    }

    public function wasCalled(string $method): int
    {
        return $this->callCounts[$method] ?? 0;
    }
}
```

**Landmines:**
- **D-12 expliciet: namespace is `App\OAuth\Testing\`, niet `Tests\…`.** Composer's `psr-4 App\\ => app/` pakt dit op; `tests/`-PSR-4 doet dat niet (en zou ook in production-autoload terechtkomen wat we niet willen voor real tests, maar voor de runtime-bind in feature-tests is `app/`-tree correct).
- Sanctum-`PersonalAccessToken` heeft géén `decode_at_rest`-tegenhanger nodig — fake-tokens zijn ook gewoon strings.
- Teller via array i.p.v. een `Counter`-class — past bij minimal-class-conventie van deze repo (zie `App\Sanctum\TokenAbilities` als constants-class, niet wrapper-object).

---

### `app/OAuth/OAuthFlowRegistry.php` (utility, provider-keyed lookup)

**Analog:** Geen directe analog. Pattern komt van Laravel's container-tag-systeem (`$this->app->tag([X::class], 'oauth.flow.mollie')`). Class-shape mirrort `App\Sanctum\TokenAbilities` (zelfde repo) voor "thin utility class".

**Copy-pattern:**
```php
<?php

declare(strict_types=1);

namespace App\OAuth;

use App\OAuth\Contracts\OAuthFlow;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

final class OAuthFlowRegistry
{
    /** @var array<string, class-string<OAuthFlow>> */
    private array $providers = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<OAuthFlow>  $implementation
     */
    public function register(string $provider, string $implementation): void
    {
        $this->providers[$provider] = $implementation;
    }

    public function for(string $provider): OAuthFlow
    {
        if (! isset($this->providers[$provider])) {
            throw new InvalidArgumentException(
                "Geen OAuthFlow geregistreerd voor provider '{$provider}'."
            );
        }

        return $this->container->make($this->providers[$provider]);
    }

    /** @return list<string> */
    public function providers(): array
    {
        return array_keys($this->providers);
    }
}
```

**Bind-pattern in `AppServiceProvider::register()`:**
```php
$this->app->singleton(OAuthFlowRegistry::class, function (Application $app) {
    $registry = new OAuthFlowRegistry($app);
    $registry->register('mollie', MollieConnectOAuthFlow::class);

    return $registry;
});
```

**Landmines:**
- D-14: registry geeft de planner een **drop-in punt** voor toekomstige Snelstart-OAuth / Exact-OAuth — `register('exact', ExactOAuthFlow::class)` is alles wat een v0.3-fase nodig heeft.
- Geen Laravel-`tagged()`-aanroep nodig (overkill voor één provider in v0.2) — explicit array-map is leesbaarder.

---

### `app/Mollie/HubMollieCredentialResolver.php` (binding voor SDK-contract)

**Analog:** `packages/mollie-api/src/Testing/FakeMollieCredentialResolver.php` — implementeert dezelfde `MollieCredentialResolver`-interface, maar deze versie haalt credentials uit een `Connection`-row.

**Imports + class-shape** (D-16: refresh-laag zit in deze resolver):
```php
<?php

declare(strict_types=1);

namespace App\Mollie;

use App\OAuth\OAuthFlowRegistry;
use Emeq\MollieApi\Contracts\MollieCredentialResolver;
use Emeq\MollieApi\Data\MollieCredentials;
use Emeq\MollieApi\Data\MollieOAuthCredentials;

final class HubMollieCredentialResolver implements MollieCredentialResolver
{
    public function __construct(
        private readonly MollieConnectionContext $context,
        private readonly OAuthFlowRegistry $registry,
    ) {}

    public function resolve(): MollieCredentials
    {
        $connection = $this->context->current();

        // Lazy refresh (D-04 + D-06): expires_at < now() + 5 min → refresh
        if ($connection->expires_at && $connection->expires_at->lt(now()->addMinutes(5))) {
            $connection = $this->registry->for('mollie')->refreshToken($connection);
        }

        return new MollieOAuthCredentials(
            accessToken: $connection->access_token,
            expiresAt: $connection->expires_at?->getTimestamp(),
        );
    }
}
```

**Bind-pattern in `AppServiceProvider::register()`:**
```php
$this->app->bind(
    MollieCredentialResolver::class,
    HubMollieCredentialResolver::class,
);
```

**Landmines:**
- D-16: deze resolver bindt al in Phase 4 (niet Phase 5a), zodat Phase 5a's pass-through-controllers `Mollie::client()` rechtstreeks kunnen aanroepen.
- `MollieOAuthCredentials` constructor valideert `access_`-prefix — fake-tokens in tests moeten `access_test_…` heten anders fails de constructor met `InvalidArgumentException` (zie `packages/mollie-api/src/Data/MollieOAuthCredentials.php:34-37`).
- Geen direct `Connection`-import in deze laag — alles via `MollieConnectionContext` om SDK-grens-doorbreking te vermijden (`.ai/rules/engineering.md` — geen Hub-domeinmodellen lekken in resolver-laag).

---

### `app/Mollie/MollieConnectionContext.php` (per-request current-Connection service)

**Analog:** Geen directe analog in repo. Past bij Laravel's "request-scoped singleton"-pattern. Vergelijkbaar met `Illuminate\Http\Request`-objects — één per request, gevuld door middleware/controller.

**Copy-pattern:**
```php
<?php

declare(strict_types=1);

namespace App\Mollie;

use App\Models\Connection;
use RuntimeException;

final class MollieConnectionContext
{
    private ?Connection $connection = null;

    public function set(Connection $connection): void
    {
        $this->connection = $connection;
    }

    public function current(): Connection
    {
        if ($this->connection === null) {
            throw new RuntimeException(
                'MollieConnectionContext: geen current Connection gezet. '
                .'Roep set() aan voordat HubMollieCredentialResolver wordt aangeroepen.'
            );
        }

        return $this->connection;
    }

    public function has(): bool
    {
        return $this->connection !== null;
    }
}
```

**Bind-pattern in `AppServiceProvider::register()`:**
```php
$this->app->scoped(MollieConnectionContext::class);
```

**Landmines:**
- `scoped()` (niet `singleton()`) — Laravel maakt 'm vers per request/job; voorkomt cross-request lekkage.
- Phase 5a vult `set()` in een controller (of middleware) bij start van pass-through-call. Phase 4 raakt dit niet — `MollieConnectionContext` heeft alleen `set()` + `current()` zodat tests deterministic kunnen wiren.
- Error-message in Nederlands per `.ai/rules/global.md` user-facing-conventie (al is dit eigenlijk een dev-error — nog steeds NL voor consistentie).

---

### `app/Http/Controllers/Api/V1/OAuth/InitController.php` (controller, request-response)

**Analog:** `app/Http/Controllers/Api/V1/PingController.php` — single-action `__invoke`, retourneert array (Laravel cast't naar JSON). Sanctum auth-pattern uit `routes/api.php`.

**Reference-shape** (`app/Http/Controllers/Api/V1/PingController.php:1-26`):
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Consumer;
use Illuminate\Http\Request;

class PingController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request): array
    {
        /** @var Consumer $consumer */
        $consumer = $request->user();

        return [
            'pong' => true,
            'consumer' => $consumer->slug,
            'abilities' => $consumer->currentAccessToken()?->abilities ?? [],
        ];
    }
}
```

**Copy-pattern voor `InitController` (D-01 + D-08):**
```php
<?php

namespace App\Http\Controllers\Api\V1\OAuth;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Consumer;
use App\OAuth\OAuthFlowRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InitController extends Controller
{
    public function __construct(private readonly OAuthFlowRegistry $registry) {}

    /**
     * @return array<string, string>
     */
    public function __invoke(Request $request): array
    {
        $validated = $request->validate([
            'account_external_id' => ['required', 'string'],
        ]);

        /** @var Consumer $consumer */
        $consumer = $request->user();

        $account = $consumer->accounts()
            ->where('external_id', $validated['account_external_id'])
            ->firstOrFail();

        $state = Str::random(48);

        $connection = Connection::create([
            'account_id' => $account->id,
            'provider' => 'mollie',
            'status' => 'pending',
            'oauth_state' => $state,
            'oauth_state_expires_at' => now()->addMinutes(30),
        ]);

        $scopes = config('services.mollie.connect.scopes');
        $redirectUrl = $this->registry->for('mollie')->getAuthorizationUrl($account, $scopes, $state);

        return [
            'connection_id' => (string) $connection->id,
            'redirect_url' => $redirectUrl,
        ];
    }
}
```

**Routing in `routes/api.php`:**
```php
Route::middleware(['auth:sanctum', 'ability:mollie:write'])->group(function (): void {
    Route::post('/oauth/mollie/init', \App\Http\Controllers\Api\V1\OAuth\InitController::class);
});
```

**Landmines:**
- D-07: ability is `mollie:write` (al gedefinieerd in `app/Sanctum/TokenAbilities.php:13`) — geen nieuwe ability nodig. Optioneel `mollie:connect` toevoegen als constants-naar-toekomstige-fase, maar niet vereist.
- `accounts()->where('external_id', …)->firstOrFail()` — gebruik bestaande Consumer-Account-scoping zoals bewezen in `tests/Feature/ConsumerAccountScopingTest.php:35-50`. Cross-Consumer-poging → `firstOrFail()` → 404 (geen info-disclosure).
- D-02: `oauth_state_expires_at = now()->addMinutes(30)`.
- D-08: response is JSON-array, geen `redirect()->away(...)` HTTP-redirect.
- Validatie via `$request->validate()` is voldoende voor één veld — geen FormRequest-class nodig in Phase 4.

---

### `app/Http/Controllers/Api/V1/OAuth/CallbackController.php` (controller, public route)

**Analog:** `app/Http/Controllers/Api/V1/PingController.php` voor shape; geen analog voor public-route-zonder-Bearer.

**Copy-pattern (D-03 + D-07: publiek, state-verified):**
```php
<?php

namespace App\Http\Controllers\Api\V1\OAuth;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\OAuth\OAuthFlowRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CallbackController extends Controller
{
    public function __construct(private readonly OAuthFlowRegistry $registry) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        $connection = Connection::query()
            ->where('provider', 'mollie')
            ->where('status', 'pending')
            ->where('oauth_state', $validated['state'])
            ->where('oauth_state_expires_at', '>', now())
            ->first();

        if ($connection === null) {
            return response()->json(
                ['error' => 'invalid_or_expired_state'],
                400,
            );
        }

        $this->registry->for('mollie')->exchangeCode($connection, $validated['code']);

        return response()->json([
            'connection_id' => (string) $connection->id,
            'status' => 'active',
        ]);
    }
}
```

**Routing in `routes/api.php`:**
```php
// Publiek — geen auth:sanctum. State-parameter is de auth (D-07).
Route::get('/oauth/mollie/callback', \App\Http\Controllers\Api\V1\OAuth\CallbackController::class);
```

**Landmines:**
- **CSRF/expired state → HTTP 400, niet 401/403.** ROADMAP SC-5 + CONTEXT.md `<specifics>`: 400 is "tampered/bad request"; 401 zou suggereren "log in".
- D-03 idempotency: tweede callback met dezelfde state vindt geen pending-row meer (status=active, oauth_state=null) en valt door naar 400.
- Geen Sanctum-middleware — browser landt hier zónder Bearer. State is de auth.
- Validatie-failure (missende `code` of `state`) geeft Laravel's default 422 — dat is OK, maar het is feitelijk een "bad request" zoals de state-mismatch. Beide paden zijn 4xx, geen 5xx.
- Mollie redirect-URI must match exact `services.mollie.connect.redirect_uri` — anders weigert Mollie de exchange-call.

---

### `app/Console/Commands/PruneOAuthPendingConnections.php` (artisan command)

**Analog:** `app/Console/Commands/HubConsumerCreate.php` — bestaande Command met `$signature` + `$description` (property-stijl, niet attributes — STATE.md decision 03-05), exit-codes via `self::SUCCESS/INVALID/FAILURE`, info/error-output in Nederlands.

**Imports + class-shape** (`app/Console/Commands/HubConsumerCreate.php:1-58`):
```php
<?php

namespace App\Console\Commands;

use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class HubConsumerCreate extends Command
{
    protected $signature = 'hub:consumer:create
                            {--slug= : Unieke slug (kebab-case identifier)}
                            {--name= : Vrije weergave-naam}
                            {--abilities=* : Comma-separated of meermaals (default: *)}
                            {--token-name=cli-default : Naam van het PAT-record}';

    protected $description = 'Maak een Consumer + Personal Access Token aan vanaf de CLI';

    public function handle(): int
    {
        // … validate options, do work, return self::SUCCESS / INVALID / FAILURE
    }
}
```

**Copy-pattern voor `PruneOAuthPendingConnections` (D-09):**
```php
<?php

namespace App\Console\Commands;

use App\Models\Connection;
use Illuminate\Console\Command;

class PruneOAuthPendingConnections extends Command
{
    protected $signature = 'oauth:prune-pending
                            {--dry-run : Toon welke rows verwijderd zouden worden zonder ze te raken}';

    protected $description = 'Ruim expired pending OAuth-Connections op (status=pending AND oauth_state_expires_at < now)';

    public function handle(): int
    {
        $query = Connection::query()
            ->where('status', 'pending')
            ->where('oauth_state_expires_at', '<', now());

        if ($this->option('dry-run')) {
            $count = $query->count();
            $this->info("Dry-run: {$count} pending Connection(s) zouden worden verwijderd.");

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info("Verwijderd: {$deleted} expired pending Connection(s).");

        return self::SUCCESS;
    }
}
```

**Landmines:**
- D-09: geen scheduler-registratie verplicht — command is "handmatig of via deploy-hook draaien". Niet in `routes/console.php` registreren tenzij user dat expliciet vraagt.
- Signature property-stijl, NIET `#[AsCommand]`-attribute — match `HubConsumerCreate`-conventie (STATE.md 2026-05-14 03-05).
- `$description` in Nederlands; option-names en option-descriptions ook Nederlands (user-facing CLI-output per `.ai/rules/global.md`).
- Delete (niet soft-delete) — Connection-model heeft geen `SoftDeletes`-trait; expired-pending-rows zijn pure orphans zonder business-value.

---

### `database/migrations/*_add_oauth_state_to_connections_table.php` (migration, alter-table)

**Analog:** `database/migrations/2026_05_14_151327_add_active_unique_to_connections.php` — bestaande alter-only migration op `connections`, anonymous-class-shape, `up()` + `down()`. Hier voegen we kolommen toe i.p.v. een unique-index.

**Reference-shape** (`database/migrations/2026_05_14_151327_add_active_unique_to_connections.php:1-20`):
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX connections_account_id_provider_active_unique '
            .'ON connections (account_id, provider) WHERE revoked_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS connections_account_id_provider_active_unique');
    }
};
```

**Copy-pattern voor OAuth-state columns:**
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->string('oauth_state', 64)->nullable()->after('scopes');
            $table->timestamp('oauth_state_expires_at')->nullable()->after('oauth_state');

            $table->index('oauth_state');
            $table->index(['status', 'oauth_state_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropIndex(['status', 'oauth_state_expires_at']);
            $table->dropIndex(['oauth_state']);
            $table->dropColumn(['oauth_state', 'oauth_state_expires_at']);
        });
    }
};
```

**Landmines:**
- **Forward-only invariant** (`.ai/project rules`): `down()` mag bestaan voor `migrate:fresh` in dev/test, maar produceer geen destructive logic in productie. Bovenstaande `down()` is OK voor dev.
- **Status-kolom bestaat al** in `2026_05_14_000003_create_connections_table.php:18` als `$table->string('status')->default('active')`. CONTEXT.md zegt "enum: pending/active/revoked" — geen schema-wijziging nodig (string-kolom accepteert die 3 waardes prima); enum-validatie gebeurt op model-laag. **Niet** een DROP-COLUMN+RECREATE doen.
- Timestamp-naming: `oauth_state_expires_at` matcht bestaande `expires_at` / `revoked_at` conventie.
- Index op `oauth_state` is kritiek voor de callback-lookup performance: `WHERE oauth_state = ?`.
- Composite index `(status, oauth_state_expires_at)` voor de prune-command query.
- Phase 4 deelt deze migration NIET met `2026_05_14_000003_create_connections_table.php` — apart bestand, forward-only invariant.

---

### `app/Models/Connection.php` (modify — fillable + casts uitbreiden)

**Analog:** zichzelf (`app/Models/Connection.php:1-65`). Voeg 2 velden toe aan `#[Fillable]` (status is er al), 1 cast aan `casts()`. **Status is al fillable** — niet dupliceren.

**Huidige fillable** (regels 12-25):
```php
#[Fillable([
    'account_id', 'provider', 'status',
    'access_token', 'refresh_token', 'expires_at', 'scopes',
    'client_key', 'subscription_key', 'subscription_id',
    'metadata', 'revoked_at',
])]
```

**Copy-pattern voor uitbreiding (D-01):**
```php
#[Fillable([
    'account_id', 'provider', 'status',
    'access_token', 'refresh_token', 'expires_at', 'scopes',
    'client_key', 'subscription_key', 'subscription_id',
    'metadata', 'revoked_at',
    'oauth_state', 'oauth_state_expires_at', // ← toevoegen
])]
```

**Casts uitbreiden** (regels 51-63, voeg toe vóór return):
```php
protected function casts(): array
{
    return [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'client_key' => 'encrypted',
        'subscription_key' => 'encrypted',
        'scopes' => 'array',
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'oauth_state_expires_at' => 'datetime', // ← toevoegen
    ];
}
```

**Landmines:**
- `oauth_state` blijft een rauw `string` — het is publiek (browser stuurt 'm terug), géén `encrypted` cast.
- `oauth_state` NIET aan `#[Hidden]` toevoegen — niet gevoelig na expiry, en geen credential.
- Geen `#[Hidden]`-wijziging — `access_token`/`refresh_token`/`client_key`/`subscription_key` blijven hidden (bestaande regel 26).
- **Chirurgisch wijzigen** (`.ai/rules/engineering.md`): raak niet de bestaande `fingerprint()`-methode, account-relation, of casts voor andere velden.

---

### `app/Sanctum/TokenAbilities.php` (modify — optioneel, OAuth-ability)

**Analog:** zichzelf (`app/Sanctum/TokenAbilities.php:1-33`). CONTEXT.md D-07 zegt: "POST /v1/oauth/mollie/init is Sanctum-protected ... + ability `mollie:write`". `mollie:write` bestaat al (regel 13) — **GEEN wijziging nodig**.

**Discretion:** als planner een dedicated `mollie:connect`-ability wenst voor finer-grained scope dan `mollie:write`, voeg toe:
```php
public const MOLLIE_CONNECT = 'mollie:connect';
```
En neem mee in `all()`-array. Default-aanbeveling: **niet** toevoegen — `mollie:write` is genoeg en houdt de surface minimaal.

---

### `app/Providers/AppServiceProvider.php` (modify — bindings)

**Analog:** zichzelf (`app/Providers/AppServiceProvider.php:1-46`). Huidige `boot()` definieert Scramble + RateLimiter; `register()` is leeg. Bindings horen in `register()`.

**Reference-shape** (`app/Providers/AppServiceProvider.php:17-44`):
```php
public function register(): void
{
    //
}

public function boot(): void
{
    Gate::define('viewApiDocs', function (?User $user): bool { /* ... */ });

    RateLimiter::for('api', function (Request $request): Limit { /* ... */ });

    Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi): void {
        $openApi->secure(SecurityScheme::http('bearer'));
    });
}
```

**Copy-pattern voor `register()` uitbreiden (D-14 + D-16):**
```php
public function register(): void
{
    $this->app->scoped(\App\Mollie\MollieConnectionContext::class);

    $this->app->singleton(\App\OAuth\OAuthFlowRegistry::class, function (\Illuminate\Contracts\Foundation\Application $app) {
        $registry = new \App\OAuth\OAuthFlowRegistry($app);
        $registry->register('mollie', \App\OAuth\Mollie\MollieConnectOAuthFlow::class);

        return $registry;
    });

    $this->app->bind(
        \Emeq\MollieApi\Contracts\MollieCredentialResolver::class,
        \App\Mollie\HubMollieCredentialResolver::class,
    );
}
```

**Landmines:**
- `register()` NOOIT facades aanroepen (Laravel-rule) — alleen container-bindings. Gate/RateLimiter/Scramble blijven in `boot()`.
- `scoped()` (niet `singleton()`) voor `MollieConnectionContext` — request-scoped, geen cross-request lekkage.
- Top-of-file imports volgen alfabetisch-namespaces-stijl van bestaande file; FQN-in-closure (zoals hierboven) is ook acceptabel voor één-puntige bindings.
- D-16: `MollieCredentialResolver` bindt al hier (niet in Phase 5a). Phase 5a's controllers kunnen direct `Mollie::client()` doen.

---

### `routes/api.php` (modify — twee OAuth-routes toevoegen)

**Analog:** zichzelf (`routes/api.php:1-17`). Huidige file heeft één auth:sanctum-group met `/ping`. Phase 4 voegt toe: één auth:sanctum+ability-protected route (`/oauth/mollie/init`) en één publieke route (`/oauth/mollie/callback`).

**Reference-shape** (`routes/api.php:1-17`):
```php
<?php

use App\Http\Controllers\Api\V1\PingController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/ping', PingController::class)->name('api.ping');
});
```

**Copy-pattern voor uitbreiding (D-07):**
```php
<?php

use App\Http\Controllers\Api\V1\OAuth\CallbackController;
use App\Http\Controllers\Api\V1\OAuth\InitController;
use App\Http\Controllers\Api\V1\PingController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/ping', PingController::class)->name('api.ping');

    Route::middleware('ability:mollie:write')->group(function (): void {
        Route::post('/oauth/mollie/init', InitController::class)
            ->name('api.oauth.mollie.init');
    });
});

// Publiek — state-parameter is de auth (D-07).
Route::get('/oauth/mollie/callback', CallbackController::class)
    ->name('api.oauth.mollie.callback');
```

**Landmines:**
- `apiPrefix: 'v1'` zit in `bootstrap/app.php:12` — daarom hier géén `/v1`-prefix in de paden zelf.
- `ability:mollie:write` is Sanctum's ability-middleware — werkt out-of-the-box dankzij Phase 3's Sanctum-config.
- Callback-route gaat BUITEN de `auth:sanctum`-group. Niet per ongeluk in de group plaatsen — anders eist Laravel een Bearer-header die de browser niet draagt.
- Route-naming `api.oauth.mollie.*` matcht bestaande `api.ping` dot-conventie.

---

### `config/services.php` (modify — Mollie Connect block)

**Analog:** zichzelf (`config/services.php:1-39`) — bestaande shape voor third-party-services config; we voegen een `mollie`-key toe naast de bestaande `postmark`/`resend`/`ses`/`slack`-blocks.

**Reference-shape** (`config/services.php:31-37`, slack-block met nested key):
```php
'slack' => [
    'notifications' => [
        'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
        'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
    ],
],
```

**Copy-pattern voor `mollie.connect`-block (D-10):**
```php
'mollie' => [
    'connect' => [
        'client_id' => env('MOLLIE_CONNECT_CLIENT_ID'),
        'client_secret' => env('MOLLIE_CONNECT_CLIENT_SECRET'),
        'redirect_uri' => env('MOLLIE_CONNECT_REDIRECT_URI'),
        'scopes' => [
            'payments.read',
            'payments.write',
            'customers.read',
            'customers.write',
            'subscriptions.read',
            'subscriptions.write',
            'mandates.read',
            'organizations.read',
            'onboarding.read',
        ],
    ],
],
```

**Landmines:**
- D-10: `scopes` hard-coded als array — geen env-var. Per-Consumer-differentiation is v1.0+.
- `.env.example` MOET de drie env-keys krijgen (`MOLLIE_CONNECT_CLIENT_ID=`, `MOLLIE_CONNECT_CLIENT_SECRET=`, `MOLLIE_CONNECT_REDIRECT_URI=https://hub.emeq.test:8090/v1/oauth/mollie/callback`). Niet vergeten in plan-acceptance.
- Géén `env()`-calls buiten `config/`-files (Laravel-rule wegens config-cache).

---

### `database/factories/ConnectionFactory.php` (modify — `pending()` + `active()` states)

**Analog:** zichzelf (`database/factories/ConnectionFactory.php:1-56`). Bestaande `forSnelstart()` + `forMollie()`-states tonen pattern; voeg `pending()` + `active()` toe voor Mollie-OAuth-lifecycle (D-01).

**Reference-shape** (`database/factories/ConnectionFactory.php:43-55`):
```php
public function forMollie(): static
{
    return $this->state(fn (array $attributes) => [
        'provider' => 'mollie',
        'access_token' => 'access_'.Str::random(40),
        'refresh_token' => 'refresh_'.Str::random(40),
        'expires_at' => now()->addHour(),
        'scopes' => ['payments.read', 'payments.write'],
        'client_key' => null,
        'subscription_key' => null,
        'subscription_id' => null,
    ]);
}
```

**Copy-pattern voor twee nieuwe states:**
```php
public function pending(): static
{
    return $this->state(fn (array $attributes) => [
        'provider' => 'mollie',
        'status' => 'pending',
        'oauth_state' => Str::random(48),
        'oauth_state_expires_at' => now()->addMinutes(30),
        'access_token' => null,
        'refresh_token' => null,
        'expires_at' => null,
        'scopes' => null,
        'client_key' => null,
        'subscription_key' => null,
        'subscription_id' => null,
    ]);
}

public function active(): static
{
    return $this->state(fn (array $attributes) => [
        'status' => 'active',
        'oauth_state' => null,
        'oauth_state_expires_at' => null,
    ]);
}

public function expired(): static
{
    return $this->state(fn (array $attributes) => [
        'oauth_state_expires_at' => now()->subMinute(),
    ]);
}
```

**Landmines:**
- `forMollie()->active()` is de "post-callback"-staat (kant-en-klare working connection). `pending()` is "post-init pre-callback".
- `expired()` is voor tests die `PruneOAuthPendingConnections` of de callback-state-check verifiëren.
- State-method returnt `static` — matcht bestaande `forSnelstart/forMollie` (regel 29, 43).
- Geen `prefixed-tokens` rule overtreden: `access_token` in `pending()` is `null` (token komt pas in callback).

---

### `tests/Feature/Api/OAuth/InitTest.php` (test, HTTP request-response)

**Analog:** `tests/Feature/Api/PingTest.php` — exact shape voor Sanctum-PAT-tests met `RefreshDatabase`. `tests/Feature/Api/SanctumAbilityTest.php` voor ability-gating-pattern.

**Reference-shape** (`tests/Feature/Api/PingTest.php:1-30`):
```php
<?php

namespace Tests\Feature\Api;

use App\Models\Consumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PingTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_consumer_receives_pong_payload(): void
    {
        $consumer = Consumer::factory()->create(['slug' => 'naschool', 'name' => 'Naschool']);
        $token = $consumer->createToken('test', ['snelstart:read'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/ping')
            ->assertOk()
            ->assertJson(['pong' => true, 'consumer' => 'naschool']);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/v1/ping')->assertUnauthorized();
    }
}
```

**Copy-pattern voor `InitTest`:**
```php
<?php

namespace Tests\Feature\Api\OAuth;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\OAuth\Contracts\OAuthFlow;
use App\OAuth\OAuthFlowRegistry;
use App\OAuth\Testing\FakeOAuthFlow;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Bind FakeOAuthFlow in plaats van de echte Mollie-implementatie.
        $this->app->bind(\App\OAuth\Mollie\MollieConnectOAuthFlow::class, FakeOAuthFlow::class);
    }

    public function test_init_creates_pending_connection_and_returns_redirect_url(): void
    {
        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create(['external_id' => 'school1', 'display_name' => 'School 1']);
        $token = $consumer->createToken('t', [TokenAbilities::MOLLIE_WRITE])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/oauth/mollie/init', ['account_external_id' => 'school1'])
            ->assertOk()
            ->assertJsonStructure(['connection_id', 'redirect_url']);

        $this->assertDatabaseHas('connections', [
            'account_id' => $account->id,
            'provider' => 'mollie',
            'status' => 'pending',
        ]);
    }

    public function test_init_without_ability_returns_403(): void
    {
        $consumer = Consumer::factory()->create();
        $consumer->accounts()->create(['external_id' => 'school1', 'display_name' => 'X']);
        $token = $consumer->createToken('t', [TokenAbilities::MOLLIE_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/oauth/mollie/init', ['account_external_id' => 'school1'])
            ->assertForbidden();
    }

    public function test_init_with_cross_consumer_account_returns_404(): void
    {
        $consumerA = Consumer::factory()->create();
        $consumerB = Consumer::factory()->create();
        $consumerB->accounts()->create(['external_id' => 'b-only', 'display_name' => 'B']);

        $tokenA = $consumerA->createToken('t', [TokenAbilities::MOLLIE_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson('/v1/oauth/mollie/init', ['account_external_id' => 'b-only'])
            ->assertNotFound();
    }
}
```

**Landmines:**
- Namespace `Tests\Feature\Api\OAuth` — sub-sub-namespace volgt PSR-4 (`tests/Feature/Api/OAuth/`).
- Test-naming `test_<scenario>_<expected>` snake_case — matcht `PingTest::test_authenticated_consumer_receives_pong_payload`.
- Cross-Consumer-test bewijst `firstOrFail()` → 404, geen 403 — matched ROADMAP/CONTEXT scoping-invariant.
- Geen Pest, géén `expect()` — PHPUnit (`$this->assert*`), per CLAUDE.md `phpunit/core` rules.

---

### `tests/Feature/Api/OAuth/CallbackTest.php` (test, public route)

**Analog:** zelfde als InitTest (`tests/Feature/Api/PingTest.php`). Hier géén Bearer-token — public route met state-parameter.

**Copy-pattern:**
```php
<?php

namespace Tests\Feature\Api\OAuth;

use App\Models\Connection;
use App\OAuth\Mollie\MollieConnectOAuthFlow;
use App\OAuth\Testing\FakeOAuthFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(MollieConnectOAuthFlow::class, FakeOAuthFlow::class);
    }

    public function test_callback_exchanges_code_when_state_matches(): void
    {
        $connection = Connection::factory()->forMollie()->pending()->create();
        $state = $connection->oauth_state;

        $this->getJson("/v1/oauth/mollie/callback?code=auth_code_xyz&state={$state}")
            ->assertOk()
            ->assertJson(['status' => 'active']);

        $connection->refresh();
        $this->assertSame('active', $connection->status);
        $this->assertNull($connection->oauth_state);
        $this->assertStringStartsWith('access_test_fake_', $connection->access_token);
    }

    public function test_callback_with_invalid_state_returns_400(): void
    {
        Connection::factory()->forMollie()->pending()->create();

        $this->getJson('/v1/oauth/mollie/callback?code=x&state=tampered_state')
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_or_expired_state']);
    }

    public function test_callback_with_expired_state_returns_400(): void
    {
        $connection = Connection::factory()->forMollie()->pending()->expired()->create();

        $this->getJson("/v1/oauth/mollie/callback?code=x&state={$connection->oauth_state}")
            ->assertStatus(400);
    }

    public function test_second_callback_with_same_state_returns_400(): void
    {
        $connection = Connection::factory()->forMollie()->pending()->create();
        $state = $connection->oauth_state;

        $this->getJson("/v1/oauth/mollie/callback?code=x&state={$state}")->assertOk();
        $this->getJson("/v1/oauth/mollie/callback?code=x&state={$state}")->assertStatus(400);
    }
}
```

**Landmines:**
- `assertStatus(400)` (niet `assertUnauthorized()` of `assertForbidden()`) voor state-mismatch — ROADMAP SC-5 expliciet.
- Idempotency-test (4e methode) bewijst D-03 — tweede callback met dezelfde state vindt geen pending row meer.
- `pending()->expired()` chain leunt op factory-states uit deze fase (zie ConnectionFactory-modify hierboven).

---

### `tests/Feature/OAuth/MollieConnectOAuthFlowTest.php` (test, HTTP-fake)

**Analog:** `tests/Feature/ConnectionEncryptionTest.php` voor class-shape; geen analog voor `Http::fake()` in deze repo (eerste use-case).

**Copy-pattern:**
```php
<?php

namespace Tests\Feature\OAuth;

use App\Models\Connection;
use App\OAuth\Mollie\MollieConnectOAuthFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MollieConnectOAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_exchange_code_writes_encrypted_tokens(): void
    {
        config(['services.mollie.connect.client_id' => 'app_test_id']);
        config(['services.mollie.connect.client_secret' => 'app_test_secret']);
        config(['services.mollie.connect.redirect_uri' => 'https://hub.test/v1/oauth/mollie/callback']);

        Http::fake([
            'api.mollie.com/oauth2/tokens' => Http::response([
                'access_token' => 'access_real_xyz',
                'refresh_token' => 'refresh_real_xyz',
                'expires_in' => 3600,
                'scope' => 'payments.read payments.write',
            ]),
        ]);

        $connection = Connection::factory()->forMollie()->pending()->create();

        $flow = $this->app->make(MollieConnectOAuthFlow::class);
        $flow->exchangeCode($connection, 'auth_code_abc');

        $connection->refresh();
        $this->assertSame('active', $connection->status);
        $this->assertSame('access_real_xyz', $connection->access_token);
        $this->assertContains('payments.write', $connection->scopes);
    }

    public function test_refresh_token_is_locked_per_connection(): void
    {
        // Lock-pattern uit D-05: tweede aanroep moet wachten op de eerste.
        // Test scope: één call doet HTTP; verify dat Cache::lock is gebruikt.
        $this->markTestIncomplete('Concurrent-refresh-race wordt getest in een aparte testcase met parallel-process simulatie.');
    }

    public function test_get_authorization_url_contains_required_query_params(): void
    {
        config(['services.mollie.connect.client_id' => 'app_test_id']);
        config(['services.mollie.connect.redirect_uri' => 'https://hub.test/v1/oauth/mollie/callback']);

        $flow = $this->app->make(MollieConnectOAuthFlow::class);
        $url = $flow->getAuthorizationUrl(
            \App\Models\Account::factory()->create(),
            ['payments.read'],
            'state_xyz',
        );

        $this->assertStringContainsString('client_id=app_test_id', $url);
        $this->assertStringContainsString('state=state_xyz', $url);
        $this->assertStringContainsString('scope=payments.read', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringStartsWith('https://my.mollie.com/oauth2/authorize?', $url);
    }
}
```

**Landmines:**
- `Http::fake()` is Laravel's HTTP-client mocking (niet PHPUnit-mock). Werkt out-of-the-box met `$this->http` factory-injection in `MollieConnectOAuthFlow`.
- `markTestIncomplete()` voor race-conditions — bestaande pattern (zie `tests/Feature/Api/SanctumAbilityTest.php:47`).
- Encrypted-check: na `exchangeCode` `$connection->access_token === 'access_real_xyz'` (Eloquent decrypt op access); voor at-rest-check zou je `DB::table('connections')->value('access_token')` doen — niet vereist voor deze test (al gedekt in `ConnectionEncryptionTest`).

---

### `tests/Feature/OAuth/OAuthFlowContractTest.php` (test, contract via FakeOAuthFlow — SC 4)

**Analog:** `tests/Feature/ConnectionEncryptionTest.php` voor class-shape + DB-asserts.

**Copy-pattern (bewijst ROADMAP SC-4: contract is niet Mollie-specifiek):**
```php
<?php

namespace Tests\Feature\OAuth;

use App\Models\Connection;
use App\OAuth\Contracts\OAuthFlow;
use App\OAuth\Testing\FakeOAuthFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthFlowContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_oauth_flow_satisfies_contract(): void
    {
        $flow = new FakeOAuthFlow();

        $this->assertInstanceOf(OAuthFlow::class, $flow);
    }

    public function test_fake_oauth_flow_exchange_code_marks_connection_active(): void
    {
        $flow = new FakeOAuthFlow();
        $connection = Connection::factory()->forMollie()->pending()->create();

        $flow->exchangeCode($connection, 'fake_code');

        $connection->refresh();
        $this->assertSame('active', $connection->status);
        $this->assertStringStartsWith('access_test_fake_', $connection->access_token);
        $this->assertSame(1, $flow->wasCalled('exchangeCode'));
    }

    public function test_fake_oauth_flow_revoke_sets_revoked_status(): void
    {
        $flow = new FakeOAuthFlow();
        $connection = Connection::factory()->forMollie()->create();

        $flow->revoke($connection);

        $connection->refresh();
        $this->assertSame('revoked', $connection->status);
        $this->assertNotNull($connection->revoked_at);
    }
}
```

**Landmines:**
- Deze test bewijst SC-4 expliciet: "Een `OAuthFlow`-contract heeft een tweede dummy-implementatie die laat zien dat het pattern niet Mollie-specifiek is."
- D-12: `wasCalled()->n()`-teller — getest hier (`$flow->wasCalled('exchangeCode') === 1`).
- Geen mocks — pure FakeOAuthFlow als drop-in.

---

### `tests/Feature/Console/PruneOAuthPendingConnectionsTest.php` (test, CLI exit-codes)

**Analog:** `tests/Feature/Console/HubConsumerCreateTest.php` — exact shape voor `$this->artisan(...)->assertExitCode(...)` pattern + `expectsOutputToContain`.

**Reference-shape** (`tests/Feature/Console/HubConsumerCreateTest.php:14-30`):
```php
public function test_creates_consumer_with_default_admin_ability(): void
{
    $this->artisan('hub:consumer:create', [
        '--slug' => 'naschool-test',
        '--name' => 'Naschool Test',
    ])->assertExitCode(0);

    $consumer = Consumer::where('slug', 'naschool-test')->first();
    $this->assertNotNull($consumer);
}
```

**Copy-pattern:**
```php
<?php

namespace Tests\Feature\Console;

use App\Models\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneOAuthPendingConnectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_expired_pending_connections(): void
    {
        $expired = Connection::factory()->forMollie()->pending()->expired()->create();
        $fresh = Connection::factory()->forMollie()->pending()->create();
        $active = Connection::factory()->forMollie()->active()->create();

        $this->artisan('oauth:prune-pending')->assertExitCode(0);

        $this->assertNull(Connection::find($expired->id));
        $this->assertNotNull(Connection::find($fresh->id));
        $this->assertNotNull(Connection::find($active->id));
    }

    public function test_dry_run_does_not_delete_anything(): void
    {
        $expired = Connection::factory()->forMollie()->pending()->expired()->create();

        $this->artisan('oauth:prune-pending', ['--dry-run' => true])
            ->expectsOutputToContain('Dry-run')
            ->assertExitCode(0);

        $this->assertNotNull(Connection::find($expired->id));
    }
}
```

**Landmines:**
- `assertExitCode(0)` = `self::SUCCESS` — matched bestaande `HubConsumerCreateTest`-conventie.
- `expectsOutputToContain('Dry-run')` — matched `HubConsumerCreateTest:35` `expectsOutputToContain('verplicht')`.

---

## Shared Patterns

### PHP-conventies (alle nieuwe PHP-files in `app/`)
**Source:** `app/Models/Connection.php`, `app/Console/Commands/HubConsumerCreate.php`, `app/Http/Controllers/Api/V1/PingController.php`
**Apply to:** alle nieuwe Phase-4-files behalve migrations (anonymous-class daar)
```php
<?php

declare(strict_types=1); // SDK-files (vendor/emeq/* + packages/*); GEEN strict_types in app/ — match bestaande Hub-conventie (zie Connection/Consumer/PingController)

namespace App\…;

use App\…;

final class … // 'final' voor utilities/value-objects, geen 'final' voor controllers (extends Controller)
{
    public function __construct(private readonly … $foo) {}

    public function bar(): ReturnType { /* ... */ }
}
```

**Conflict-resolution** (`.ai/rules/engineering.md`): SDK-tree (`packages/`, `vendor/emeq/`) gebruikt `declare(strict_types=1)`; Hub-tree (`app/`) doet dat NIET (zie `Connection.php`, `PingController.php`, `HubConsumerCreate.php`). **Volg Hub-conventie in `app/`-files; declare strict_types in nieuwe SDK-files (niet van toepassing in Phase 4).**

### Constructor-promotion + type-hints (alle services + controllers)
**Source:** `app/Providers/AppServiceProvider.php`, vendor SDK-classes
**Apply to:** `MollieConnectOAuthFlow`, `HubMollieCredentialResolver`, `OAuthFlowRegistry`, `MollieConnectionContext`, beide controllers (`InitController`, `CallbackController`)
```php
public function __construct(
    private readonly HttpFactory $http,
    private readonly ConfigRepository $config,
) {}
```

PHP 8 promoted properties met `readonly` voor immutability + type-hints op alle parameters (per `.ai/rules/php`).

### Single-action `__invoke` (controllers)
**Source:** `app/Http/Controllers/Api/V1/PingController.php:14`
**Apply to:** `InitController`, `CallbackController`
```php
class PingController extends Controller
{
    public function __invoke(Request $request): array|JsonResponse
    {
        // …
    }
}
```

Geen resource-controllers — één route per controller, één action per file. Matched bestaande pattern.

### Sanctum ability-middleware (request-response routes)
**Source:** `routes/api.php:14-16`, `app/Sanctum/TokenAbilities.php`, `tests/Feature/Api/SanctumAbilityTest.php`
**Apply to:** alle `/v1/oauth/mollie/init` route-toevoegingen
```php
Route::middleware(['auth:sanctum', 'ability:mollie:write'])->group(…);
```

`auth:sanctum` van groep + per-route `ability:<name>` — werkt out-of-the-box. `mollie:write` (al gedefinieerd in `TokenAbilities.php:13`).

### Encrypted-at-rest invariant (Connection-uitbreidingen)
**Source:** `app/Models/Connection.php:51-63` + `tests/Feature/ConnectionEncryptionTest.php`
**Apply to:** `MollieConnectOAuthFlow::exchangeCode`, `MollieConnectOAuthFlow::refreshToken`, `FakeOAuthFlow::exchangeCode`
- Eloquent `'encrypted'`-cast doet het werk — schrijf gewoon `$connection->access_token = 'access_xyz'` en het wordt encrypted at rest.
- DB-bypass-assert pattern uit `ConnectionEncryptionTest.php:14-27` als je at-rest-verificatie nodig hebt:
```php
$rawAtRest = DB::table('connections')->where('id', $id)->value('access_token');
$this->assertNotSame('access_xyz', $rawAtRest);
```

### Fingerprint-only in logs (alle nieuwe HTTP-clients)
**Source:** `app/Models/Connection.php:37-46` + `.ai/rules/global.md` Security
**Apply to:** `MollieConnectOAuthFlow` (logs van token-exchange / refresh)
- **NOOIT** `Log::info($response->body())` met raw token-body.
- Wel: `Log::info("oauth.refresh.ok", ['connection' => $connection->fingerprint()])`.
- Exceptions: `RuntimeException::class` met alleen `$response->status()` + `$response->reason()` — geen `$response->body()`.

### Migration anonymous-class (alle DDL-changes)
**Source:** `database/migrations/2026_05_14_151327_add_active_unique_to_connections.php`
**Apply to:** `*_add_oauth_state_to_connections_table.php`
```php
return new class extends Migration
{
    public function up(): void { /* ... */ }
    public function down(): void { /* ... */ }
};
```

Anonymous-class (Laravel 11+ idiom); `up()`/`down()`-paar; forward-only invariant geldt voor productie-pad maar `down()` mag bestaan voor `migrate:fresh` in dev/test.

### PHPUnit feature-test skeleton (alle Phase-4 tests)
**Source:** `tests/Feature/Api/PingTest.php`, `tests/Feature/ConnectionEncryptionTest.php`, `tests/Feature/Console/HubConsumerCreateTest.php`
**Apply to:** alle Phase-4 tests
- `extends Tests\TestCase` (PHPUnit, niet Pest)
- `use RefreshDatabase;` voor tests die DB raken
- Test-naam `test_<scenario>_<expected>` snake_case
- `$this->assert*` (geen `expect()`)
- Test-command: `php artisan test --compact --filter=<TestClass>`

### Taal-conventie (alle Phase-4 files)
**Source:** `.ai/rules/global.md`
- Code, identifiers, type-hints, class-namen: **Engels** (`MollieConnectOAuthFlow`, `getAuthorizationUrl`, `oauth_state`)
- CLI-output, error-messages naar user, command-descriptions: **Nederlands** (`'Verwijderd: 3 expired pending Connection(s).'`)
- Mollie-domeintermen blijven Engels (Payments, Customers, Subscriptions — niet vertalen)
- Snelstart-domeintermen blijven Nederlands (niet relevant in Phase 4)

### Test-fixture in `app/` (niet `tests/`)
**Source:** `packages/mollie-api/src/Testing/FakeMollieCredentialResolver.php` (SDK-laag analog)
**Apply to:** `app/OAuth/Testing/FakeOAuthFlow.php` (Hub-laag)
- **D-12 expliciet:** FakeOAuthFlow leeft in `app/OAuth/Testing/`, NIET in `tests/Feature/OAuth/`.
- Reden: feature-tests binden 'm via container (`$this->app->bind(MollieConnectOAuthFlow::class, FakeOAuthFlow::class)`). Composer's `psr-4 App\\ => app/` pakt de namespace op zonder PSR-4-truc.
- Wel `final class` met deterministic outputs en een `wasCalled()`-teller.

---

## No Analog Found

Files waarvoor geen close match bestaat in deze repo — planner moet research + Mollie-docs raadplegen, of de patterns hierboven combineren.

| File | Role | Data Flow | Reden / Alternatieve bron |
|---|---|---|---|
| `app/OAuth/OAuthFlowRegistry.php` | utility (provider-keyed lookup) | request-response | Geen registry-pattern in repo. Class-shape mirrort `App\Sanctum\TokenAbilities` (thin final class). Container-binding via `AppServiceProvider::register()` singleton. Geen Laravel `tagged()` nodig voor v0.2 (één provider). |
| `app/Mollie/MollieConnectionContext.php` | service (request-scoped state) | request-response | Geen request-scoped service in repo (Phase 3 had alleen models + Sanctum-auth). `$this->app->scoped()` binding-pattern komt van Laravel-docs; verschilt van `singleton()` doordat scoped per-request vers is. |
| `app/OAuth/Mollie/MollieConnectOAuthFlow.php` | implementation (HTTP-out service) | request-response (HTTP token-exchange) | Geen Http-out-client in `app/`-tree. Vendor-analog `vendor/emeq/snelstart-api/src/Auth/ClientKeyAuthenticator.php` is Saloon-gebaseerd (anders dan D-15: directe `Http::post`). Mollie docs zijn de canonical source: https://docs.mollie.com/reference/oauth2/tokens. |
| `tests/Feature/OAuth/MollieConnectOAuthFlowTest.php` (`Http::fake()`) | test (HTTP-fake) | HTTP-mock | Geen bestaand gebruik van `Illuminate\Support\Facades\Http::fake()` in tests. Laravel-standard pattern; planner moet 'm hier voor het eerst instantiëren. |

---

## Metadata

**Analog search scope:** `app/`, `bootstrap/`, `config/`, `database/`, `routes/`, `tests/`, `packages/mollie-api/src/`, `vendor/emeq/snelstart-api/src/`
**Files scanned:** 31 (18 target-files + 13 referentie-files in deze repo + vendor)
**Repo-state at mapping:** branch `chore/v02-roadmap-split-and-scramble` @ HEAD `148f8d4` (na Phase 3 close + Phase 5b context).
**Pattern extraction date:** 2026-05-14
**Phase 3 PATTERNS.md style reference:** `.planning/phases/03-hub-skeleton/03-PATTERNS.md` — identieke section-structure (Classification → Pattern Assignments → Shared → No Analog Found → Metadata).
