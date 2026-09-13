<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_has_app_authentication_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'app_authentication_secret'));
        $this->assertTrue(Schema::hasColumn('users', 'app_authentication_recovery_codes'));
    }

    public function test_admin_can_reach_profile_page_to_set_up_two_factor(): void
    {
        Role::firstOrCreate(['name' => 'super-admin']);
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $this->actingAs($user)
            ->get('/admin/profile')
            ->assertOk();
    }

    public function test_quick_login_shortcut_bypasses_two_factor_in_non_production(): void
    {
        Role::firstOrCreate(['name' => 'super-admin']);
        User::factory()->create()->assignRole('super-admin');

        $this->get('/admin/quick-login/super-admin')
            ->assertRedirect('/admin');

        $this->get('/admin')->assertOk();
    }
}
