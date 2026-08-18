<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Exact\Webhooks;

use App\Integrations\Exact\Webhooks\ExactEntityResolver;
use App\Integrations\Exact\Webhooks\ExactHubOriginDetector;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\ProviderEntityLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExactHubOriginDetectorTest extends TestCase
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
        ProviderEntityLink::factory()->create([
            'connection_id' => $connection->id,
            'entity_type' => ProviderEntityLink::ENTITY_RELATION,
            'provider_entity_id' => 'guid-relation',
            'origin' => ProviderEntityLink::ORIGIN_HUB,
        ]);

        $this->assertTrue($this->detect($connection, 'guid-relation'));
    }

    public function test_it_does_not_flag_a_relation_that_was_only_matched_at_the_provider(): void
    {
        $connection = $this->exactConnection();
        ProviderEntityLink::factory()->create([
            'connection_id' => $connection->id,
            'entity_type' => ProviderEntityLink::ENTITY_RELATION,
            'provider_entity_id' => 'guid-matched',
            'origin' => ProviderEntityLink::ORIGIN_PROVIDER,
        ]);

        $this->assertFalse($this->detect($connection, 'guid-matched'));
    }

    public function test_it_does_not_flag_a_relation_the_hub_never_touched(): void
    {
        $connection = $this->exactConnection();

        $this->assertFalse($this->detect($connection, 'guid-untouched'));
    }

    public function test_it_returns_false_without_a_usable_key(): void
    {
        $connection = $this->exactConnection();

        $this->assertFalse($this->detector()->hubAuthored($connection, ['Content' => []]));
        $this->assertFalse($this->detector()->hubAuthored($connection, []));
    }

    public function test_it_reports_when_the_hub_last_wrote_the_entity(): void
    {
        $connection = $this->exactConnection();
        ProviderEntityLink::factory()->create([
            'connection_id' => $connection->id,
            'provider_entity_id' => 'guid-hub',
            'origin' => ProviderEntityLink::ORIGIN_HUB,
            'last_synced_at' => '2026-08-01T10:00:00+00:00',
        ]);

        $at = $this->detector()->hubLastWroteAt($connection, ['Content' => ['Key' => 'guid-hub']]);

        $this->assertNotNull($at);
        $this->assertSame('2026-08-01T10:00:00+00:00', $at->toIso8601String());
    }

    public function test_it_returns_null_hub_last_wrote_at_for_an_entity_the_hub_never_wrote(): void
    {
        $connection = $this->exactConnection();
        ProviderEntityLink::factory()->create([
            'connection_id' => $connection->id,
            'provider_entity_id' => 'guid-theirs',
            'origin' => ProviderEntityLink::ORIGIN_PROVIDER,
        ]);

        $this->assertNull($this->detector()->hubLastWroteAt($connection, ['Content' => ['Key' => 'guid-theirs']]));
        $this->assertNull($this->detector()->hubLastWroteAt($connection, ['Content' => []]));
    }

    private function detect(Connection $connection, string $key): bool
    {
        return $this->detector()->hubAuthored($connection, ['Content' => ['Key' => $key]]);
    }

    private function detector(): ExactHubOriginDetector
    {
        return new ExactHubOriginDetector(new ExactEntityResolver);
    }

    private function exactConnection(): Connection
    {
        $account = Account::factory()->for(Consumer::factory()->create())->create();

        return Connection::factory()->forExact()->active()->for($account)->create();
    }
}
