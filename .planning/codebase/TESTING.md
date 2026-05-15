# Testing Patterns

**Analysis Date:** 2026-05-15

## Framework Split

**Hub (`emeq-hub` repo):** **PHPUnit 12.5.12** (`phpunit/phpunit` v12).
- Config: `phpunit.xml` (default-suite) + `phpunit.integration.xml` (echte Mollie test-mode).
- Base TestCase: `tests/TestCase.php` (extends `Illuminate\Foundation\Testing\TestCase`).
- Geen Pest in de Hub — Laravel-Boost's PHPUnit-rule: "If you see a test using Pest, convert it to PHPUnit." Alle Hub-tests zijn class-based PHPUnit.

**SDK-packages (`packages/snelstart-api/`, `packages/mollie-api/`):** **Pest v3/v4** met `pestphp/pest-plugin-laravel` + `pestphp/pest-plugin-arch`.
- Config: `packages/<name>/phpunit.xml.dist` met strict-flags (`failOnWarning="true"`, `failOnRisky="true"`, `executionOrder="random"`).
- Base TestCase: `packages/<name>/tests/TestCase.php` + bootstrap in `packages/<name>/tests/Pest.php` met `uses(TestCase::class)->in(__DIR__)`.
- Cache-flushing globaal in elke `beforeEach` (zie reden in `packages/mollie-api/tests/Pest.php:11-15`).

**Reden voor de split:** Pest is OSS-conventie in losse Laravel-packages (`spatie/*`, `mollie/*` ecosystem). PHPUnit is Laravel-Boost-default in een app. Niet mixen binnen één repo.

## Test File Organization

**Hub-layout:**
```
tests/
├── TestCase.php                 # Abstract base (extends Illuminate base)
├── Concerns/                    # Reusable test-traits
│   ├── BindsMollieConnectionContext.php
│   ├── PrimesSnelstartTokenCache.php
│   └── StubsMollieClient.php
├── Unit/                        # Pure unit (geen Laravel-boot)
│   ├── ExampleTest.php
│   ├── Billing/
│   └── Support/
│       ├── Snelstart/           # UpstreamErrorMapperTest, HeaderForwarderTest
│       └── Mollie/              # MollieUpstreamErrorMapperTest
├── Feature/                     # Full Laravel boot + DB + HTTP
│   ├── Api/                     # API-endpoints
│   │   ├── OAuth/               # OAuth init/callback
│   │   └── V1/
│   │       ├── Snelstart/       # Pass-through tests
│   │       ├── Mollie/          # Mollie controllers
│   │       ├── StoreAccountTest.php
│   │       └── StoreConnectionTest.php
│   ├── Webhooks/                # Inbound webhook controllers
│   ├── OAuth/                   # OAuth flow-classes
│   ├── Services/                # HubSnelstartCredentialResolver, etc.
│   ├── Mollie/                  # HubMollieCredentialResolver
│   ├── Billing/                 # ConsumerBillableTest
│   ├── Console/                 # Artisan commands
│   ├── Documentation/           # Scramble route-discovery
│   ├── ConnectionEncryptionTest.php
│   ├── ConnectionUniqueActiveTest.php
│   ├── ConsumerAccountScopingTest.php
│   ├── NoIndexHeaderTest.php
│   └── PassThroughCallModelTest.php
└── Integration/                 # Echte test-mode Mollie API hits
    ├── IntegrationTestCase.php  # Skipt zonder CASHIER_MOLLIE_KEY
    └── Billing/
        ├── CashierMollieSubscriptionFlowTest.php
        └── CashierWebhookEndToEndTest.php
```

**SDK-layout:** flat `tests/` met `<feature>Test.php` of `<Feature>/...Test.php` — geen Unit/Feature split, Pest descriptors regelen de scope.

**Naming:**
- Test-classes eindigen op `Test.php` (PHPUnit) — `ConnectionEncryptionTest`, `SubscriptionsTest`.
- Test-methods: `test_xxx_yyy(): void` met beschrijvende-zin-snake-case. Geen `@test`-annotatie nodig.
- Test-traits in `tests/Concerns/`: werkwoord-prefix (`BindsMollieConnectionContext`, `PrimesSnelstartTokenCache`, `StubsMollieClient`).
- Spelling: één test-functie = één gedrag. Splits ipv chaining assertions over meerdere "happy paths".

## Test Structure

**Standaard Feature-test layout** (`tests/Feature/Webhooks/MollieWebhookSignatureTest.php`):

```php
<?php

namespace Tests\Feature\Webhooks;

use App\Models\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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
        // ... arrange
        $response = $this->call('POST', "/webhooks/mollie/{$connection->id}", ...);

        // ... assert
        $response->assertStatus(202);
        $this->assertDatabaseHas('webhook_calls', ['name' => 'mollie']);
        Bus::assertDispatched(ForwardMollieWebhookToConsumer::class);
    }
}
```

**Patterns:**

- **`use RefreshDatabase;`** standaard op alle Feature-tests met DB-state (50/66 testfiles). Skipt voor pure Unit-tests (`UpstreamErrorMapperTest` extends `PHPUnit\Framework\TestCase` direct).
- **`setUp() / tearDown()`** voor per-test mock-reset:
  ```php
  protected function setUp(): void {
      parent::setUp();
      MockClient::destroyGlobal();
      config(['snelstart.http.retry.times' => 1, 'snelstart.http.retry.sleep' => 0]);
  }
  protected function tearDown(): void {
      MockClient::destroyGlobal();
      parent::tearDown();
  }
  ```
  Zie `tests/Feature/Api/V1/Snelstart/PassThroughOdataRelatiesTest.php:24-35`.
- **Test-traits voor herbruikbare setup:**
  - `Tests\Concerns\StubsMollieClient` — binds een Mollie-client-mock met capturing-stubs.
  - `Tests\Concerns\PrimesSnelstartTokenCache` — pre-fills SDK-token-cache zodat ClientKeyAuthenticator geen echte OAuth-hit doet.
  - `Tests\Concerns\BindsMollieConnectionContext` — direct context-bind voor unit-stijl tests.
- **AAA-stijl** binnen één test, met whitespace-separator tussen arrange/act/assert.
- **`#[Group('integration')]`** attribute op integration-tests (PHPUnit 12 native attributes, geen DocBlock `@group`). Zie `tests/Integration/IntegrationTestCase.php:23` en `phpunit.xml:15-19` (default-suite excludes `integration` group).

**Unit-test layout** (`tests/Unit/Support/Snelstart/UpstreamErrorMapperTest.php`):

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Snelstart;

use App\Support\Snelstart\UpstreamErrorMapper;
use PHPUnit\Framework\TestCase;

class UpstreamErrorMapperTest extends TestCase
{
    public function test_authentication_exception_maps_to_502_with_snelstart_auth_short_code(): void
    {
        $exception = AuthenticationException::tokenFetchFailed(401, '{"error":"invalid_client"}', 'ac942340c588');
        $result = UpstreamErrorMapper::mapException($exception);

        $this->assertSame(502, $result['status']);
        $this->assertSame('snelstart_auth', $result['short_code']);
    }
}
```

Unit-tests extends **`PHPUnit\Framework\TestCase` direct**, niet `Tests\TestCase` — geen Laravel-boot, geen DB.

## Mocking

**Drie verschillende mock-pattern's, één per laag:**

### 1. Laravel HTTP Fake (voor `Illuminate\Http\Client`)

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'api.mollie.com/oauth2/tokens' => Http::response([
        'access_token' => 'access_real_xyz',
        'refresh_token' => 'refresh_real_xyz',
        'expires_in' => 3600,
        'scope' => 'payments.read payments.write',
    ]),
]);
```

Gebruikt in `tests/Feature/OAuth/MollieConnectOAuthFlowTest.php` voor de OAuth2 token-exchange — de flow-class injectie'rt `HttpFactory`, dus `Http::fake` werkt.

### 2. Saloon's `MockClient::global` (voor de Snelstart-SDK)

```php
use Emeq\SnelstartApi\Http\Request\RawSnelstartRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

MockClient::global([
    RawSnelstartRequest::class => function (PendingRequest $pr) use (&$captured) {
        $captured = [
            'query' => $pr->query()->all(),
            'url' => $pr->getUrl(),
            'method' => $pr->getMethod()->value,
        ];
        return MockResponse::make(['value' => [['id' => 'r-1']]], 200);
    },
]);
```

`MockClient::destroyGlobal()` in `setUp()` én `tearDown()` om state-bleed tussen tests te voorkomen. Zie `tests/Feature/Api/V1/Snelstart/PassThroughOdataRelatiesTest.php:42-53`.

### 3. Custom Mollie-stub via `StubsMollieClient` trait + `createMock`

Mollie's SDK is geen Saloon-stack (wrapt `mollie/mollie-api-php` rechtstreeks), dus een custom anonieme-class-stub die `MollieApiClient`-endpoints na-aapt + payload-capturing:

```php
use Tests\Concerns\StubsMollieClient;

class SubscriptionsTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    public function test_post_customer_subscriptions_creates_via_sdk(): void
    {
        [, $token, , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => $this->makeSubscription([
                'id' => 'sub_new_1',
                'status' => 'pending',
                'customerId' => $arg['customer_id'],
            ]),
        ]);

        $response = $this->callMollie($token, 'POST', '/v1/mollie/customers/cst_abc/subscriptions', $payload);

        $response->assertCreated();
        $this->assertCount(1, $this->mollieCaptured['subscription_create_for_id']);
    }
}
```

`bindMollieStubs()` retourneert een mocked `Emeq\MollieApi\Mollie`-wrapper via `$this->createMock(Mollie::class)` + `app()->instance(Mollie::class, $mollie)`. Capture-array per endpoint (`payment_create`, `subscription_create_for_id`, `mandate_get_for_id`, …) staat tot je beschikking via `$this->mollieCaptured`.

### 4. Laravel-facade fakes voor side-effects

```php
use Illuminate\Support\Facades\Bus;

Bus::fake();
// ... act
Bus::assertDispatched(ForwardMollieWebhookToConsumer::class);
Bus::assertNotDispatched(ForwardMollieWebhookToConsumer::class);
```

Gebruikt in alle webhook-tests (`tests/Feature/Webhooks/`).

**What to mock:**
- Externe HTTP (Mollie API, Snelstart API, Mollie OAuth-server).
- Queue-dispatches voor unit-isolatie.
- `Mollie::class` wrapper voor unit-niveau Mollie-tests.

**What NOT to mock:**
- Eloquent-models (gebruik factories + `RefreshDatabase`).
- Form-Request-validation (echte controller-flow runt validatie).
- Sanctum-token-creation (`Consumer::factory()->create()->createToken(...)`).
- Saloon's eigen interne classes — alleen `MockClient::global` op request-class-niveau.

## Fixtures & Factories

Eloquent-factories in `database/factories/`:

```
AccountFactory.php
ConnectionFactory.php
ConsumerFactory.php
PassThroughCallFactory.php
UserFactory.php
```

**Factory-pattern:**

```php
/**
 * @extends Factory<Connection>
 */
class ConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['account_id' => Account::factory(), 'provider' => 'snelstart', 'status' => 'active'];
    }

    public function forSnelstart(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => 'snelstart',
            'client_key' => 'CK-'.Str::random(40),
            'subscription_key' => 'SK-'.Str::random(40),
            …
        ]);
    }

    public function forMollie(): static { … }
    public function pending(): static { … }
    public function active(): static { … }
    public function expired(): static { … }
}
```

**Conventions:**
- **Named states als methods** (camelCase werkwoord-of-adjective): `forSnelstart()`, `forMollie()`, `pending()`, `active()`, `expired()`, `withWebhookCallback($url, $secret)`, `withActiveSubscription($planSlug)`. Returntype `static` voor chaining.
- **Faker via global `fake()`** helper — niet `$this->faker`. Bevestigd in alle 5 factories (`fake()->company()`, `fake()->unique()->numerify('######')`, `fake()->numberBetween(20, 400)`).
- **Test-data lijkt op productie maar mag herkenbaar zijn:** `'CK-test-rawkey-DO-NOT-LEAK'` voor secrets in audit-leakage-tests (`tests/Feature/Api/V1/Snelstart/PassThroughAuditNoSecretsTest.php:26`).
- **`afterCreating` callbacks** voor cross-table state die niet via FK kan: `withActiveSubscription` schrijft direct in Cashier's `subscriptions`-tabel (`ConsumerFactory.php:46-60`).
- **Helper-builders binnen tests** voor resource-objects die geen factory hebben:
  ```php
  protected function makeSubscription(array $attributes): Subscription { … }
  protected function makeSubscriptionCollection(array $items): SubscriptionCollection { … }
  ```
  Zie `tests/Concerns/StubsMollieClient.php:495-555`.

## Database Setup

- **In-memory SQLite** voor de default-suite: `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` (`phpunit.xml:31-32`).
- **`use RefreshDatabase;`** trigger't migrations per test (in-memory, snel).
- **Hub gebruikt Postgres 16 in dev/prod** — feature-tests die provider-specifieke SQL hebben (constraints, `whereJsonContains`) moeten lokaal getest worden via integration-config, niet vertrouwen op SQLite. Geen aparte voorziening per 2026-05-15 — toekomstige CONCERNS-item.

## Integration Tests (echte Mollie test-mode)

`tests/Integration/IntegrationTestCase.php` skipt automatisch als:
- `CASHIER_MOLLIE_KEY` of `MOLLIE_KEY` niet gezet is, OF
- niet met `test_`-prefix begint, OF
- gelijk is aan de `.env.example` placeholder `test_xxx`.

**Run apart:**
```bash
composer test:integration
# of:
vendor/bin/phpunit --configuration=phpunit.integration.xml
```

Default `php artisan test` excludeert deze tests via `<groups><exclude><group>integration` (`phpunit.xml:15-19`) — feature-branches zonder secrets blijven groen.

## Coverage

- **Geen coverage-treshold geforceerd** per 2026-05-15. `phpunit.xml` declareert `<source>` (`app/`) maar geen `coverage`-block.
- **Coverage-focus:** elke nieuwe public method moet ≥1 happy-path + ≥1 failure-path + edge-cases (Laravel-Boost PHPUnit-rule "Tests should cover all happy paths, failure paths, and edge cases").
- Lokaal coverage genereren:
  ```bash
  XDEBUG_MODE=coverage php artisan test --coverage
  ```

## Run Commands (uit `.ai/dev-setup`)

```bash
# Hub-tests (PHPUnit, default-suite — Unit + Feature, exclude integration)
php artisan test --compact
php artisan test --compact tests/Feature/ConnectionEncryptionTest.php
php artisan test --compact --filter=test_snelstart_client_key_is_encrypted_at_rest

# Hub-integration-tests (echte Mollie test-mode — apart!)
composer test:integration

# SDK-package-tests (Pest, eigen vendor)
cd packages/snelstart-api && ./vendor/bin/pest
cd packages/mollie-api && ./vendor/bin/pest

# Format na test-wijziging
vendor/bin/pint --dirty --format agent
```

**Bij wijziging van een file:** run minstens de relevante `--filter` of file-scope, niet de hele suite (Laravel-Boost-PHPUnit-rule: "Run the minimal number of tests").

## Test Types

**Unit Tests (`tests/Unit/`):**
- Extends `PHPUnit\Framework\TestCase` direct — geen Laravel-boot.
- Voor pure mappers/transformers: `UpstreamErrorMapperTest`, `HeaderForwarderTest`, `MollieUpstreamErrorMapperTest`, `PlanResolverTest`.
- Snel, geen DB, geen container.

**Feature Tests (`tests/Feature/`):**
- Extends `Tests\TestCase` (Laravel-boot).
- Voor HTTP-flows, middleware, models, Eloquent-relations, Sanctum-abilities.
- DB via in-memory SQLite + `RefreshDatabase`.
- 95% van Hub-test-volume.

**Integration Tests (`tests/Integration/`):**
- Extends `Tests\Integration\IntegrationTestCase` met `#[Group('integration')]`.
- Hit'tt echte Mollie test-mode API — vereist `CASHIER_MOLLIE_KEY`.
- Skipt automatisch op CI zonder secrets.
- Voor end-to-end Cashier-flows: subscription-create-met-first-payment-redirect, cancel-met-echte-mandate.

**E2E (browser-level):** niet aanwezig per 2026-05-15. Niet gepland voor v0.2.

## Common Patterns

**Async Testing (queue):**
```php
Bus::fake();
// dispatch happens in the controller
Bus::assertDispatched(ForwardMollieWebhookToConsumer::class);
Bus::assertNotDispatched(ForwardMollieWebhookToConsumer::class);
```

**Error Testing (exception mapping):**
```php
public function test_authentication_exception_maps_to_502_with_snelstart_auth_short_code(): void
{
    $exception = AuthenticationException::tokenFetchFailed(401, '{"error":"invalid_client"}', 'ac942340c588');
    $result = UpstreamErrorMapper::mapException($exception);
    $this->assertSame(502, $result['status']);
    $this->assertSame('snelstart_auth', $result['short_code']);
}
```

**Encrypted-at-rest verification:**
```php
public function test_snelstart_client_key_is_encrypted_at_rest(): void
{
    $connection = Connection::factory()->forSnelstart()->create(['client_key' => 'CK-secret-123']);

    $rawAtRest = DB::table('connections')->where('id', $connection->id)->value('client_key');

    $this->assertNotSame('CK-secret-123', $rawAtRest);  // ciphertext in DB
    $this->assertSame('CK-secret-123', $connection->fresh()->client_key);  // decrypted via cast
}
```
Standard-pattern voor security-invariants (`tests/Feature/ConnectionEncryptionTest.php`).

**Audit-no-leak verification (loop over DB-columns):**
```php
$row = (array) DB::table('pass_through_calls')->latest('id')->first();
foreach ($row as $col => $val) {
    if (is_string($val)) {
        $this->assertStringNotContainsString(self::RAW_CLIENT_KEY, $val, "Audit-kolom {$col} bevat raw clientKey.");
    }
}
```
Zie `tests/Feature/Api/V1/Snelstart/PassThroughAuditNoSecretsTest.php:49-59`.

**Sanctum-token-ability tests:**
```php
$consumer = Consumer::factory()->create();
$token = $consumer->createToken('snel-read', [TokenAbilities::SNELSTART_READ])->plainTextToken;

$this->withHeader('Authorization', "Bearer {$token}")
    ->withHeader('X-Account-Id', 'school-A')
    ->getJson('/v1/mollie/payment-methods')
    ->assertStatus(403)
    ->assertJsonPath('error', 'insufficient_ability');
```

**Database assertions:**
```php
$this->assertDatabaseHas('pass_through_calls', [
    'provider' => 'mollie',
    'method' => 'POST',
    'path' => '/v2/customers/{id}/subscriptions',
    'status' => 201,
    'connection_id' => $connection->getKey(),
]);
```

**JSON-response assertions:**
```php
$response->assertCreated()
    ->assertJsonPath('id', 'sub_new_1')
    ->assertJsonPath('customerId', 'cst_abc')
    ->assertJsonCount(2);
```

## Test-traits index (`tests/Concerns/`)

| Trait | Doel | File |
|---|---|---|
| `StubsMollieClient` | Mollie-client stub met capturing, één resolver per endpoint (`payments`, `customers`, `methods`, `paymentRefunds`, `mandates`, `subscriptions`, `paymentLinks`). | `tests/Concerns/StubsMollieClient.php` |
| `PrimesSnelstartTokenCache` | Pre-fills `Emeq\SnelstartApi`'s token-cache zodat `ClientKeyAuthenticator` geen echte OAuth-hit doet in een test. | `tests/Concerns/PrimesSnelstartTokenCache.php` |
| `BindsMollieConnectionContext` | Direct `MollieConnectionContext::set()` voor unit-stijl tests die de `ResolveMollieAccount`-middleware niet triggeren. | `tests/Concerns/BindsMollieConnectionContext.php` |

Nieuwe herbruikbare test-helpers landen óók onder `tests/Concerns/` met werkwoord-prefix-naam.

---

*Testing analysis: 2026-05-15*
