<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mollie\Connect;

use App\Integrations\Mollie\MollieAccessTokenResolver;
use App\Sanctum\TokenAbilities;
use Emeq\MollieApi\Mollie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Resources\PermissionCollection;
use Tests\Concerns\StubsMollieClient;
use Tests\Concerns\StubsMollieConnectClient;
use Tests\Feature\Api\V1\Mollie\StubMollieClient;
use Tests\TestCase;

class TokenResolverIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;
    use StubsMollieConnectClient;

    public function test_merchant_route_uses_connection_access_token(): void
    {
        [, $token, $account, $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE], 'acc_test_merchant');
        $expectedToken = $connection->access_token;
        $this->assertIsString($expectedToken);

        $paymentsStub = new class
        {
            public function create(array $payload): Payment
            {
                $payment = new Payment(new MollieApiClient);
                $payment->id = 'tr_test_merchant';
                $payment->status = 'open';
                $payment->amount = (object) ['value' => '10.00', 'currency' => 'EUR'];

                return $payment;
            }
        };

        $stub = new StubMollieClient($paymentsStub);

        $mollie = $this->createMock(Mollie::class);
        $mollie->method('client')->willReturnCallback(function () use ($stub, $expectedToken): StubMollieClient {
            $stub->setAccessToken($expectedToken);

            return $stub;
        });
        $this->app->instance(Mollie::class, $mollie);

        $response = $this->callMollie(
            $token,
            'POST',
            '/v1/mollie/payments',
            [
                'amount' => ['value' => '10.00', 'currency' => 'EUR'],
                'description' => 'integration-test',
                'redirectUrl' => 'https://example.test/return',
            ],
            [],
            $account->external_id,
        );

        $response->assertCreated();

        $this->assertNotNull($stub->lastUsedAccessToken, 'Merchant-route moet via setAccessToken lopen op de stub.');
        $this->assertSame(
            $expectedToken,
            $stub->lastUsedAccessToken,
            'Merchant-route moet de Connection.access_token gebruiken, niet de partner-env-var.',
        );
    }

    public function test_connect_route_uses_partner_access_token(): void
    {
        $this->setPartnerToken('access_partner_xyz');
        [, $token] = $this->setupMollieConnectConsumer([TokenAbilities::MOLLIE_READ]);

        $stub = $this->bindMollieConnectStubs([
            'permissions' => fn (string $op) => $this->makeMollieCollectionWithBody(
                PermissionCollection::class,
                [
                    'count' => 1,
                    '_embedded' => [
                        'permissions' => [
                            ['resource' => 'permission', 'id' => 'payments.read', 'granted' => true],
                        ],
                    ],
                ],
                $this->mollieConnectClient,
            ),
        ]);

        $response = $this->callMollieConnect($token, 'GET', '/v1/mollie/connect/permissions');

        $response->assertOk();

        $this->assertSame(
            'access_partner_xyz',
            $stub->lastUsedAccessToken,
            'Connect-route moet de partner-env-var-token gebruiken, niet een Connection-token.',
        );

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'path' => '/v2/permissions',
            'status' => 200,
            'token_type' => 'partner',
            'connection_id' => null,
            'account_id' => null,
        ]);
    }

    public function test_connect_route_with_missing_partner_token_returns_503_partner_token_missing(): void
    {
        config(['services.mollie.partner_access_token' => null]);
        $this->app->forgetInstance(MollieAccessTokenResolver::class);

        [, $token] = $this->setupMollieConnectConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieConnectStubs([
            'permissions' => fn () => null,
        ]);

        $response = $this->callMollieConnect($token, 'GET', '/v1/mollie/connect/permissions');

        $response->assertStatus(503)
            ->assertJsonPath('error', 'partner_token_missing');

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'status' => 503,
            'upstream_error' => 'partner_token_missing',
            'token_type' => 'partner',
        ]);
    }
}
