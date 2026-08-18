<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mollie\Connect;

use App\Sanctum\TokenAbilities;
use Emeq\MollieApi\Exceptions\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mollie\Api\Resources\Onboarding;
use Tests\Concerns\StubsMollieConnectClient;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieConnectClient;

    public function test_get_onboarding_me_returns_resource_and_writes_partner_audit_row(): void
    {
        $this->setPartnerToken('access_partner_onboarding_001');
        [, $token] = $this->setupMollieConnectConsumer([TokenAbilities::MOLLIE_READ]);

        $stub = $this->bindMollieConnectStubs([
            'onboarding' => fn () => $this->makeMollieResourceWithBody(
                Onboarding::class,
                [
                    'resource' => 'onboarding',
                    'name' => 'Emeq B.V.',
                    'status' => 'completed',
                    'canReceivePayments' => true,
                    'canReceiveSettlements' => true,
                    'signedUpAt' => '2026-05-18T12:00:00+00:00',
                ],
                $this->mollieConnectClient,
            ),
        ]);

        $response = $this->callMollieConnect($token, 'GET', '/v1/mollie/connect/onboarding/me');

        $response->assertOk()
            ->assertJsonPath('name', 'Emeq B.V.')
            ->assertJsonPath('status', 'completed');

        $this->assertSame('access_partner_onboarding_001', $stub->lastUsedAccessToken);

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'GET',
            'path' => '/v2/onboarding/me',
            'status' => 200,
            'token_type' => 'partner',
            'connection_id' => null,
            'account_id' => null,
            'partner_token_fingerprint' => substr(hash('sha256', 'access_partner_onboarding_001'), 0, 12),
        ]);
    }

    public function test_get_onboarding_with_auth_failure_maps_to_502_mollie_auth_failed(): void
    {
        $this->setPartnerToken('access_partner_onboarding_002');
        [, $token] = $this->setupMollieConnectConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieConnectStubs([
            'onboarding' => fn () => new AuthenticationException('upstream auth failure'),
        ]);

        $response = $this->callMollieConnect($token, 'GET', '/v1/mollie/connect/onboarding/me');

        $response->assertStatus(502)
            ->assertJsonPath('error', 'mollie_auth_failed')
            ->assertJsonPath('upstream_status', 401);

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'status' => 502,
            'upstream_error' => 'mollie_auth',
            'token_type' => 'partner',
        ]);
    }
}
