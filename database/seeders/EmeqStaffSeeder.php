<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class EmeqStaffSeeder extends Seeder
{
    /** @var list<string> */
    private const SHARED_PERMISSIONS = [
        'manage-consumers',
        'manage-connections',
        'view-webhooks',
        'view-pass-through-calls',
        'view-account-subscriptions',
        'view-billing',
    ];

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

        Role::firstOrCreate(['name' => 'boekhouder']);

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
