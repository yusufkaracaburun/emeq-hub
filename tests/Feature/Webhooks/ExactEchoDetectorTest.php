<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Integrations\Exact\Webhooks\ExactEchoDetector;
use App\Models\Account;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Models\Consumer;
use App\Models\ProviderEntityLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExactEchoDetectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_recognises_an_entity_the_hub_itself_wrote(): void
    {
        $connection = $this->exactConnection();
        ProviderEntityLink::factory()->create([
            'connection_id' => $connection->id,
            'provider_entity_id' => 'guid-hub',
            'origin' => ProviderEntityLink::ORIGIN_HUB,
        ]);

        $this->assertTrue($this->detect($connection, 'guid-hub'));
    }

    public function test_it_does_not_flag_an_entity_that_originated_at_the_provider(): void
    {
        $connection = $this->exactConnection();
        ProviderEntityLink::factory()->create([
            'connection_id' => $connection->id,
            'provider_entity_id' => 'guid-theirs',
            'origin' => ProviderEntityLink::ORIGIN_PROVIDER,
        ]);

        $this->assertFalse($this->detect($connection, 'guid-theirs'));
    }

    public function test_the_same_notification_is_only_an_echo_for_the_connection_that_caused_it(): void
    {
        $mine = $this->exactConnection();
        $theirs = $this->exactConnection();

        ProviderEntityLink::factory()->create([
            'connection_id' => $mine->id,
            'provider_entity_id' => 'guid-hub',
            'origin' => ProviderEntityLink::ORIGIN_HUB,
        ]);

        $this->assertTrue($this->detect($mine, 'guid-hub'));
        $this->assertFalse($this->detect($theirs, 'guid-hub'));
    }

    public function test_it_recognises_a_relation_the_hub_created(): void
    {
        $connection = $this->exactConnection();
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->id,
            'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 'party-1',
            'native_id' => 'guid-relation',
            'attrs' => ['created_by_hub' => true],
        ]);

        $this->assertTrue($this->detect($connection, 'guid-relation'));
    }

    public function test_it_does_not_flag_a_relation_that_was_only_learned(): void
    {
        $connection = $this->exactConnection();
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->id,
            'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 'party-2',
            'native_id' => 'guid-learned',
            'attrs' => [],
        ]);

        $this->assertFalse($this->detect($connection, 'guid-learned'));
    }

    public function test_it_returns_false_without_a_usable_key(): void
    {
        $connection = $this->exactConnection();

        $this->assertFalse((new ExactEchoDetector)->causedByHub($connection, ['Content' => []]));
        $this->assertFalse((new ExactEchoDetector)->causedByHub($connection, []));
    }

    private function detect(Connection $connection, string $key): bool
    {
        return (new ExactEchoDetector)->causedByHub($connection, ['Content' => ['Key' => $key]]);
    }

    private function exactConnection(): Connection
    {
        $account = Account::factory()->for(Consumer::factory()->create())->create();

        return Connection::factory()->forExact()->active()->for($account)->create();
    }
}
