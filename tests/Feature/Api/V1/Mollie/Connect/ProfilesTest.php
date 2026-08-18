<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mollie\Connect;

use App\Sanctum\TokenAbilities;
use Emeq\MollieApi\Exceptions\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mollie\Api\Resources\Profile;
use Mollie\Api\Resources\ProfileCollection;
use Tests\Concerns\StubsMollieConnectClient;
use Tests\TestCase;

class ProfilesTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieConnectClient;

    public function test_get_profiles_returns_paginated_collection(): void
    {
        $this->setPartnerToken('access_partner_prof_001');
        [, $token] = $this->setupMollieConnectConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieConnectStubs([
            'profiles' => fn (string $op) => $this->makeMollieCollectionWithBody(
                ProfileCollection::class,
                [
                    'count' => 1,
                    '_embedded' => [
                        'profiles' => [
                            ['resource' => 'profile', 'id' => 'pfl_listed', 'name' => 'Emeq Live', 'website' => 'https://emeq.test'],
                        ],
                    ],
                ],
                $this->mollieConnectClient,
            ),
        ]);

        $response = $this->callMollieConnect($token, 'GET', '/v1/mollie/connect/profiles');

        $response->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('_embedded.profiles.0.id', 'pfl_listed');

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'GET',
            'path' => '/v2/profiles',
            'status' => 200,
            'token_type' => 'partner',
            'connection_id' => null,
            'account_id' => null,
        ]);
    }

    public function test_post_profile_creates_resource_returns_201(): void
    {
        $this->setPartnerToken('access_partner_prof_002');
        [, $token] = $this->setupMollieConnectConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieConnectStubs([
            'profiles' => fn (string $op, mixed $arg) => $this->makeMollieResourceWithBody(
                Profile::class,
                [
                    'resource' => 'profile',
                    'id' => 'pfl_test',
                    'name' => is_array($arg) ? ($arg['name'] ?? null) : null,
                    'website' => is_array($arg) ? ($arg['website'] ?? null) : null,
                ],
                $this->mollieConnectClient,
            ),
        ]);

        $payload = [
            'name' => 'Emeq Test',
            'website' => 'https://emeq.test',
            'email' => 'info@emeq.test',
            'phone' => '+31201234567',
        ];

        $response = $this->callMollieConnect($token, 'POST', '/v1/mollie/connect/profiles', $payload);

        $response->assertCreated()
            ->assertJsonPath('id', 'pfl_test')
            ->assertJsonPath('name', 'Emeq Test');

        $this->assertCount(1, $this->mollieConnectCaptured['profile_create']);
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'POST',
            'path' => '/v2/profiles',
            'status' => 201,
            'token_type' => 'partner',
        ]);
    }

    public function test_get_profile_by_id_returns_resource(): void
    {
        $this->setPartnerToken('access_partner_prof_003');
        [, $token] = $this->setupMollieConnectConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieConnectStubs([
            'profiles' => fn (string $op, mixed $arg) => $this->makeMollieResourceWithBody(
                Profile::class,
                [
                    'resource' => 'profile',
                    'id' => is_string($arg) ? $arg : 'unknown',
                    'name' => 'Persisted Profile',
                ],
                $this->mollieConnectClient,
            ),
        ]);

        $response = $this->callMollieConnect($token, 'GET', '/v1/mollie/connect/profiles/pfl_test');

        $response->assertOk()
            ->assertJsonPath('id', 'pfl_test')
            ->assertJsonPath('name', 'Persisted Profile');

        $this->assertSame(['pfl_test'], $this->mollieConnectCaptured['profile_get']);
        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'mollie',
            'method' => 'GET',
            'path' => '/v2/profiles/{id}',
            'status' => 200,
            'token_type' => 'partner',
        ]);
    }

    public function test_post_profile_with_missing_required_fields_returns_422(): void
    {
        [, $token] = $this->setupMollieConnectConsumer([TokenAbilities::MOLLIE_WRITE]);

        $response = $this->callMollieConnect($token, 'POST', '/v1/mollie/connect/profiles', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'website', 'email', 'phone']);
    }

    public function test_get_profiles_with_auth_failure_maps_to_502_mollie_auth_failed(): void
    {
        $this->setPartnerToken('access_partner_prof_004');
        [, $token] = $this->setupMollieConnectConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieConnectStubs([
            'profiles' => fn () => new AuthenticationException('upstream auth failure'),
        ]);

        $response = $this->callMollieConnect($token, 'GET', '/v1/mollie/connect/profiles');

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
