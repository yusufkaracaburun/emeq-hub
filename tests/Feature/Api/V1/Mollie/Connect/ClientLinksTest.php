<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mollie\Connect;

use App\Sanctum\TokenAbilities;
use Emeq\MollieApi\Exceptions\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mollie\Api\Resources\ClientLink;
use Tests\Concerns\StubsMollieConnectClient;
use Tests\TestCase;

/**
 * MOLL-05 SC-1 — ClientLinks partner-resource pass-through:
 *  - POST /v1/mollie/connect/client-links → 201 met _links.clientLink.href
 *  - Idempotency-Key auto-forward naar Mollie SDK
 *  - 401 upstream → 502 mollie_auth_failed (Hub-cloaked)
 *  - Form Request blokkeert invalid payloads vóór SDK-call (422)
 *  - Audit-rij met token_type=partner.
 */
class ClientLinksTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieConnectClient;

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'owner' => [
                'email' => 'merchant@example.test',
                'givenName' => 'Merchant',
                'familyName' => 'Owner',
            ],
            'name' => 'Acme B.V.',
            'address' => [
                'streetAndNumber' => 'Damrak 1',
                'postalCode' => '1012 LG',
                'city' => 'Amsterdam',
                'country' => 'NL',
            ],
        ];
    }

    public function test_post_client_link_creates_resource_and_returns_201_with_audit_row(): void
    {
        $this->setPartnerToken('access_partner_test_001');
        [, $token] = $this->setupMollieConnectConsumer([TokenAbilities::MOLLIE_WRITE]);

        $stub = $this->bindMollieConnectStubs([
            'clientLinks' => fn (string $op, mixed $payload) => $this->makeMollieResourceWithBody(
                ClientLink::class,
                [
                    'id' => 'cl_test',
                    'resource' => 'client-link',
                    '_links' => [
                        'clientLink' => ['href' => 'https://my.mollie.com/dashboard/client-link/cl_test'],
                    ],
                ],
                $this->mollieConnectClient,
            ),
        ]);

        $response = $this->callMollieConnect($token, 'POST', '/v1/mollie/connect/client-links', $this->validPayload());

        $response->assertCreated()
            ->assertJsonPath('id', 'cl_test')
            ->assertJsonPath('_links.clientLink.href', 'https://my.mollie.com/dashboard/client-link/cl_test');

        $this->assertSame('access_partner_test_001', $stub->lastUsedAccessToken);

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'POST',
            'path' => '/v2/client-links',
            'status' => 201,
            'token_type' => 'partner',
            'connection_id' => null,
            'account_id' => null,
            'partner_token_fingerprint' => substr(hash('sha256', 'access_partner_test_001'), 0, 12),
        ]);
    }

    public function test_post_client_link_with_idempotency_key_forwards_to_mollie_client(): void
    {
        $this->setPartnerToken('access_partner_test_idem');
        [, $token] = $this->setupMollieConnectConsumer([TokenAbilities::MOLLIE_WRITE]);

        $stub = $this->bindMollieConnectStubs([
            'clientLinks' => fn () => $this->makeMollieResourceWithBody(
                ClientLink::class,
                ['id' => 'cl_idem', 'resource' => 'client-link', '_links' => ['clientLink' => ['href' => 'https://my.mollie.com/x']]],
                $this->mollieConnectClient,
            ),
        ]);

        $response = $this->callMollieConnect(
            $token,
            'POST',
            '/v1/mollie/connect/client-links',
            $this->validPayload(),
            ['Idempotency-Key' => 'ck-test-xyz'],
        );

        $response->assertCreated();
        $this->assertSame('ck-test-xyz', $stub->lastIdempotencyKey);
    }

    public function test_post_client_link_with_mollie_auth_failure_maps_to_502_mollie_auth_failed(): void
    {
        $this->setPartnerToken('access_partner_test_auth');
        [, $token] = $this->setupMollieConnectConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieConnectStubs([
            'clientLinks' => fn () => new AuthenticationException('upstream auth failure'),
        ]);

        $response = $this->callMollieConnect($token, 'POST', '/v1/mollie/connect/client-links', $this->validPayload());

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

    public function test_post_client_link_with_invalid_payload_returns_422_validation_failed(): void
    {
        [, $token] = $this->setupMollieConnectConsumer([TokenAbilities::MOLLIE_WRITE]);

        // Form Request blokkeert vóór SDK-call → geen partner-token of stub nodig.
        $response = $this->callMollieConnect($token, 'POST', '/v1/mollie/connect/client-links', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['owner', 'name', 'address']);
    }
}
