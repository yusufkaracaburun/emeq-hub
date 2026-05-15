<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Plan 09-03 Task 3 — feature-tests voor /admin canAccessPanel-gate.
 *
 * Bewijst (D-05 RBAC-gate):
 *  - Unauthenticated User → redirect naar /admin/login (Filament-default)
 *  - Authenticated User zonder Spatie-rol → 403 (canAccessPanel false)
 *  - Authenticated User met rol `staff` → 200 (canAccessPanel true)
 *
 * Rollen worden direct via Spatie's Role-model geseed (niet via EmeqStaffSeeder)
 * omdat EmeqStaffSeeder env-gated is — directe role-create is deterministischer.
 */
class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    private function seedRoles(): void
    {
        Role::firstOrCreate(['name' => 'super-admin']);
        Role::firstOrCreate(['name' => 'staff']);
    }

    public function test_unauthenticated_user_is_redirected_to_admin_login(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect('/admin/login');
    }

    public function test_authenticated_user_without_role_cannot_access_admin_panel(): void
    {
        $this->seedRoles();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertForbidden();
    }

    public function test_staff_user_can_access_admin_panel(): void
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $user->assignRole('staff');

        $response = $this->actingAs($user)->get('/admin');

        $response->assertOk();
    }
}
