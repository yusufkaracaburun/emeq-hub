<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mollie\Connect;

use App\Sanctum\TokenAbilities;
use Emeq\MollieApi\Exceptions\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mollie\Api\Resources\Organization;
use Tests\Concerns\StubsMollieConnectClient;
use Tests\TestCase;

class OrganizationsTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieConnectClient;

    public function test_get_organizations_me_returns_resource(): void
    {
        $this->setPartnerToken('access_partner_org_001');
        [, $token] = $this->setupMollieConnectConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieConnectStubs([
            'organizations' => fn (string $op, mixed $arg) => $this->makeMollieResourceWithBody(
                Organization::class,
                [
                    'resource' => 'organization',
                    'id' => 'org_emeq',
                    'name' => 'Emeq B.V.',
                    'email' => 'info@emeq.test',
                ],
                $this->mollieConnectClient,
            ),
        ]);

        $response = $this->callMollieConnect($token, 'GET', '/v1/mollie/connect/organizations/me');

        $response->assertOk()
            ->assertJsonPath('id', 'org_emeq')
            ->assertJsonPath('name', 'Emeq B.V.');

        $this->assertSame(['me'], $this->mollieConnectCaptured['organization_get']);
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'GET',
            'path' => '/v2/organizations/me',
            'status' => 200,
            'token_type' => 'partner',
            'connection_id' => null,
            'account_id' => null,
        ]);
    }

    public function test_get_organization_by_id_returns_resource(): void
    {
        $this->setPartnerToken('access_partner_org_002');
        [, $token] = $this->setupMollieConnectConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieConnectStubs([
            'organizations' => fn (string $op, mixed $arg) => $this->makeMollieResourceWithBody(
                Organization::class,
                [
                    'resource' => 'organization',
                    'id' => is_string($arg) ? $arg : 'unknown',
                    'name' => 'Other Org',
                ],
                $this->mollieConnectClient,
            ),
        ]);

        $response = $this->callMollieConnect($token, 'GET', '/v1/mollie/connect/organizations/org_xyz');

        $response->assertOk()
            ->assertJsonPath('id', 'org_xyz')
            ->assertJsonPath('name', 'Other Org');

        $this->assertSame(['org_xyz'], $this->mollieConnectCaptured['organization_get']);
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'GET',
            'path' => '/v2/organizations/{id}',
            'status' => 200,
            'token_type' => 'partner',
        ]);
    }

    public function test_get_organization_with_auth_failure_maps_to_502_mollie_auth_failed(): void
    {
        $this->setPartnerToken('access_partner_org_003');
        [, $token] = $this->setupMollieConnectConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieConnectStubs([
            'organizations' => fn () => new AuthenticationException('upstream auth failure'),
        ]);

        $response = $this->callMollieConnect($token, 'GET', '/v1/mollie/connect/organizations/me');

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
