<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PermissionGatingTest extends TestCase
{
    use RefreshDatabase;

    private function seedRolesAndPermissions(): void
    {
        $superAdmin = Role::firstOrCreate(['name' => 'super-admin']);
        Role::firstOrCreate(['name' => 'staff']);
        $managePerm = Permission::firstOrCreate(['name' => 'manage-staff']);
        $superAdmin->givePermissionTo($managePerm);
    }

    public function test_staff_user_cannot_access_user_resource(): void
    {
        $this->seedRolesAndPermissions();
        $user = User::factory()->create();
        $user->assignRole('staff');

        $response = $this->actingAs($user)->get('/admin/users');

        $response->assertForbidden();
    }

    public function test_super_admin_can_access_user_resource(): void
    {
        $this->seedRolesAndPermissions();
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $response = $this->actingAs($user)->get('/admin/users');

        $response->assertOk();
    }

    public function test_staff_user_does_not_see_user_navigation_link(): void
    {
        $this->seedRolesAndPermissions();
        $user = User::factory()->create();
        $user->assignRole('staff');

        $response = $this->actingAs($user)->get('/admin');

        $response->assertOk();
        $response->assertDontSee('admin/users');
    }
}
