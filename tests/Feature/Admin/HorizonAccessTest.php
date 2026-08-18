<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HorizonAccessTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        Role::firstOrCreate(['name' => $role]);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_super_admin_can_view_horizon(): void
    {
        $this->actingAs($this->userWithRole('super-admin'))
            ->get('/horizon')
            ->assertOk();
    }

    public function test_staff_cannot_view_horizon(): void
    {
        $this->actingAs($this->userWithRole('staff'))
            ->get('/horizon')
            ->assertForbidden();
    }
}
