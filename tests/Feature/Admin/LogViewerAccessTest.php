<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * opcodesio/log-viewer op /log-viewer — gated via LogViewer::auth()
 * (AppServiceProvider::boot) op de super-admin-rol.
 */
class LogViewerAccessTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        Role::firstOrCreate(['name' => $role]);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_super_admin_can_view_log_viewer(): void
    {
        $this->actingAs($this->userWithRole('super-admin'))
            ->get('/log-viewer')
            ->assertOk();
    }

    public function test_staff_cannot_view_log_viewer(): void
    {
        $this->actingAs($this->userWithRole('staff'))
            ->get('/log-viewer')
            ->assertForbidden();
    }

    public function test_guest_cannot_view_log_viewer(): void
    {
        $this->get('/log-viewer')->assertForbidden();
    }
}
