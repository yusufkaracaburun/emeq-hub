<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regressie: dev-seeder moet super-admin én staff de `view-pass-through-calls`
 * permission geven, zodat de PassThroughCallResource zichtbaar is na een kale
 * `migrate:fresh --seed`. Dreef eerder weg van EmeqStaffSeeder::SHARED_PERMISSIONS.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_dev_seeder_grants_pass_through_calls_permission_to_both_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(
            Role::findByName('super-admin')->hasPermissionTo('view-pass-through-calls'),
        );
        $this->assertTrue(
            Role::findByName('staff')->hasPermissionTo('view-pass-through-calls'),
        );
    }
}
