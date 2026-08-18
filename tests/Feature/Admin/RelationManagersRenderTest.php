<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\AccountSubscriptionsRelationManager as AccountSubsRm;
use App\Filament\Resources\Connections\Pages\ViewConnection;
use App\Filament\Resources\Connections\RelationManagers\InboundWebhookEventsRelationManager;
use App\Filament\Resources\Connections\RelationManagers\PassThroughCallsRelationManager;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\PassThroughCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RelationManagersRenderTest extends TestCase
{
    use RefreshDatabase;

    private function makeStaffUser(): User
    {
        Role::firstOrCreate(['name' => 'super-admin']);
        Role::firstOrCreate(['name' => 'staff']);
        Permission::firstOrCreate(['name' => 'manage-consumers']);
        Permission::firstOrCreate(['name' => 'manage-connections']);
        $user = User::factory()->create();
        $user->assignRole('staff');
        $user->givePermissionTo(['manage-consumers', 'manage-connections']);

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

    public function test_connection_view_page_renders_pass_through_relation_manager(): void
    {
        $admin = $this->makeStaffUser();
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        $mollie = Connection::factory()->forMollie()->for($account)->create();
        PassThroughCall::factory()->create(['connection_id' => $mollie->id]);

        $response = $this->actingAs($admin)->get("/admin/connections/{$mollie->id}");

        $response->assertOk();
        $response->assertSee('PassThroughCallsRelationManager');
    }

    public function test_connection_relation_managers_render_isolated(): void
    {
        $admin = $this->makeStaffUser();
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        $mollie = Connection::factory()->forMollie()->for($account)->create();

        $this->actingAs($admin);

        Livewire::test(PassThroughCallsRelationManager::class, [
            'ownerRecord' => $mollie,
            'pageClass' => ViewConnection::class,
        ])->assertSuccessful();

        Livewire::test(InboundWebhookEventsRelationManager::class, [
            'ownerRecord' => $mollie,
            'pageClass' => ViewConnection::class,
        ])->assertSuccessful();
    }
}
