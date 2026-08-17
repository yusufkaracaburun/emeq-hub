<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Connections\Pages\ListConnections;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\User;
use Emeq\ExactApi\Http\Request\Delete\DeleteWebhookSubscription;
use Emeq\ExactApi\Http\Request\Read\ListWebhookSubscriptions;
use Emeq\ExactApi\Http\Request\Write\CreateWebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManageWebhookSubscriptionsActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MockClient::destroyGlobal();
        config([
            'services.exact.client_id' => 'app_test_id',
            'services.exact.client_secret' => 'app_test_secret',
            'services.exact.redirect_uri' => 'https://hub.test/v1/oauth/exact/callback',
            'services.exact.webhook_topics' => ['BankEntries', 'CashEntries'],
        ]);
    }

    protected function tearDown(): void
    {
        MockClient::destroyGlobal();

        parent::tearDown();
    }

    public function test_action_is_visible_for_an_active_exact_connection(): void
    {
        $this->actingAs($this->makeStaffUser());

        Livewire::test(ListConnections::class)
            ->assertTableActionVisible('webhookSubscriptions', $this->makeExactConnection());
    }

    public function test_action_is_hidden_for_a_revoked_connection(): void
    {
        $this->actingAs($this->makeStaffUser());
        $revoked = $this->makeExactConnection(['revoked_at' => now()]);

        Livewire::test(ListConnections::class)
            ->assertTableActionHidden('webhookSubscriptions', $revoked);
    }

    public function test_ticking_a_topic_creates_the_subscription_at_exact(): void
    {
        $this->actingAs($this->makeStaffUser());

        MockClient::global([
            ListWebhookSubscriptions::class => MockResponse::make(['d' => ['results' => [
                ['Topic' => 'BankEntries', 'ID' => 'sub-A'],
            ]]], 200),
            CreateWebhookSubscription::class => MockResponse::make(['d' => ['ID' => 'sub-new']], 201),
        ]);

        $connection = $this->makeExactConnection();

        Livewire::test(ListConnections::class)
            ->callTableAction('webhookSubscriptions', $connection, data: [
                'topics' => ['BankEntries' => true, 'CashEntries' => true],
            ])
            ->assertHasNoTableActionErrors();

        $connection->refresh();
        $this->assertSame(
            ['BankEntries' => 'sub-A', 'CashEntries' => 'sub-new'],
            $connection->metadata['exact_webhooks'],
        );
    }

    public function test_unticking_a_topic_cancels_it_at_exact(): void
    {
        $this->actingAs($this->makeStaffUser());

        MockClient::global([
            ListWebhookSubscriptions::class => MockResponse::make(['d' => ['results' => [
                ['Topic' => 'BankEntries', 'ID' => 'sub-A'],
                ['Topic' => 'CashEntries', 'ID' => 'sub-B'],
            ]]], 200),
            DeleteWebhookSubscription::class => MockResponse::make([], 204),
        ]);

        $connection = $this->makeExactConnection([
            'metadata' => ['exact_webhooks' => ['BankEntries' => 'sub-A', 'CashEntries' => 'sub-B']],
        ]);

        Livewire::test(ListConnections::class)
            ->callTableAction('webhookSubscriptions', $connection, data: [
                'topics' => ['BankEntries' => true, 'CashEntries' => false],
            ])
            ->assertHasNoTableActionErrors();

        $connection->refresh();
        $this->assertSame(['BankEntries' => 'sub-A'], $connection->metadata['exact_webhooks']);

        MockClient::global()->assertSent(DeleteWebhookSubscription::class);
    }

    public function test_an_exact_failure_does_not_blow_up_the_modal(): void
    {
        $this->actingAs($this->makeStaffUser());

        MockClient::global([
            ListWebhookSubscriptions::class => MockResponse::make(
                ['error' => ['message' => ['value' => 'Forbidden - Application Scope Violated.']]],
                403,
            ),
        ]);

        $connection = $this->makeExactConnection();

        Livewire::test(ListConnections::class)
            ->mountTableAction('webhookSubscriptions', $connection)
            ->assertHasNoTableActionErrors();
    }

    private function makeStaffUser(): User
    {
        Role::firstOrCreate(['name' => 'staff']);
        Permission::firstOrCreate(['name' => 'manage-connections']);
        $user = User::factory()->create();
        $user->assignRole('staff');
        $user->givePermissionTo('manage-connections');

        return $user;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function makeExactConnection(array $state = []): Connection
    {
        $account = Account::factory()->for(Consumer::factory()->create())->create();

        return Connection::factory()->forExact()->for($account)->create($state);
    }
}
