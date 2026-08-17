<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\AccessRequests\Pages\ViewAccessRequest;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\AccountSubscriptions\Pages\ViewAccountSubscription;
use App\Filament\Resources\Connections\Pages\ViewConnection;
use App\Filament\Resources\Consumers\Pages\ViewConsumer;
use App\Filament\Resources\DemoRequests\Pages\ViewDemoRequest;
use App\Filament\Resources\InboundWebhookEvents\Pages\ViewInboundWebhookEvent;
use App\Filament\Resources\PassThroughCalls\Pages\ViewPassThroughCall;
use App\Models\AccessRequest;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\DemoRequest;
use App\Models\InboundWebhookEvent;
use App\Models\PassThroughCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Elke detailpagina opent met dezelfde kopstrip. Deze test rendert ze allemaal en
 * controleert de labels van die strip — de opbouw is gedeeld, dus één kapotte
 * relatie of kolomnaam legt hier direct een pagina om.
 */
class DetailPageLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'super-admin']);

        $permissions = [
            'manage-connections',
            'manage-consumers',
            'view-pass-through-calls',
            'view-webhooks',
            'view-account-subscriptions',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $user->syncPermissions(Permission::all());

        return $user;
    }

    private function account(): Account
    {
        return Account::factory()->for(Consumer::factory()->create())->create();
    }

    public function test_connection_detail_opens_with_the_status_strip(): void
    {
        $this->actingAs($this->admin());
        $connection = Connection::factory()->forExact()->for($this->account())->create();

        Livewire::test(ViewConnection::class, ['record' => $connection->getKey()])
            ->assertSuccessful()
            ->assertSee('Status')
            ->assertSee('Token verloopt');
    }

    public function test_account_detail_opens_with_the_status_strip(): void
    {
        $this->actingAs($this->admin());
        $account = $this->account();
        Connection::factory()->forExact()->for($account)->create();

        Livewire::test(ViewAccount::class, ['record' => $account->getKey()])
            ->assertSuccessful()
            ->assertSee('Consumer')
            ->assertSee('External ID')
            ->assertSee('Koppelingen')
            ->assertSee('1 actief van 1');
    }

    public function test_consumer_detail_opens_with_the_status_strip(): void
    {
        $this->actingAs($this->admin());
        $consumer = Consumer::factory()->create();
        Account::factory()->for($consumer)->create();

        Livewire::test(ViewConsumer::class, ['record' => $consumer->getKey()])
            ->assertSuccessful()
            ->assertSee('Slug')
            ->assertSee('Accounts')
            ->assertSee('Laatste inbound webhook');
    }

    public function test_account_subscription_detail_opens_with_the_status_strip(): void
    {
        $this->actingAs($this->admin());
        $account = $this->account();
        $subscription = AccountSubscription::factory()
            ->for($account)
            ->for(Connection::factory()->forMollie()->for($account)->create())
            ->create();

        Livewire::test(ViewAccountSubscription::class, ['record' => $subscription->getKey()])
            ->assertSuccessful()
            ->assertSee('Bedrag')
            ->assertSee('Interval')
            ->assertSee('Laatste webhook');
    }

    public function test_access_request_detail_opens_with_the_status_strip(): void
    {
        $this->actingAs($this->admin());
        $request = AccessRequest::factory()->create();

        Livewire::test(ViewAccessRequest::class, ['record' => $request->getKey()])
            ->assertSuccessful()
            ->assertSee('Bedrijf')
            ->assertSee('Ge-onboard als')
            ->assertSee('Ontvangen');
    }

    public function test_demo_request_detail_opens_with_the_status_strip(): void
    {
        $this->actingAs($this->admin());
        $request = DemoRequest::factory()->create();

        Livewire::test(ViewDemoRequest::class, ['record' => $request->getKey()])
            ->assertSuccessful()
            ->assertSee('Bedrijf')
            ->assertSee('Voorkeursmoment')
            ->assertSee('Ontvangen');
    }

    public function test_inbound_webhook_event_detail_opens_with_the_status_strip(): void
    {
        $this->actingAs($this->admin());
        $event = InboundWebhookEvent::factory()->create();

        Livewire::test(ViewInboundWebhookEvent::class, ['record' => $event->getKey()])
            ->assertSuccessful()
            ->assertSee('Uitkomst')
            ->assertSee('HTTP-status')
            ->assertSee('Fan-out');
    }

    public function test_pass_through_call_detail_opens_with_the_status_strip(): void
    {
        $this->actingAs($this->admin());
        $call = PassThroughCall::factory()->create();

        Livewire::test(ViewPassThroughCall::class, ['record' => $call->getKey()])
            ->assertSuccessful()
            ->assertSee('HTTP-status')
            ->assertSee('Endpoint')
            ->assertSee('Duur');
    }
}
