<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Consumer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Plan 08-04 Task 1 — bewijst dat ConsumerResource een View-page heeft met een
 * 'Wat is een Consumer?'-hint-Section bovenaan, met de canonical D-07 / UI-SPEC §S4 copy.
 */
class ConsumerInfolistHintTest extends TestCase
{
    use RefreshDatabase;

    private function seedRolesAndPermissions(): void
    {
        Role::firstOrCreate(['name' => 'super-admin']);
        Role::firstOrCreate(['name' => 'staff']);
        Permission::firstOrCreate(['name' => 'manage-consumers']);
    }

    private function actAsStaff(): User
    {
        $this->seedRolesAndPermissions();
        $user = User::factory()->create();
        $user->assignRole('staff');
        $user->givePermissionTo('manage-consumers');
        $this->actingAs($user);

        return $user;
    }

    public function test_view_consumer_page_renders_hint_section_heading_and_body(): void
    {
        $this->actAsStaff();

        $consumer = Consumer::factory()->create();

        $response = $this->get("/admin/consumers/{$consumer->id}");

        $response->assertOk();
        $response->assertSeeText('Wat is een Consumer?');
        $response->assertSeeText('Eén SaaS-app die de Hub gebruikt (Naschool, Planny, externe app). Authenticeert met een Bearer-PAT. Een Consumer heeft Accounts (zijn klanten) en die Accounts hebben Connections (partner-koppelingen).');
    }

    public function test_hint_section_is_collapsed_by_default(): void
    {
        $this->actAsStaff();
        $consumer = Consumer::factory()->create();

        $response = $this->get("/admin/consumers/{$consumer->id}");

        $response->assertOk();
        // Filament v4 emit `isCollapsed: true` in het Alpine x-data van een ->collapsed() Section.
        // We assert dat dit voorkomt NA de canonical hint-heading (geen false-positive uit
        // sidebar-groups die ook isCollapsed-state hebben).
        $response->assertSeeInOrder([
            'Wat is een Consumer?',
            'isCollapsed: true',
        ]);
    }

    public function test_infolist_renders_consumer_basic_fields(): void
    {
        $this->actAsStaff();
        $consumer = Consumer::factory()->create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        $response = $this->get("/admin/consumers/{$consumer->id}");

        $response->assertOk();
        $response->assertSeeText('Test Tenant');
        $response->assertSeeText('test-tenant');
    }

    public function test_view_consumer_route_is_registered(): void
    {
        $routes = collect(app('router')->getRoutes())
            ->map(fn ($r) => $r->uri())
            ->filter(fn (string $uri) => str_starts_with($uri, 'admin/consumers'))
            ->values();

        $this->assertContains('admin/consumers/{record}', $routes->all());
    }

    public function test_view_consumer_returns_403_for_user_without_permission(): void
    {
        $this->seedRolesAndPermissions();
        $user = User::factory()->create();
        // assign staff-rol maar GEEN manage-consumers-permission
        $user->assignRole('staff');
        $this->actingAs($user);

        $consumer = Consumer::factory()->create();

        $response = $this->get("/admin/consumers/{$consumer->id}");

        $response->assertForbidden();
    }
}
