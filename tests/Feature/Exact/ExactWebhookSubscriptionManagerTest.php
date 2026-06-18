<?php

declare(strict_types=1);

namespace Tests\Feature\Exact;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Services\Exact\ExactWebhookSubscriptionManager;
use Emeq\ExactApi\Http\Request\Delete\DeleteWebhookSubscription;
use Emeq\ExactApi\Http\Request\Read\ListWebhookSubscriptions;
use Emeq\ExactApi\Http\Request\Write\CreateWebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

class ExactWebhookSubscriptionManagerTest extends TestCase
{
    use RefreshDatabase;

    private const TOPICS = ['FinancialTransactions', 'Documents'];

    protected function setUp(): void
    {
        parent::setUp();

        MockClient::destroyGlobal();
        config([
            'services.exact.client_id' => 'app_test_id',
            'services.exact.client_secret' => 'app_test_secret',
            'services.exact.redirect_uri' => 'https://hub.test/v1/oauth/exact/callback',
            'services.exact.webhook_topics' => self::TOPICS,
        ]);
    }

    protected function tearDown(): void
    {
        MockClient::destroyGlobal();

        parent::tearDown();
    }

    public function test_register_creates_a_subscription_per_topic_and_stores_ids(): void
    {
        MockClient::global([
            ListWebhookSubscriptions::class => MockResponse::make(['d' => ['results' => []]], 200),
            CreateWebhookSubscription::class => MockResponse::make(['d' => ['ID' => 'sub-new']], 201),
        ]);

        $connection = $this->exactConnection();

        $this->app->make(ExactWebhookSubscriptionManager::class)->register($connection);

        $connection->refresh();
        $stored = $connection->metadata['exact_webhooks'] ?? [];
        $this->assertSame(self::TOPICS, array_keys($stored));
        $this->assertSame('sub-new', $stored['FinancialTransactions']);

        MockClient::global()->assertSent(fn (CreateWebhookSubscription $request): bool => $request->body()->all()['CallbackURL'] === route('webhooks.exact'));
    }

    public function test_register_is_idempotent_and_skips_existing_topics(): void
    {
        MockClient::global([
            ListWebhookSubscriptions::class => MockResponse::make(['d' => ['results' => [
                ['Topic' => 'FinancialTransactions', 'ID' => 'sub-A'],
                ['Topic' => 'Documents', 'ID' => 'sub-B'],
            ]]], 200),
        ]);

        $connection = $this->exactConnection();

        $this->app->make(ExactWebhookSubscriptionManager::class)->register($connection);

        $connection->refresh();
        $this->assertSame(
            ['FinancialTransactions' => 'sub-A', 'Documents' => 'sub-B'],
            $connection->metadata['exact_webhooks'],
        );

        // Alle topics bestonden al → géén create-call.
        MockClient::global()->assertNotSent(CreateWebhookSubscription::class);
    }

    public function test_register_swallows_duplicate_data_already_exists(): void
    {
        MockClient::global([
            ListWebhookSubscriptions::class => MockResponse::make(['d' => ['results' => []]], 200),
            CreateWebhookSubscription::class => MockResponse::make(['message' => 'Data already exists'], 500),
        ]);

        $connection = $this->exactConnection();

        // Geen exception: duplicate is idempotent. Geen ID → niets opgeslagen.
        $this->app->make(ExactWebhookSubscriptionManager::class)->register($connection);

        $connection->refresh();
        $this->assertArrayNotHasKey('exact_webhooks', $connection->metadata ?? []);
    }

    public function test_unsubscribe_deletes_stored_subscriptions_and_clears_metadata(): void
    {
        MockClient::global([
            DeleteWebhookSubscription::class => MockResponse::make([], 204),
        ]);

        $connection = $this->exactConnection([
            'metadata' => ['exact_webhooks' => ['FinancialTransactions' => 'sub-A', 'Documents' => 'sub-B']],
        ]);

        $this->app->make(ExactWebhookSubscriptionManager::class)->unsubscribe($connection);

        $connection->refresh();
        $this->assertArrayNotHasKey('exact_webhooks', $connection->metadata ?? []);

        MockClient::global()->assertSentCount(2);
    }

    public function test_register_noops_without_configured_topics(): void
    {
        config(['services.exact.webhook_topics' => []]);
        MockClient::global([]);

        $connection = $this->exactConnection();

        $this->app->make(ExactWebhookSubscriptionManager::class)->register($connection);

        MockClient::global()->assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function exactConnection(array $state = []): Connection
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();

        return Connection::factory()->forExact()->active()->for($account)->create($state);
    }
}
