<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\EmeqStaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Plan 09-03 Task 3 — feature-tests voor EmeqStaffSeeder.
 *
 * Bewijst:
 *  - Zonder beide env-vars: no-op (geen rollen, geen users)
 *  - Met beide env-vars: 2 rollen + 6 permissions + bootstrap super-admin User
 *  - 2× draaien met zelfde env: idempotent (geen duplicates)
 */
class EmeqStaffSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('EMEQ_STAFF_SEED_EMAIL');
        putenv('EMEQ_STAFF_SEED_PASSWORD');

        parent::tearDown();
    }

    public function test_seeder_is_noop_without_env_vars(): void
    {
        putenv('EMEQ_STAFF_SEED_EMAIL');
        putenv('EMEQ_STAFF_SEED_PASSWORD');

        $this->seed(EmeqStaffSeeder::class);

        $this->assertSame(0, Role::count());
        $this->assertSame(0, Permission::count());
        $this->assertSame(0, User::count());
    }

    public function test_seeder_creates_roles_permissions_and_bootstrap_user_with_env(): void
    {
        putenv('EMEQ_STAFF_SEED_EMAIL=admin@emeq.test');
        putenv('EMEQ_STAFF_SEED_PASSWORD=test-secret');

        $this->seed(EmeqStaffSeeder::class);

        $this->assertSame(2, Role::count());
        $this->assertSame(6, Permission::count());

        $bootstrap = User::where('email', 'admin@emeq.test')->first();
        $this->assertNotNull($bootstrap);
        $this->assertTrue($bootstrap->hasRole('super-admin'));

        $superAdmin = Role::where('name', 'super-admin')->first();
        $staff = Role::where('name', 'staff')->first();
        $this->assertTrue($superAdmin->hasPermissionTo('manage-staff'));
        $this->assertFalse($staff->hasPermissionTo('manage-staff'));
    }

    public function test_seeder_is_idempotent_when_run_twice(): void
    {
        putenv('EMEQ_STAFF_SEED_EMAIL=admin@emeq.test');
        putenv('EMEQ_STAFF_SEED_PASSWORD=test-secret');

        $this->seed(EmeqStaffSeeder::class);
        $this->seed(EmeqStaffSeeder::class);

        $this->assertSame(2, Role::count());
        $this->assertSame(6, Permission::count());
        $this->assertSame(1, User::count());
    }
}
