---
phase: 05b-snelstart-pass-through-api
plan: 02
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Services/Snelstart/HubSnelstartCredentialResolver.php
  - tests/Feature/Services/HubSnelstartCredentialResolverTest.php
autonomous: true
requirements: [HUB-05]
tags:
  - laravel
  - snelstart
  - credential-resolution
  - sdk-binding
  - phpunit

must_haves:
  truths:
    - "Een `HubSnelstartCredentialResolver` gebonden aan een Connection produceert een `SnelstartCredentials`-DTO met de decrypted `client_key` / `subscription_key` / `subscription_id` van die Connection"
    - "De resolver kan worden geïnstantieerd zonder ServiceProvider-changes — bindt per-request in een middleware (Plan 05 wires die in)"
    - "Per-request scoped binding garandeert dat een tweede request voor een andere Account/Connection nooit oude credentials krijgt"
  artifacts:
    - path: "app/Services/Snelstart/HubSnelstartCredentialResolver.php"
      provides: "Hub-implementatie van `Emeq\\SnelstartApi\\Contracts\\SnelstartCredentialResolver` die uit een `Connection` leest"
      contains: "implements SnelstartCredentialResolver"
    - path: "tests/Feature/Services/HubSnelstartCredentialResolverTest.php"
      provides: "Bewijs dat resolver de juiste decrypted-credentials teruggeeft, fingerprint deterministisch is, en dat een Connection zonder Snelstart-shape de constructie afkeurt"
      contains: "class HubSnelstartCredentialResolverTest"
  key_links:
    - from: "App\\Services\\Snelstart\\HubSnelstartCredentialResolver"
      to: "Emeq\\SnelstartApi\\Contracts\\SnelstartCredentialResolver"
      via: "implements"
      pattern: "implements .*SnelstartCredentialResolver"
    - from: "HubSnelstartCredentialResolver::resolve()"
      to: "Emeq\\SnelstartApi\\Data\\SnelstartCredentials"
      via: "return new SnelstartCredentials(clientKey: ..., subscriptionKey: ..., subscriptionId: ...)"
      pattern: "new SnelstartCredentials"
---

<objective>
Een Hub-implementatie van de Snelstart SDK's `SnelstartCredentialResolver`-interface die uit een `App\Models\Connection` leest. Wordt in Plan 05 per-request gebonden aan de container in `ResolveSnelstartAccount`-middleware.

Purpose: HUB-05 success criterion 3 — *"`GET /v1/snelstart/echo/ping` proxied → bewijst resolver-binding"*. Zonder deze resolver kan de SDK geen Snelstart-call doen vanaf Hub-credentials.

Output: één thin service-class + één unit/feature-test. Géén ServiceProvider-wijziging in dit plan (binding gebeurt per-request in middleware uit Plan 05); géén routes/controllers in dit plan.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/STATE.md
@.planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md
@CLAUDE.md
@app/Models/Connection.php
@packages/snelstart-api/src/Contracts/SnelstartCredentialResolver.php
@packages/snelstart-api/src/Data/SnelstartCredentials.php
@database/factories/ConnectionFactory.php

<interfaces>
<!-- Bestaande interface die we gaan implementeren. NIET wijzigen — alleen consumeren. -->

From packages/snelstart-api/src/Contracts/SnelstartCredentialResolver.php:
```php
namespace Emeq\SnelstartApi\Contracts;

interface SnelstartCredentialResolver {
    public function resolve(): SnelstartCredentials;
}
```

From packages/snelstart-api/src/Data/SnelstartCredentials.php:
```php
namespace Emeq\SnelstartApi\Data;

final readonly class SnelstartCredentials {
    public function __construct(
        public string $clientKey,
        public string $subscriptionKey,
        public ?string $subscriptionId = null,
    );
    public function fingerprint(): string; // hash('sha256', $clientKey) — full hash, 64 chars
}
```

From app/Models/Connection.php (encrypted accessors al present uit Phase 3):
```php
class Connection extends Model {
    // casts(): client_key + subscription_key zijn 'encrypted' (decrypted bij access)
    // subscription_id is plain string (tenant-UUID, niet zelf een secret)
    // public function fingerprint(): ?string // sha256(client_key)[0..12], NIET sha256 full
}
```
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: `HubSnelstartCredentialResolver` + tests</name>
  <files>app/Services/Snelstart/HubSnelstartCredentialResolver.php, tests/Feature/Services/HubSnelstartCredentialResolverTest.php</files>
  <behavior>
    - Constructor neemt een `App\Models\Connection`; bewaart 'm `private readonly`
    - `resolve(): SnelstartCredentials` decrypteert `client_key` + `subscription_key` (via bestaande Eloquent `encrypted` casts) en bouwt `new SnelstartCredentials(clientKey, subscriptionKey, subscriptionId)`
    - `subscription_id` is een plain string-veld op de Connection en wordt 1-op-1 doorgegeven (mag `null` zijn)
    - Roep je `resolve()` aan op een Connection waar `client_key` of `subscription_key` `null` is, dan gooit `SnelstartCredentials` zelf een `InvalidArgumentException` (bestaand gedrag in DTO) — dat is acceptabel: de middleware in Plan 05 voorkomt dat een non-Snelstart-Connection ooit aan deze resolver wordt gegeven
    - Test 1: happy path met `Connection::factory()->forSnelstart()->create()` — `resolve()` geeft de juiste decrypted-waarden terug
    - Test 2: fingerprint-determinisme — twee resolvers op dezelfde Connection produceren `SnelstartCredentials` met identieke `fingerprint()`
    - Test 3: contract-conformance — `$resolver instanceof SnelstartCredentialResolver` is true
    - Test 4: missing-credential-pad — `Connection::factory()->forMollie()->create()` (geen `client_key`) → `resolve()` gooit `InvalidArgumentException`
  </behavior>
  <read_first>
    - packages/snelstart-api/src/Contracts/SnelstartCredentialResolver.php (interface die je implementeert — single method `resolve(): SnelstartCredentials`)
    - packages/snelstart-api/src/Data/SnelstartCredentials.php (constructor-validatie: throwt `InvalidArgumentException` bij lege strings)
    - app/Models/Connection.php (encrypted casts + bestaande `fingerprint()`-accessor — niet hergebruiken in resolver; resolver werkt op decrypted-veld-access)
    - database/factories/ConnectionFactory.php (`forSnelstart()`-state vult `client_key` / `subscription_key` / `subscription_id`)
  </read_first>
  <action>
    **Service-class** `app/Services/Snelstart/HubSnelstartCredentialResolver.php`:

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Services\Snelstart;

    use App\Models\Connection;
    use Emeq\SnelstartApi\Contracts\SnelstartCredentialResolver;
    use Emeq\SnelstartApi\Data\SnelstartCredentials;

    /**
     * Per-Connection Snelstart credential-resolver. Bindt aan
     * Emeq\SnelstartApi\Contracts\SnelstartCredentialResolver via de container
     * in ResolveSnelstartAccount-middleware (Plan 05). Constructor neemt een
     * Snelstart-Connection; resolve() leest de decrypted waardes via de
     * Eloquent encrypted-casts en bouwt de DTO die de SDK consumeert.
     */
    final readonly class HubSnelstartCredentialResolver implements SnelstartCredentialResolver
    {
        public function __construct(
            private Connection $connection,
        ) {
        }

        public function resolve(): SnelstartCredentials
        {
            return new SnelstartCredentials(
                clientKey: (string) $this->connection->client_key,
                subscriptionKey: (string) $this->connection->subscription_key,
                subscriptionId: $this->connection->subscription_id,
            );
        }
    }
    ```

    `final readonly` + property promotion per `.ai/rules/php.md`. Geen public methodes anders dan `resolve()`. Geen multi-Account-state intern — één resolver = één Connection.

    **Test** `tests/Feature/Services/HubSnelstartCredentialResolverTest.php` (PHPUnit, namespace `Tests\Feature\Services`):

    ```php
    <?php

    namespace Tests\Feature\Services;

    use App\Models\Connection;
    use App\Services\Snelstart\HubSnelstartCredentialResolver;
    use Emeq\SnelstartApi\Contracts\SnelstartCredentialResolver;
    use Emeq\SnelstartApi\Data\SnelstartCredentials;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use InvalidArgumentException;
    use Tests\TestCase;

    class HubSnelstartCredentialResolverTest extends TestCase
    {
        use RefreshDatabase;

        public function test_resolve_returns_decrypted_snelstart_credentials(): void
        {
            $connection = Connection::factory()->forSnelstart()->create([
                'client_key'       => 'CK-test-1234',
                'subscription_key' => 'SK-test-5678',
                'subscription_id'  => 'subscription-uuid-aaa',
            ]);

            $resolver = new HubSnelstartCredentialResolver($connection);
            $creds    = $resolver->resolve();

            $this->assertInstanceOf(SnelstartCredentials::class, $creds);
            $this->assertSame('CK-test-1234', $creds->clientKey);
            $this->assertSame('SK-test-5678', $creds->subscriptionKey);
            $this->assertSame('subscription-uuid-aaa', $creds->subscriptionId);
        }

        public function test_resolver_implements_sdk_contract(): void
        {
            $resolver = new HubSnelstartCredentialResolver(
                Connection::factory()->forSnelstart()->create()
            );

            $this->assertInstanceOf(SnelstartCredentialResolver::class, $resolver);
        }

        public function test_two_resolves_on_same_connection_produce_same_fingerprint(): void
        {
            $connection = Connection::factory()->forSnelstart()->create();
            $resolver   = new HubSnelstartCredentialResolver($connection);

            $first  = $resolver->resolve()->fingerprint();
            $second = $resolver->resolve()->fingerprint();

            $this->assertSame($first, $second);
            $this->assertSame(64, strlen($first), 'SnelstartCredentials::fingerprint() returns full sha256');
        }

        public function test_resolve_throws_when_connection_has_no_snelstart_credentials(): void
        {
            $mollieConnection = Connection::factory()->forMollie()->create(); // client_key + subscription_key zijn null

            $resolver = new HubSnelstartCredentialResolver($mollieConnection);

            $this->expectException(InvalidArgumentException::class);
            $resolver->resolve();
        }
    }
    ```

    Run pint: `vendor/bin/pint --dirty --format agent`.
    Run test: `php artisan test --compact --filter=HubSnelstartCredentialResolverTest`.

    **Géén** ServiceProvider-binding in dit plan — die hoort thuis in `ResolveSnelstartAccount`-middleware (Plan 05) zodat de binding per-request scoped is en niet globaal lekt naar andere requests.
  </action>
  <verify>
    <automated>php artisan test --compact --filter=HubSnelstartCredentialResolverTest</automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "implements SnelstartCredentialResolver" app/Services/Snelstart/HubSnelstartCredentialResolver.php` == 1
    - `grep -c "final readonly class HubSnelstartCredentialResolver" app/Services/Snelstart/HubSnelstartCredentialResolver.php` == 1
    - `grep -c "new SnelstartCredentials" app/Services/Snelstart/HubSnelstartCredentialResolver.php` == 1
    - `grep -c "public function resolve(): SnelstartCredentials" app/Services/Snelstart/HubSnelstartCredentialResolver.php` == 1
    - `grep -cE "public function test_" tests/Feature/Services/HubSnelstartCredentialResolverTest.php` >= 4
    - `php artisan test --compact --filter=HubSnelstartCredentialResolverTest` exit 0, 4 tests passed
    - **Geen** wijziging in `app/Providers/AppServiceProvider.php` of `packages/snelstart-api/**` (SDK-grens-invariant)
  </acceptance_criteria>
  <done>Resolver-class bestaat, implementeert SDK-contract, 4 tests groen, geen lekken naar SDK-package of globaal ServiceProvider.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Hub-Connection (encrypted DB rij) → SDK-call (cleartext op runtime) | De resolver decrypteert credentials; ze leven in geheugen tot end-of-request en mogen niet naar logs/exception-messages of audit-tabel lekken |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05b-05 | Information disclosure | `HubSnelstartCredentialResolver::resolve()` returnt cleartext DTO | mitigate | Returnt `SnelstartCredentials` (final readonly DTO) die alleen via expliciete property-access leakable is. Geen `__toString()`, geen logging. SDK consumeert de DTO direct in de Authenticator — verlaat de Hub-laag niet. |
| T-05b-06 | Information disclosure | Resolver binding lekt naar volgende request | mitigate | Plan 05 bindt `app()->instance(...)` per-request in `ResolveSnelstartAccount`-middleware; geen `singleton()` in ServiceProvider. Per-request bindings worden bij request-einde gegarbage-collect. Acceptance van Plan 05 controleert dit. |
| T-05b-07 | Spoofing | Verkeerde Connection in resolver-constructor | accept | Middleware in Plan 05 is enige caller; cross-Consumer-scope is in middleware afgedwongen (404). Resolver zelf vertrouwt zijn input — single-responsibility-principe. |
</threat_model>

<verification>
- `HubSnelstartCredentialResolverTest` 4 tests groen
- Bestaande Phase-3-tests groen (`php artisan test --compact` zonder regressies)
- Pint clean
- Geen wijziging onder `packages/snelstart-api/` (SDK-grens)
</verification>

<success_criteria>
- `app/Services/Snelstart/HubSnelstartCredentialResolver.php` bestaat en implementeert het SDK-contract
- 4 tests bewijzen: happy-path-decryption, contract-conformance, fingerprint-determinisme, missing-credential-rejection
- Plan 05 kan in zijn `ResolveSnelstartAccount`-middleware schrijven:
  `app()->instance(SnelstartCredentialResolver::class, new HubSnelstartCredentialResolver($connection))`
  zonder verdere infrastructuur
</success_criteria>

<output>
Na completion: `.planning/phases/05b-snelstart-pass-through-api/05b-02-SUMMARY.md` per template. Notitie naar Plan 05: importeer `App\Services\Snelstart\HubSnelstartCredentialResolver` en `Emeq\SnelstartApi\Contracts\SnelstartCredentialResolver` in de middleware-stub.
</output>
