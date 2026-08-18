<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

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
