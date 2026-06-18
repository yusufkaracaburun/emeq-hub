<?php

namespace Database\Seeders;

use App\Models\Consumer;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    private const ROLE_PERMISSIONS = [
        'super-admin' => [
            'manage-consumers',
            'manage-connections',
            'view-webhooks',
            'view-pass-through-calls',
            'view-account-subscriptions',
            'view-billing',
            'manage-staff',
        ],
        'staff' => [
            'manage-consumers',
            'manage-connections',
            'view-webhooks',
            'view-pass-through-calls',
            'view-account-subscriptions',
            'view-billing',
        ],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $testUser = User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => bcrypt('password')],
        );

        $consumer = Consumer::firstOrCreate(
            ['slug' => 'naschool'],
            ['name' => 'Naschool'],
        );

        $consumer->accounts()->firstOrCreate(
            ['external_id' => 'school1'],
            ['display_name' => 'Demo School 1'],
        );

        $this->seedRbac($testUser);

        $this->call(EmeqStaffSeeder::class);
        $this->call(ExactDevSettingsSeeder::class);
    }

    private function seedRbac(User $testUser): void
    {
        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            $role = Role::firstOrCreate(['name' => $roleName]);
            foreach ($permissions as $perm) {
                $role->givePermissionTo(Permission::firstOrCreate(['name' => $perm]));
            }
        }

        if (! $testUser->hasRole('super-admin')) {
            $testUser->assignRole('super-admin');
        }
    }
}
