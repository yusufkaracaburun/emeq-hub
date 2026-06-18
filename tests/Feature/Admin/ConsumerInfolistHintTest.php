<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Consumers\Pages\ViewConsumer;
use App\Models\Consumer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Bewijst dat ConsumerResource een View-page heeft met de 'Wat is een Consumer?'-
 * toelichting (canonical D-07 / UI-SPEC §S4 copy) achter het info-icoon-modal in de
 * paginaheader, niet inline op de pagina.
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

    public function test_view_consumer_page_exposes_concept_via_info_action(): void
    {
        $this->actAsStaff();

        $consumer = Consumer::factory()->create();

        Livewire::test(ViewConsumer::class, ['record' => $consumer->id])
            ->assertActionExists('info');
    }

    public function test_concept_is_behind_info_action_not_inline(): void
    {
        $this->actAsStaff();
        $consumer = Consumer::factory()->create();

        $response = $this->get("/admin/consumers/{$consumer->id}");

        $response->assertOk();
        // De toelichting staat niet inline op de pagina maar achter het info-icoon.
        $response->assertDontSeeText('Eén SaaS-app die de Hub gebruikt (Naschool, Planny, externe app).');
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
