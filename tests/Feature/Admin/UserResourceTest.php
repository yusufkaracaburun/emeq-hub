<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Plan 09-10: UserResource CRUD-flow voor super-admin.
 *
 * Bewijst:
 *  - Create via Livewire CreateUser-page: User row + password hashed
 *  - Custom assignRole-action via Livewire callTableAction: rol gesynced
 *  - Validatie: email required + unique
 */
class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    private function seedRolesAndPermissions(): void
    {
        $superAdmin = Role::firstOrCreate(['name' => 'super-admin']);
        Role::firstOrCreate(['name' => 'staff']);
        $managePerm = Permission::firstOrCreate(['name' => 'manage-staff']);
        $superAdmin->givePermissionTo($managePerm);
    }

    private function actingAsSuperAdmin(): User
    {
        $this->seedRolesAndPermissions();
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);

        return $admin;
    }

    public function test_super_admin_can_create_user_via_resource(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Nieuwe Admin',
                'email' => 'new@emeq.test',
                'password' => 'Secret123!',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('email', 'new@emeq.test')->first();
        $this->assertNotNull($created);
        $this->assertTrue(Hash::check('Secret123!', $created->password));
    }

    public function test_super_admin_can_assign_role_via_action(): void
    {
        $this->actingAsSuperAdmin();

        $target = User::factory()->create();
        $this->assertFalse($target->hasRole('staff'));

        Livewire::test(ListUsers::class)
            ->callTableAction('assignRole', $target, ['role' => 'staff'])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($target->fresh()->hasRole('staff'));
    }

    public function test_email_is_required_and_unique(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Zonder Email',
                'email' => '',
                'password' => 'Secret123!',
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);

        User::factory()->create(['email' => 'dup@emeq.test']);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Duplicaat',
                'email' => 'dup@emeq.test',
                'password' => 'Secret123!',
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);
    }
}
