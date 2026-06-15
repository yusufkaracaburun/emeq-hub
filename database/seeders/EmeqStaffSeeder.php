<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * D-05 + D-7 (WR-04): RBAC-bootstrap via Spatie laravel-permission.
 * Env-driven seeder die 2 rollen + 6 permissions + 1 bootstrap super-admin
 * User aanmaakt. Zonder beide env-vars: no-op (production-safe).
 *
 * Bootstrap-only — voor password-resets gebruik `php artisan tinker`.
 * 2× runnen op een gebootstrapt env gooit RuntimeException (D-7 / WR-04 —
 * "bootstrap, niet sync"). Roles + Permissions blijven idempotent via
 * firstOrCreate; alleen de User-creatie is hard-fail.
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
        'view-pass-through-calls',
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

        $existing = User::where('email', $email)->first();

        if ($existing !== null) {
            throw new \RuntimeException(
                "User {$email} bestaat al — reset wachtwoord via `php artisan tinker`, niet via seeder. ".
                'EmeqStaffSeeder is bootstrap-only (D-7 / WR-04).'
            );
        }

        $user = User::create([
            'email' => $email,
            'name' => 'Emeq Super Admin',
            'password' => Hash::make($password),
        ]);
        $user->assignRole($superAdmin);
    }
}
