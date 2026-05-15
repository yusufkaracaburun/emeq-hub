<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * D-05: RBAC-bootstrap via Spatie laravel-permission. Idempotent
 * env-driven seeder die 2 rollen + 6 permissions + 1 bootstrap
 * super-admin User aanmaakt. Zonder beide env-vars: no-op (production-safe).
 *
 * Run: EMEQ_STAFF_SEED_EMAIL=… EMEQ_STAFF_SEED_PASSWORD=… \
 *      php artisan db:seed --class=EmeqStaffSeeder
 */
class EmeqStaffSeeder extends Seeder
{
    /**
     * Permissions die zowel super-admin als staff krijgen.
     *
     * @var list<string>
     */
    private const SHARED_PERMISSIONS = [
        'manage-consumers',
        'manage-connections',
        'view-webhooks',
        'view-account-subscriptions',
        'view-billing',
    ];

    /**
     * Permission die alleen super-admin krijgt.
     */
    private const SUPER_ADMIN_ONLY_PERMISSION = 'manage-staff';

    public function run(): void
    {
        $email = env('EMEQ_STAFF_SEED_EMAIL');
        $password = env('EMEQ_STAFF_SEED_PASSWORD');

        if (! $email || ! $password) {
            return;
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super-admin']);
        $staff = Role::firstOrCreate(['name' => 'staff']);

        foreach (self::SHARED_PERMISSIONS as $perm) {
            $p = Permission::firstOrCreate(['name' => $perm]);
            $superAdmin->givePermissionTo($p);
            $staff->givePermissionTo($p);
        }

        $managePerm = Permission::firstOrCreate(['name' => self::SUPER_ADMIN_ONLY_PERMISSION]);
        $superAdmin->givePermissionTo($managePerm);

        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => 'Emeq Super Admin', 'password' => Hash::make($password)],
        );
        $user->assignRole($superAdmin);
    }
}
