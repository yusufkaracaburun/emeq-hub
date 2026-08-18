<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Exact\Console;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use Emeq\ExactApi\Http\Request\Delete\DeleteWebhookSubscription;
use Emeq\ExactApi\Http\Request\Read\ListWebhookSubscriptions;
use Emeq\ExactApi\Http\Request\Write\CreateWebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

class ExactWebhooksCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TOPICS = ['BankEntries', 'CashEntries'];

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

    public function test_status_succeeds_when_every_configured_topic_is_subscribed(): void
    {
        $this->mockRemote([
            ['Topic' => 'BankEntries', 'ID' => 'sub-A'],
            ['Topic' => 'CashEntries', 'ID' => 'sub-B'],
        ]);

        $this->artisan('exact:webhooks', ['connection' => $this->exactConnection()->id])
            ->expectsOutputToContain('BankEntries')
            ->expectsOutputToContain('https://hub.test/webhooks/exact')
            ->assertSuccessful();

        MockClient::global()->assertSentCount(1);
    }

    public function test_status_fails_when_a_configured_topic_is_missing_remotely(): void
    {
        $this->mockRemote([['Topic' => 'BankEntries', 'ID' => 'sub-A']]);

        $this->artisan('exact:webhooks', ['connection' => $this->exactConnection()->id])
            ->expectsOutputToContain('CashEntries')
            ->assertFailed();
    }

    public function test_status_reports_a_remote_topic_the_hub_does_not_configure(): void
    {
        $this->mockRemote([
            ['Topic' => 'BankEntries', 'ID' => 'sub-A'],
            ['Topic' => 'CashEntries', 'ID' => 'sub-B'],
            ['Topic' => 'GoodsDeliveries', 'ID' => 'sub-legacy'],
        ]);

        $this->artisan('exact:webhooks', ['connection' => $this->exactConnection()->id])
            ->expectsOutputToContain('GoodsDeliveries')
            ->assertSuccessful();
    }

    public function test_status_accepts_a_public_id_and_reports_stale_metadata(): void
    {
        $this->mockRemote([
            ['Topic' => 'BankEntries', 'ID' => 'sub-A'],
            ['Topic' => 'CashEntries', 'ID' => 'sub-B'],
        ]);

        $connection = $this->exactConnection([
            'metadata' => ['exact_webhooks' => ['GoodsDeliveries' => 'sub-gone']],
        ]);

        $this->artisan('exact:webhooks', ['connection' => $connection->public_id])
            ->expectsOutputToContain('sub-gone')
            ->assertSuccessful();
    }

    public function test_register_is_a_dry_run_without_force(): void
    {
        $this->mockRemote([]);

        $this->artisan('exact:webhooks', [
            'connection' => $this->exactConnection()->id,
            'action' => 'register',
        ])->expectsOutputToContain('DRY-RUN')->assertSuccessful();

        MockClient::global()->assertNotSent(CreateWebhookSubscription::class);
    }

    public function test_register_with_force_creates_only_the_missing_topic(): void
    {
        MockClient::global([
            ListWebhookSubscriptions::class => MockResponse::make(['d' => ['results' => [
                ['Topic' => 'BankEntries', 'ID' => 'sub-A'],
            ]]], 200),
            CreateWebhookSubscription::class => MockResponse::make(['d' => ['ID' => 'sub-new']], 201),
        ]);

        $connection = $this->exactConnection();

        $this->artisan('exact:webhooks', [
            'connection' => $connection->id,
            'action' => 'register',
            '--force' => true,
        ])->assertSuccessful();

        $connection->refresh();
        $this->assertSame(
            ['BankEntries' => 'sub-A', 'CashEntries' => 'sub-new'],
            $connection->metadata['exact_webhooks'],
        );

        MockClient::global()->assertSentCount(2);
    }

    public function test_register_surfaces_an_exact_scope_violation(): void
    {
        MockClient::global([
            ListWebhookSubscriptions::class => MockResponse::make(
                ['error' => ['message' => ['value' => "Forbidden - Application Scope Violated. Cannot read 'organization.administration' scope."]]],
                403,
            ),
        ]);

        $this->artisan('exact:webhooks', [
            'connection' => $this->exactConnection()->id,
            'action' => 'register',
            '--force' => true,
        ])->expectsOutputToContain('Application Scope Violated')->assertFailed();
    }

    public function test_unregister_is_a_dry_run_without_force(): void
    {
        $this->mockRemote([['Topic' => 'BankEntries', 'ID' => 'sub-A']]);

        $connection = $this->exactConnection([
            'metadata' => ['exact_webhooks' => ['BankEntries' => 'sub-A']],
        ]);

        $this->artisan('exact:webhooks', ['connection' => $connection->id, 'action' => 'unregister'])
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();

        MockClient::global()->assertNotSent(DeleteWebhookSubscription::class);
    }

    public function test_unregister_with_force_deletes_and_clears_metadata(): void
    {
        MockClient::global([
            ListWebhookSubscriptions::class => MockResponse::make(['d' => ['results' => [
                ['Topic' => 'BankEntries', 'ID' => 'sub-A'],
            ]]], 200),
            DeleteWebhookSubscription::class => MockResponse::make([], 204),
        ]);

        $connection = $this->exactConnection([
            'metadata' => ['exact_webhooks' => ['BankEntries' => 'sub-A']],
        ]);

        $this->artisan('exact:webhooks', [
            'connection' => $connection->id,
            'action' => 'unregister',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $connection->refresh();
        $this->assertArrayNotHasKey('exact_webhooks', $connection->metadata ?? []);
    }

    public function test_probe_reports_which_candidate_topics_exact_accepts(): void
    {
        MockClient::global([
            CreateWebhookSubscription::class => MockResponse::make(['d' => ['ID' => 'sub-probe']], 201),
            DeleteWebhookSubscription::class => MockResponse::make([], 204),
        ]);

        $this->artisan('exact:webhooks', [
            'connection' => $this->exactConnection()->id,
            'action' => 'probe',
            '--topics' => 'Accounts',
            '--force' => true,
        ])->expectsOutputToContain('Accounts')->assertSuccessful();

        MockClient::global()->assertSent(DeleteWebhookSubscription::class);
    }

    public function test_probe_reports_a_rejected_topic_without_failing_the_run(): void
    {
        MockClient::global([
            CreateWebhookSubscription::class => MockResponse::make(
                ['error' => ['message' => ['value' => 'Invalid topic']]],
                400,
            ),
        ]);

        $this->artisan('exact:webhooks', [
            'connection' => $this->exactConnection()->id,
            'action' => 'probe',
            '--topics' => 'Bogus',
            '--force' => true,
        ])->expectsOutputToContain('Bogus')->assertSuccessful();

        MockClient::global()->assertNotSent(DeleteWebhookSubscription::class);
    }

    public function test_probe_without_force_sends_nothing(): void
    {
        MockClient::global([]);

        $this->artisan('exact:webhooks', [
            'connection' => $this->exactConnection()->id,
            'action' => 'probe',
            '--topics' => 'Accounts',
        ])->expectsOutputToContain('DRY-RUN')->assertSuccessful();

        MockClient::global()->assertNothingSent();
    }

    public function test_it_rejects_an_unknown_action(): void
    {
        $this->artisan('exact:webhooks', [
            'connection' => $this->exactConnection()->id,
            'action' => 'sync',
        ])->expectsOutputToContain('sync')->assertFailed();
    }

    public function test_it_rejects_an_unknown_connection(): void
    {
        $this->artisan('exact:webhooks', ['connection' => '999'])->assertFailed();
    }

    public function test_it_rejects_a_non_exact_connection(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->active()->for($account)->create(['provider' => 'mollie']);

        $this->artisan('exact:webhooks', ['connection' => $connection->id])
            ->expectsOutputToContain('geen Exact-koppeling')
            ->assertFailed();
    }

    public function test_it_reports_when_no_topics_are_configured(): void
    {
        config(['services.exact.webhook_topics' => []]);
        $this->mockRemote([]);

        $this->artisan('exact:webhooks', ['connection' => $this->exactConnection()->id])
            ->expectsOutputToContain('Geen topics geconfigureerd')
            ->assertSuccessful();
    }

    /** @param  list<array<string, string>>  $results */
    private function mockRemote(array $results): void
    {
        MockClient::global([
            ListWebhookSubscriptions::class => MockResponse::make(['d' => ['results' => $results]], 200),
        ]);
    }

    /** @param  array<string, mixed>  $state */
    private function exactConnection(array $state = []): Connection
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();

        return Connection::factory()->forExact()->active()->for($account)->create($state);
    }
}
