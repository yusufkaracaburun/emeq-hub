<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Filament\Pages\ManageIntegrationSettings;
use App\Models\User;
use App\Settings\ExactSettings;
use App\Settings\MollieSettings;
use App\Settings\SnelstartSettings;
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
                'mollie_connect_client_id' => 'm-cid',
                'mollie_connect_client_secret' => 'm-secret',
                'mollie_connect_redirect_uri' => 'https://hub.test/v1/oauth/mollie/callback',
                'mollie_partner_access_token' => 'm-token',
                'snelstart_webhook_secret' => 'sns-wh',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        app()->forgetInstance(ExactSettings::class);
        app()->forgetInstance(MollieSettings::class);
        app()->forgetInstance(SnelstartSettings::class);
        $this->assertSame('new-cid', app(ExactSettings::class)->client_id);
        $this->assertSame('new-secret', app(ExactSettings::class)->client_secret);
        $this->assertSame('m-token', app(MollieSettings::class)->partner_access_token);
        $this->assertSame('sns-wh', app(SnelstartSettings::class)->webhook_secret);
    }
}
