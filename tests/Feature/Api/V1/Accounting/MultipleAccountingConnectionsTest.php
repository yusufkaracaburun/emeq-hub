<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Accounting;

use App\Accounting\AccountingResult;
use App\Accounting\AccountingTargetRegistry;
use App\Accounting\Contracts\AccountingTarget;
use App\Accounting\FinancialDocument;
use App\Enums\Provider;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MultipleAccountingConnectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccountingTargetRegistry::class)->register(
            Provider::Snelstart->value,
            SecondAccountingTarget::class,
        );
    }

    public function test_two_connections_without_a_choice_is_refused_instead_of_guessed(): void
    {
        $consumer = $this->consumerWithTwoConnections();

        $response = $this->fetch($consumer, 'capabilities')
            ->assertStatus(409)
            ->assertJsonPath('error', 'multiple_accounting_connections');

        $this->assertSame(
            ['exact', 'snelstart'],
            array_column($response->json('connections'), 'provider'),
        );
        foreach ($response->json('connections') as $connection) {
            $this->assertStringStartsWith('con_', $connection['connection_id']);
        }
    }

    public function test_the_connection_header_selects_the_connection(): void
    {
        $consumer = $this->consumerWithTwoConnections();
        $account = $consumer->accounts()->sole();

        foreach (['exact', 'snelstart'] as $provider) {
            $connection = $account->connections()->where('provider', $provider)->sole();

            $this->fetch($consumer, 'capabilities', $connection->public_id)
                ->assertOk()
                ->assertJsonPath('provider', $provider);
        }
    }

    public function test_a_connection_id_this_account_does_not_have_is_a_404(): void
    {
        $consumer = $this->consumerWithTwoConnections();

        $this->fetch($consumer, 'capabilities', 'con_NIETVANDITACCOUNT')
            ->assertStatus(404)
            ->assertJsonPath('error', 'connection_not_found');
    }

    public function test_a_connection_id_of_another_consumer_is_not_reachable(): void
    {
        $consumer = $this->consumerWithTwoConnections();

        $other = Consumer::factory()->create();
        $otherAccount = $other->accounts()->create(['external_id' => 'other', 'display_name' => 'Other']);
        $otherConnection = Connection::factory()->forExact()->for($otherAccount)->create(['status' => 'active']);

        $this->fetch($consumer, 'capabilities', (string) $otherConnection->public_id)
            ->assertStatus(404)
            ->assertJsonPath('error', 'connection_not_found');
    }

    public function test_a_single_connection_still_resolves_without_the_header(): void
    {
        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create(['external_id' => 'school1', 'display_name' => 'S1']);
        Connection::factory()->forExact()->for($account)->create(['status' => 'active']);

        $this->fetch($consumer, 'capabilities')
            ->assertOk()
            ->assertJsonPath('provider', 'exact');
    }

    private function consumerWithTwoConnections(): Consumer
    {
        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create(['external_id' => 'school1', 'display_name' => 'S1']);

        Connection::factory()->forExact()->for($account)->create(['status' => 'active']);
        Connection::factory()->for($account)->create([
            'provider' => Provider::Snelstart->value,
            'status' => 'active',
        ]);

        return $consumer;
    }

    private function fetch(Consumer $consumer, string $path, ?string $connectionId = null): TestResponse
    {
        $token = $consumer->createToken('t', [TokenAbilities::ACCOUNTING_READ])->plainTextToken;

        $request = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1');

        if ($connectionId !== null) {
            $request = $request->withHeader('X-Connection-Id', $connectionId);
        }

        return $request->getJson('/v1/accounting/'.$path);
    }
}

final class SecondAccountingTarget implements AccountingTarget
{
    public function push(FinancialDocument $document, Connection $connection): AccountingResult
    {
        return new AccountingResult(status: 201, externalRef: 'second-1', externalNumber: null, raw: [], attachments: []);
    }
}
