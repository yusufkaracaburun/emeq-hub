<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Mollie\Http\Connect;

use App\Sanctum\TokenAbilities;
use Emeq\MollieApi\Exceptions\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mollie\Api\Resources\Permission;
use Mollie\Api\Resources\PermissionCollection;
use Tests\Feature\Integrations\Mollie\Concerns\StubsMollieConnectClient;
use Tests\TestCase;

class PermissionsTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieConnectClient;

    public function test_get_permissions_returns_collection_200(): void
    {
        $this->setPartnerToken('access_partner_perm_001');
        [, $token] = $this->setupMollieConnectConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieConnectStubs([
            'permissions' => fn (string $op) => $this->makeMollieCollectionWithBody(
                PermissionCollection::class,
                [
                    'count' => 2,
                    '_embedded' => [
                        'permissions' => [
                            ['resource' => 'permission', 'id' => 'payments.read', 'description' => 'View payments', 'granted' => true],
                            ['resource' => 'permission', 'id' => 'payments.write', 'description' => 'Create payments', 'granted' => false],
                        ],
                    ],
                ],
                $this->mollieConnectClient,
            ),
        ]);

        $response = $this->callMollieConnect($token, 'GET', '/v1/mollie/connect/permissions');

        $response->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('_embedded.permissions.0.id', 'payments.read')
            ->assertJsonPath('_embedded.permissions.1.granted', false);

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'GET',
            'path' => '/v2/permissions',
            'status' => 200,
            'token_type' => 'partner',
            'connection_id' => null,
            'account_id' => null,
        ]);
    }

    public function test_get_permission_by_id_returns_resource(): void
    {
        $this->setPartnerToken('access_partner_perm_002');
        [, $token] = $this->setupMollieConnectConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieConnectStubs([
            'permissions' => fn (string $op, mixed $arg) => $this->makeMollieResourceWithBody(
                Permission::class,
                [
                    'resource' => 'permission',
                    'id' => is_string($arg) ? $arg : 'unknown',
                    'description' => 'View payments',
                    'granted' => true,
                ],
                $this->mollieConnectClient,
            ),
        ]);

        $response = $this->callMollieConnect($token, 'GET', '/v1/mollie/connect/permissions/payments.read');

        $response->assertOk()
            ->assertJsonPath('id', 'payments.read')
            ->assertJsonPath('granted', true);

        $this->assertSame(['payments.read'], $this->mollieConnectCaptured['permission_get']);
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'GET',
            'path' => '/v2/permissions/{id}',
            'status' => 200,
            'token_type' => 'partner',
        ]);
    }

    public function test_get_permissions_with_auth_failure_maps_to_502_mollie_auth_failed(): void
    {
        $this->setPartnerToken('access_partner_perm_003');
        [, $token] = $this->setupMollieConnectConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieConnectStubs([
            'permissions' => fn () => new AuthenticationException('upstream auth failure'),
        ]);

        $response = $this->callMollieConnect($token, 'GET', '/v1/mollie/connect/permissions');

        $response->assertStatus(502)
            ->assertJsonPath('error', 'mollie_auth_failed');

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'status' => 502,
            'upstream_error' => 'mollie_auth',
            'token_type' => 'partner',
        ]);
    }
}
