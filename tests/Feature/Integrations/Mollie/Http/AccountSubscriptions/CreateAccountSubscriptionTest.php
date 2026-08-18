<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Mollie\Http\AccountSubscriptions;

use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Emeq\MollieApi\Exceptions\ValidationException as EmeqMollieValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Integrations\Mollie\Concerns\StubsMollieClient;
use Tests\TestCase;

class CreateAccountSubscriptionTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    public function test_happy_path_creates_subscription_and_returns_201_with_resource_shape(): void
    {
        [, $token, $account, $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => $this->makeSubscription([
                'id' => 'sub_new',
                'status' => 'active',
                'customerId' => $arg['customer_id'],
            ]),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/account-subscriptions', $this->validBody());

        $response->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.mollie_subscription_id', 'sub_new')
            ->assertJsonPath('data.mollie_customer_id', 'cst_abc');

        $this->assertDatabaseHas('account_subscriptions', [
            'account_id' => $account->id,
            'connection_id' => $connection->id,
            'mollie_subscription_id' => 'sub_new',
            'mollie_customer_id' => 'cst_abc',
            'status' => 'active',
        ]);
    }

    public function test_mollie_create_call_uses_correct_payload(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => $this->makeSubscription([
                'id' => 'sub_payload',
                'status' => 'active',
                'customerId' => $arg['customer_id'],
            ]),
        ]);

        $body = $this->validBody([
            'mollie_mandate_id' => 'mdt_xyz456',
            'times' => 12,
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/account-subscriptions', $body)
            ->assertCreated();

        $captured = $this->mollieCaptured['subscription_create_for_id'];
        $this->assertCount(1, $captured);
        $this->assertSame('cst_abc', $captured[0]['customer_id']);

        $payload = $captured[0]['payload'];
        $this->assertSame(['currency' => 'EUR', 'value' => '10.00'], $payload['amount']);
        $this->assertSame('1 month', $payload['interval']);
        $this->assertSame('Maandelijkse bijdrage Pieter Janssen 2026', $payload['description']);
        $this->assertSame('mdt_xyz456', $payload['mandateId']);
        $this->assertSame(12, $payload['times']);
    }

    public function test_read_only_token_returns_403_on_create(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_READ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/account-subscriptions', $this->validBody())
            ->assertForbidden();
    }

    public function test_missing_required_field_returns_422(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $body = $this->validBody();
        unset($body['amount']['value']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/account-subscriptions', $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount.value']);
    }

    public function test_invalid_amount_format_returns_422(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $body = $this->validBody(['amount' => ['currency' => 'EUR', 'value' => '10']]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/account-subscriptions', $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount.value']);
    }

    public function test_account_external_id_of_other_consumer_returns_422(): void
    {
        [, $tokenA] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE], 'school-A');

        $consumerB = Consumer::factory()->create();
        $accountB = Account::factory()->for($consumerB)->create(['external_id' => 'school-B']);
        Connection::factory()->forMollie()->active()->for($accountB)->create();

        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson('/v1/account-subscriptions', $this->validBody(['account_external_id' => 'school-B']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['account_external_id']);

        $this->assertSame(0, AccountSubscription::query()->count(), 'Geen sub mag aangemaakt zijn.');
    }

    public function test_mollie_validation_error_propagates_as_422_via_error_mapper(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => new EmeqMollieValidationException(
                message: 'amount.value is invalid',
                field: 'amount.value',
                code: 422,
            ),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/account-subscriptions', $this->validBody())
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonPath('field', 'amount.value')
            ->assertJsonPath('upstream_status', 422);
    }

    public function test_idempotency_key_forwarded_to_mollie_client(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => $this->makeSubscription([
                'id' => 'sub_idem',
                'status' => 'active',
                'customerId' => $arg['customer_id'],
            ]),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Idempotency-Key', 'uuid-test-01HXXX')
            ->postJson('/v1/account-subscriptions', $this->validBody())
            ->assertCreated();

        $captured = $this->mollieCaptured['idempotency_keys'];
        $this->assertNotEmpty($captured, 'Verwacht minstens één captured idempotency-key.');
        $this->assertSame('uuid-test-01HXXX', end($captured));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validBody(array $overrides = []): array
    {
        return array_merge([
            'account_external_id' => 'school-A',
            'mollie_customer_id' => 'cst_abc',
            'amount' => ['currency' => 'EUR', 'value' => '10.00'],
            'interval' => '1 month',
            'description' => 'Maandelijkse bijdrage Pieter Janssen 2026',
        ], $overrides);
    }
}
