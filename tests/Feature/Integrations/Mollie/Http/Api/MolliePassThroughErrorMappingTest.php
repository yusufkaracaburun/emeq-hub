<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Mollie\Http\Api;

use App\Sanctum\TokenAbilities;
use Emeq\MollieApi\Exceptions\AuthenticationException;
use Emeq\MollieApi\Exceptions\MollieException;
use Emeq\MollieApi\Exceptions\NotFoundException;
use Emeq\MollieApi\Exceptions\RateLimitException;
use Emeq\MollieApi\Exceptions\ServerException;
use Emeq\MollieApi\Exceptions\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\Integrations\Mollie\Concerns\StubsMollieClient;
use Tests\TestCase;

class MolliePassThroughErrorMappingTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'description' => 'Error-mapping-test',
            'amount' => ['currency' => 'EUR', 'value' => '1.00'],
        ];
    }

    public function test_authentication_exception_maps_to_502_mollie_auth_failed(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);
        $this->bindMollieStub(fn () => new AuthenticationException('upstream auth failure'));

        $response = $this->callMollie($token, 'POST', '/v1/mollie/payments', $this->payload());

        $response->assertStatus(502)
            ->assertJsonPath('error', 'mollie_auth_failed');

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'status' => 502,
            'upstream_error' => 'mollie_auth',
        ]);
    }

    public function test_not_found_exception_maps_to_404_not_found_with_null_upstream_error(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_READ]);
        $this->bindMollieStub(fn () => new NotFoundException('tr_missing not found'));

        $response = $this->callMollie($token, 'GET', '/v1/mollie/payments/tr_missing');

        $response->assertStatus(404)
            ->assertJsonPath('error', 'not_found');

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'status' => 404,
            'upstream_error' => null,
        ]);
    }

    public function test_validation_exception_maps_to_422_validation_failed(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);
        $this->bindMollieStub(fn () => new ValidationException(
            message: 'amount.value is invalid',
            field: 'amount.value',
        ));

        $response = $this->callMollie($token, 'POST', '/v1/mollie/payments', $this->payload());

        $response->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonPath('field', 'amount.value');

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'status' => 422,
            'upstream_error' => null,
        ]);
    }

    public function test_rate_limit_exception_maps_to_429_rate_limited(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);
        $this->bindMollieStub(fn () => new RateLimitException('too many requests'));

        $response = $this->callMollie($token, 'POST', '/v1/mollie/payments', $this->payload());

        $response->assertStatus(429)
            ->assertJsonPath('error', 'rate_limited');

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'status' => 429,
            'upstream_error' => null,
        ]);
    }

    public function test_server_exception_maps_to_502_mollie_unavailable(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);
        $this->bindMollieStub(fn () => new ServerException('mollie 503'));

        $response = $this->callMollie($token, 'POST', '/v1/mollie/payments', $this->payload());

        $response->assertStatus(502)
            ->assertJsonPath('error', 'mollie_unavailable');

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'status' => 502,
            'upstream_error' => 'mollie_5xx',
        ]);
    }

    public function test_unexpected_runtime_exception_maps_to_502_mollie_error(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);
        $this->bindMollieStub(fn () => new RuntimeException('unexpected boom'));

        $response = $this->callMollie($token, 'POST', '/v1/mollie/payments', $this->payload());

        $response->assertStatus(502)
            ->assertJsonPath('error', 'mollie_error');

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'status' => 502,
            'upstream_error' => 'mollie_unknown',
        ]);
    }

    public function test_mollie_exception_base_maps_to_502_mollie_error(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);
        $this->bindMollieStub(fn () => new MollieException('base exception fallback'));

        $response = $this->callMollie($token, 'POST', '/v1/mollie/payments', $this->payload());

        $response->assertStatus(502)
            ->assertJsonPath('error', 'mollie_error');
    }
}
