<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\AccountSubscriptionsRelationManager as AccountSubsRm;
use App\Filament\Resources\Connections\Pages\ViewConnection;
use App\Filament\Resources\Connections\RelationManagers\AccountSubscriptionsRelationManager as ConnectionSubsRm;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Smoke-tests voor de RelationManagers op Consumer/Account/Connection-detail-pagina's.
 *
 * Filament v4 lazy-rendert tab-content — alleen de eerste/active tab zit in
 * initial HTML. Voor lazy tabs gebruiken we Livewire::test() op de manager-class.
 */
class RelationManagersRenderTest extends TestCase
{
    use RefreshDatabase;

    private function makeStaffUser(): User
    {
        Role::firstOrCreate(['name' => 'super-admin']);
        Role::firstOrCreate(['name' => 'staff']);
        $user = User::factory()->create();
        $user->assignRole('staff');

        return $user;
    }

    public function test_consumer_edit_page_renders_accounts_relation_manager(): void
    {
        $admin = $this->makeStaffUser();
        $consumer = Consumer::factory()->create(['slug' => 'naschool']);
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        Connection::factory()->forMollie()->for($account)->create();

        $response = $this->actingAs($admin)->get("/admin/consumers/{$consumer->id}/edit");

        $response->assertOk();
        $response->assertSee('AccountsRelationManager');
    }

    public function test_account_view_page_renders_connections_relation_manager_in_first_tab(): void
    {
        $admin = $this->makeStaffUser();
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        Connection::factory()->forMollie()->for($account)->create();

        $response = $this->actingAs($admin)->get("/admin/accounts/{$account->id}");

        $response->assertOk();
        $response->assertSee('ConnectionsRelationManager');
    }

    public function test_account_subscriptions_relation_manager_renders_for_account(): void
    {
        $admin = $this->makeStaffUser();
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        $mollie = Connection::factory()->forMollie()->for($account)->create();
        AccountSubscription::factory()->for($account)->for($mollie, 'connection')->create();

        $this->actingAs($admin);

        Livewire::test(AccountSubsRm::class, [
            'ownerRecord' => $account,
            'pageClass' => ViewAccount::class,
        ])->assertSuccessful();
    }

    public function test_connection_view_page_renders_subscriptions_relation_manager(): void
    {
        $admin = $this->makeStaffUser();
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        $mollie = Connection::factory()->forMollie()->for($account)->create();
        AccountSubscription::factory()->for($account)->for($mollie, 'connection')->create();

        $response = $this->actingAs($admin)->get("/admin/connections/{$mollie->id}");

        $response->assertOk();
        $response->assertSee('AccountSubscriptionsRelationManager');
    }

    public function test_connection_subscriptions_relation_manager_renders_isolated(): void
    {
        $admin = $this->makeStaffUser();
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        $mollie = Connection::factory()->forMollie()->for($account)->create();
        AccountSubscription::factory()->for($account)->for($mollie, 'connection')->create();

        $this->actingAs($admin);

        Livewire::test(ConnectionSubsRm::class, [
            'ownerRecord' => $mollie,
            'pageClass' => ViewConnection::class,
        ])->assertSuccessful();
    }
}
