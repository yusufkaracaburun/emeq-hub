<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\EmeqStaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

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

        $this->assertSame(3, Role::count());
        $this->assertSame(7, Permission::count());
        $this->assertTrue(Role::where('name', 'boekhouder')->exists());

        $bootstrap = User::where('email', 'admin@emeq.test')->first();
        $this->assertNotNull($bootstrap);
        $this->assertTrue($bootstrap->hasRole('super-admin'));

        $superAdmin = Role::where('name', 'super-admin')->first();
        $staff = Role::where('name', 'staff')->first();
        $this->assertTrue($superAdmin->hasPermissionTo('manage-staff'));
        $this->assertFalse($staff->hasPermissionTo('manage-staff'));
    }

    public function test_seeder_throws_runtime_exception_when_user_already_exists(): void
    {
        putenv('EMEQ_STAFF_SEED_EMAIL=admin@emeq.test');
        putenv('EMEQ_STAFF_SEED_PASSWORD=test-secret');

        $this->seed(EmeqStaffSeeder::class);

        $this->assertSame(1, User::count());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bestaat al');

        $this->seed(EmeqStaffSeeder::class);
    }
}
