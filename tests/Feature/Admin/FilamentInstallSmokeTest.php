<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Plan 09-02 Task 2 — smoke-test voor Filament v4 + Spatie permission install.
 *
 * Bewijst:
 *  - GET /admin/login rendert (Filament asset-pipeline + route-registratie werkt)
 *  - Spatie's 5 permission-tabellen bestaan na publish + migrate
 *  - GET /admin (unauthenticated) redirect naar /admin/login (Filament auth-gate)
 *
 * Geen User-trait of role-seeding — die landen pas in plan 09-03.
 */
class FilamentInstallSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_page_returns_200(): void
    {
        $response = $this->get('/admin/login');

        $response->assertOk();
    }

    public function test_spatie_permission_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('permissions'));
        $this->assertTrue(Schema::hasTable('roles'));
        $this->assertTrue(Schema::hasTable('model_has_permissions'));
        $this->assertTrue(Schema::hasTable('model_has_roles'));
        $this->assertTrue(Schema::hasTable('role_has_permissions'));
    }

    public function test_admin_panel_redirects_unauthenticated_to_login(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect('/admin/login');
    }
}
