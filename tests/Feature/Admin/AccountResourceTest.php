<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Models\Account;
use App\Models\Consumer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountResourceTest extends TestCase
{
    use RefreshDatabase;

    private function seedRoles(): void
    {
        Role::firstOrCreate(['name' => 'super-admin']);
        Role::firstOrCreate(['name' => 'staff']);
        Permission::firstOrCreate(['name' => 'manage-consumers']);
    }

    private function actAsStaff(): User
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $user->assignRole('staff');
        $user->givePermissionTo('manage-consumers');
        $this->actingAs($user);

        return $user;
    }

    public function test_list_shows_all_accounts_for_staff_user(): void
    {
        $this->actAsStaff();

        $consumerA = Consumer::factory()->create();
        $consumerB = Consumer::factory()->create();
        Account::factory()->count(2)->for($consumerA)->create();
        Account::factory()->for($consumerB)->create();

        Livewire::test(ListAccounts::class)
            ->assertCanSeeTableRecords(Account::all())
            ->assertCountTableRecords(3);
    }

    public function test_consumer_filter_narrows_to_specific_consumer(): void
    {
        $this->actAsStaff();

        $consumerA = Consumer::factory()->create();
        $consumerB = Consumer::factory()->create();
        Account::factory()->count(2)->for($consumerA)->create();
        Account::factory()->for($consumerB)->create();

        Livewire::test(ListAccounts::class)
            ->filterTable('consumer', $consumerA->id)
            ->assertCanSeeTableRecords(Account::where('consumer_id', $consumerA->id)->get())
            ->assertCountTableRecords(2);
    }

    public function test_account_view_page_returns_200(): void
    {
        $this->actAsStaff();

        $account = Account::factory()->create();

        $response = $this->get("/admin/accounts/{$account->id}");

        $response->assertOk();
        $response->assertSee($account->external_id);
    }
}
