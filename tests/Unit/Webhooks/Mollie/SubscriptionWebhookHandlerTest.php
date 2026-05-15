<?php

declare(strict_types=1);

namespace Tests\Unit\Webhooks\Mollie;

use App\Billing\Account\AccountSubscriptionManager;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\Connection;
use App\Models\Consumer;
use App\Mollie\MollieConnectionContext;
use App\Webhooks\Mollie\SubscriptionWebhookHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SubscriptionWebhookHandlerTest extends TestCase
{
    use RefreshDatabase;

    private AccountSubscriptionManager&MockInterface $manager;

    private MollieConnectionContext $context;

    private SubscriptionWebhookHandler $handler;

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = Mockery::mock(AccountSubscriptionManager::class);
        $this->context = new MollieConnectionContext;
        $this->handler = new SubscriptionWebhookHandler($this->context, $this->manager);

        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        $this->connection = Connection::factory()->forMollie()->active()->for($account)->create();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_handle_with_unknown_subscription_id_returns_skip_without_mollie_call(): void
    {
        // Geen AccountSubscription-rij voor 'sub_unknown' → handler moet skip
        // retourneren zonder ooit syncFromMollie aan te roepen (quota-burn).
        $this->manager->shouldNotReceive('syncFromMollie');

        $result = $this->handler->handle('sub_unknown', [], $this->connection);

        $this->assertSame('skip', $result->status);
        $this->assertSame('unknown_subscription', $result->reason);
        $this->assertFalse($result->isOk());
    }

    public function test_handle_with_matching_subscription_calls_manager_sync_from_mollie(): void
    {
        $sub = AccountSubscription::factory()
            ->forConnection($this->connection)
            ->active()
            ->create(['mollie_subscription_id' => 'sub_match']);

        $this->manager->shouldReceive('syncFromMollie')
            ->once()
            ->withArgs(fn (AccountSubscription $arg): bool => $arg->id === $sub->id);

        $result = $this->handler->handle('sub_match', [], $this->connection);

        $this->assertSame('ok', $result->status);
        $this->assertTrue($result->isOk());
        $this->assertSame($sub->id, $result->accountSubscriptionId);
    }
}
