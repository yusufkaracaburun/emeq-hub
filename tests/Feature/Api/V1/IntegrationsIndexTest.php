<?php

namespace Tests\Feature\Api\V1;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationsIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_all_showcase_providers_disconnected_without_account(): void
    {
        [, $token] = $this->consumerWithToken([TokenAbilities::INTEGRATIONS_MANAGE]);

        $items = collect($this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/integrations')
            ->assertOk()
            ->json());

        $this->assertEqualsCanonicalizing(['exact', 'mollie'], $items->pluck('key')->all());
        $this->assertTrue($items->every(fn (array $i): bool => $i['status'] === 'disconnected'));
        $this->assertTrue($items->firstWhere('key', 'exact')['connectable']);
        $this->assertTrue($items->firstWhere('key', 'mollie')['connectable']);
    }

    public function test_merges_connection_status_for_given_account(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::INTEGRATIONS_MANAGE]);
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school1']);
        Connection::factory()->forMollie()->active()->for($account)->create();
        Connection::factory()->forExact()->for($account)->create(['status' => 'pending']);

        $items = collect($this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/integrations?account_external_id=school1')
            ->assertOk()
            ->json());

        $mollie = $items->firstWhere('key', 'mollie');
        $this->assertSame('connected', $mollie['status']);
        $this->assertNotNull($mollie['connection_id']);
        $this->assertSame('pending', $items->firstWhere('key', 'exact')['status']);
    }

    public function test_revoked_connection_reports_disconnected_and_null_id(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::INTEGRATIONS_MANAGE]);
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school1']);
        Connection::factory()->forExact()->for($account)->create([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);

        $exact = collect($this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/integrations?account_external_id=school1')
            ->assertOk()
            ->json())->firstWhere('key', 'exact');

        $this->assertSame('disconnected', $exact['status']);
        $this->assertNull($exact['connection_id']);
    }

    public function test_kill_switched_provider_is_listed_but_not_connectable(): void
    {
        $this->disableProvider('mollie');
        [, $token] = $this->consumerWithToken([TokenAbilities::INTEGRATIONS_MANAGE]);

        $mollie = collect($this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/integrations')
            ->assertOk()
            ->json())->firstWhere('key', 'mollie');

        $this->assertFalse($mollie['connectable']);
    }

    public function test_unknown_account_returns_all_disconnected(): void
    {
        [, $token] = $this->consumerWithToken([TokenAbilities::INTEGRATIONS_MANAGE]);

        $items = collect($this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/integrations?account_external_id=does-not-exist')
            ->assertOk()
            ->json());

        $this->assertTrue($items->every(fn (array $i): bool => $i['status'] === 'disconnected'));
    }

    public function test_consumer_manage_accounts_ability_also_authorizes(): void
    {
        [, $token] = $this->consumerWithToken([TokenAbilities::CONSUMER_MANAGE_ACCOUNTS]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/integrations')
            ->assertOk();
    }

    public function test_without_required_ability_returns_403(): void
    {
        [, $token] = $this->consumerWithToken([TokenAbilities::MOLLIE_READ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/integrations')
            ->assertForbidden();
    }

    public function test_does_not_leak_other_consumers_connection_status(): void
    {
        $consumerB = Consumer::factory()->create();
        $accountB = Account::factory()->for($consumerB)->create(['external_id' => 'shared-key']);
        Connection::factory()->forMollie()->active()->for($accountB)->create();

        [, $tokenA] = $this->consumerWithToken([TokenAbilities::INTEGRATIONS_MANAGE]);

        $mollie = collect($this->withHeader('Authorization', "Bearer {$tokenA}")
            ->getJson('/v1/integrations?account_external_id=shared-key')
            ->assertOk()
            ->json())->firstWhere('key', 'mollie');

        $this->assertSame('disconnected', $mollie['status']);
        $this->assertNull($mollie['connection_id']);
    }

    public function test_response_contains_no_raw_tokens(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::INTEGRATIONS_MANAGE]);
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school1']);
        Connection::factory()->forExact()->active()->for($account)->create();

        $body = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/integrations?account_external_id=school1')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('access_token', (string) $body);
        $this->assertStringNotContainsString('refresh_token', (string) $body);
        $this->assertStringNotContainsString('client_key', (string) $body);
    }

    /**
     * @param  list<string>  $abilities
     * @return array{0: Consumer, 1: string}
     */
    private function consumerWithToken(array $abilities): array
    {
        $consumer = Consumer::factory()->create();
        $token = $consumer->createToken('test', $abilities)->plainTextToken;

        return [$consumer, $token];
    }
}
