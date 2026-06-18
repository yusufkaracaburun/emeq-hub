<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Models\Account;
use App\Models\Consumer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Admin-CRUD voor Account (tenant-resource met volledige CRUD). `external_id` +
 * `consumer_id` vormen samen de identiteit en zijn op edit immutable — alleen
 * `display_name` is bij te werken.
 */
class AccountCrudTest extends TestCase
{
    use RefreshDatabase;

    private function actAsManager(): User
    {
        Role::firstOrCreate(['name' => 'staff']);
        Permission::firstOrCreate(['name' => 'manage-consumers']);
        $user = User::factory()->create();
        $user->assignRole('staff');
        $user->givePermissionTo('manage-consumers');
        $this->actingAs($user);

        return $user;
    }

    public function test_admin_can_create_account(): void
    {
        $this->actAsManager();
        $consumer = Consumer::factory()->create();

        Livewire::test(CreateAccount::class)
            ->fillForm([
                'consumer_id' => $consumer->id,
                'external_id' => 'school-X',
                'display_name' => 'School X',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('accounts', [
            'consumer_id' => $consumer->id,
            'external_id' => 'school-X',
            'display_name' => 'School X',
        ]);
    }

    public function test_admin_can_edit_display_name_but_external_id_is_immutable(): void
    {
        $this->actAsManager();
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create([
            'external_id' => 'orig-id',
            'display_name' => 'Oude naam',
        ]);

        Livewire::test(EditAccount::class, ['record' => $account->id])
            ->assertFormFieldIsDisabled('external_id')
            ->fillForm(['display_name' => 'Nieuwe naam'])
            ->call('save')
            ->assertHasNoFormErrors();

        $account->refresh();
        $this->assertSame('Nieuwe naam', $account->display_name);
        $this->assertSame('orig-id', $account->external_id);
    }

    public function test_admin_can_delete_account(): void
    {
        $this->actAsManager();
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();

        Livewire::test(ListAccounts::class)
            ->callTableAction('delete', $account);

        $this->assertModelMissing($account);
    }
}
