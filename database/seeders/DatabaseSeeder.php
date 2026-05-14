<?php

namespace Database\Seeders;

use App\Models\Consumer;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        if (! User::where('email', 'test@example.com')->exists()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }

        $consumer = Consumer::firstOrCreate(
            ['slug' => 'naschool'],
            ['name' => 'Naschool'],
        );

        $consumer->accounts()->firstOrCreate(
            ['external_id' => 'school1'],
            ['display_name' => 'Demo School 1'],
        );
    }
}
