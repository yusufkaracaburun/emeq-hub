<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Billing\Account\SubscriptionStatus;
use App\Filament\Resources\AccountSubscriptions\Pages\ListAccountSubscriptions;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountSubscriptionResourceTest extends TestCase
{
    use RefreshDatabase;

    private function seedRoles(): void
    {
        Role::firstOrCreate(['name' => 'super-admin']);
        Role::firstOrCreate(['name' => 'staff']);
        Permission::firstOrCreate(['name' => 'view-account-subscriptions']);
    }

    private function actingAsStaff(): User
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $user->assignRole('staff');
        $user->givePermissionTo('view-account-subscriptions');
        $this->actingAs($user);

        return $user;
    }

    public function test_list_shows_subscriptions_for_staff(): void
    {
        $this->actingAsStaff();

        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        $connection = Connection::factory()->forMollie()->active()->for($account)->create();

        $active = AccountSubscription::factory()->forConnection($connection)->active()->create();
        $paused = AccountSubscription::factory()->forConnection($connection)->paused()->create();

        Livewire::test(ListAccountSubscriptions::class)
            ->assertCanSeeTableRecords([$active, $paused]);
    }

    public function test_status_filter_narrows_to_active(): void
    {
        $this->actingAsStaff();

        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-B']);
        $connection = Connection::factory()->forMollie()->active()->for($account)->create();

        $active = AccountSubscription::factory()->forConnection($connection)->active()->create();
        $paused = AccountSubscription::factory()->forConnection($connection)->paused()->create();

        Livewire::test(ListAccountSubscriptions::class)
            ->filterTable('status', SubscriptionStatus::Active->value)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$paused]);
    }

    public function test_view_page_shows_mollie_ids(): void
    {
        $this->actingAsStaff();

        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-V']);
        $connection = Connection::factory()->forMollie()->active()->for($account)->create();

        $sub = AccountSubscription::factory()->forConnection($connection)->active()->create([
            'mollie_customer_id' => 'cst_TEST_VIEW',
            'mollie_subscription_id' => 'sub_TEST_VIEW',
            'mollie_mandate_id' => 'mdt_TEST_VIEW',
        ]);

        $response = $this->get('/admin/account-subscriptions/'.$sub->id);

        $response->assertOk();
        $response->assertSee('cst_TEST_VIEW');
        $response->assertSee('sub_TEST_VIEW');
        $response->assertSee('mdt_TEST_VIEW');
    }
}
