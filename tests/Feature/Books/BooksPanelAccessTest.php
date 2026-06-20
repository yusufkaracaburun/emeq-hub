<?php

namespace Tests\Feature\Books;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BooksPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    private function seedRoles(): void
    {
        Role::firstOrCreate(['name' => 'super-admin']);
        Role::firstOrCreate(['name' => 'staff']);
        Role::firstOrCreate(['name' => 'boekhouder']);
    }

    private function userWithRole(string $role): User
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_boekhouder_can_access_books_panel(): void
    {
        $panel = Filament::getPanel('books');

        $this->assertTrue($this->userWithRole('boekhouder')->canAccessPanel($panel));
        $this->assertTrue($this->userWithRole('super-admin')->canAccessPanel($panel));
    }

    public function test_staff_cannot_access_books_panel(): void
    {
        $panel = Filament::getPanel('books');

        $this->assertFalse($this->userWithRole('staff')->canAccessPanel($panel));
    }

    public function test_super_admin_still_cannot_be_locked_out_of_admin_but_staff_can_admin(): void
    {
        $admin = Filament::getPanel('admin');

        $this->assertTrue($this->userWithRole('staff')->canAccessPanel($admin));
        $this->assertFalse($this->userWithRole('boekhouder')->canAccessPanel($admin));
    }

    public function test_books_dashboard_renders_for_boekhouder(): void
    {
        $this->actingAs($this->userWithRole('boekhouder'));

        $this->get('/boekhouding')->assertSuccessful();
    }

    public function test_books_dashboard_forbidden_for_staff(): void
    {
        $this->actingAs($this->userWithRole('staff'));

        $this->get('/boekhouding')->assertForbidden();
    }

    public function test_books_dashboard_redirects_guest_to_login(): void
    {
        $this->get('/boekhouding')->assertRedirect('/boekhouding/login');
    }
}
