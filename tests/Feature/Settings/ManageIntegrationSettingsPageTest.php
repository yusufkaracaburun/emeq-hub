<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Filament\Pages\ManageIntegrationSettings;
use App\Integrations\Exact\Settings\ExactSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManageIntegrationSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        Role::firstOrCreate(['name' => $role]);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_super_admin_can_access(): void
    {
        $this->actingAs($this->userWithRole('super-admin'));

        $this->assertTrue(ManageIntegrationSettings::canAccess());
    }

    public function test_staff_cannot_access(): void
    {
        $this->actingAs($this->userWithRole('staff'));

        $this->assertFalse(ManageIntegrationSettings::canAccess());
    }

    public function test_save_persists_settings(): void
    {
        $this->actingAs($this->userWithRole('super-admin'));

        Livewire::test(ManageIntegrationSettings::class)
            ->fillForm([
                'exact_client_id' => 'new-cid',
                'exact_client_secret' => 'new-secret',
                'exact_redirect_uri' => 'https://hub.test/v1/oauth/exact/callback',
                'exact_webhook_secret' => 'new-wh',
                'exact_auth_base_url' => 'https://start.exactonline.nl',
                'exact_api_base_url' => 'https://start.exactonline.nl',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        app()->forgetInstance(ExactSettings::class);
        $this->assertSame('new-cid', app(ExactSettings::class)->client_id);
        $this->assertSame('new-secret', app(ExactSettings::class)->client_secret);
    }
}
