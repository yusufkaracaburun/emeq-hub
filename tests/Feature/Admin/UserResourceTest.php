<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

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
                'roles' => [Role::findByName('staff')->getKey()],
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

    public function test_super_admin_cannot_self_downgrade_via_assign_role(): void
    {
        $admin = $this->actingAsSuperAdmin();

        Livewire::test(ListUsers::class)
            ->callTableAction('assignRole', $admin, ['role' => 'staff']);

        $this->assertTrue($admin->fresh()->hasRole('super-admin'));
        $this->assertFalse($admin->fresh()->hasRole('staff'));
    }

    public function test_last_super_admin_self_downgrade_is_blocked(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $this->assertSame(1, User::role('super-admin')->count());

        Livewire::test(ListUsers::class)
            ->callTableAction('assignRole', $admin, ['role' => 'staff']);

        $admin->refresh();
        $this->assertTrue($admin->hasRole('super-admin'));
        $this->assertSame(1, User::role('super-admin')->count());
    }

    public function test_last_super_admin_cannot_be_deleted_via_edit_page(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $this->assertSame(1, User::role('super-admin')->count());

        Livewire::test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->callAction('delete');

        $this->assertNotNull(User::find($admin->id));
    }

    public function test_assign_role_rejects_unknown_role(): void
    {
        $this->actingAsSuperAdmin();

        $target = User::factory()->create();
        $this->assertFalse($target->hasRole('staff'));
        $this->assertFalse($target->hasRole('super-admin'));

        Livewire::test(ListUsers::class)
            ->callTableAction('assignRole', $target, ['role' => 'foo-role']);

        $target->refresh();
        $this->assertFalse($target->hasRole('foo-role'));
        $this->assertFalse($target->hasRole('staff'));
        $this->assertFalse($target->hasRole('super-admin'));
    }

    public function test_super_admin_can_downgrade_other_super_admin_when_not_last(): void
    {
        $admin1 = $this->actingAsSuperAdmin();
        $admin2 = User::factory()->create();
        $admin2->assignRole('super-admin');

        $this->assertSame(2, User::role('super-admin')->count());

        Livewire::test(ListUsers::class)
            ->callTableAction('assignRole', $admin2, ['role' => 'staff'])
            ->assertHasNoTableActionErrors();

        $admin2->refresh();
        $this->assertFalse($admin2->hasRole('super-admin'));
        $this->assertTrue($admin2->hasRole('staff'));
        $this->assertTrue($admin1->fresh()->hasRole('super-admin'));
    }

    public function test_edit_user_without_password_keeps_existing_hash(): void
    {
        $this->actingAsSuperAdmin();

        $target = User::factory()->create([
            'password' => Hash::make('original-pass'),
        ]);

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm([
                'name' => 'Updated Name',
                'email' => $target->email,
                'password' => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $target->refresh();

        $this->assertTrue(Hash::check('original-pass', $target->password));
        $this->assertSame('Updated Name', $target->name);
    }
}
