<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Pages\ManageIntegrationSettings;
use App\Integrations\Exact\Settings\ExactSettings;
use App\Models\User;
use App\Settings\ProviderSettings;
use App\Support\ProviderGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProviderAvailabilityToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_shows_the_stored_state_per_provider(): void
    {
        $this->storeToggles(['exact' => true, 'mollie' => false, 'snelstart' => false]);
        $this->actingAs($this->superAdmin());

        Livewire::test(ManageIntegrationSettings::class)
            ->assertOk()
            ->assertSet('data.enabled_exact', true)
            ->assertSet('data.enabled_mollie', false)
            ->assertSet('data.enabled_snelstart', false);
    }

    public function test_turning_a_provider_on_takes_effect_without_a_deploy(): void
    {
        $this->storeToggles(['exact' => true, 'mollie' => false, 'snelstart' => false]);
        $this->actingAs($this->superAdmin());

        $this->assertFalse(ProviderGate::enabled('mollie'));

        Livewire::test(ManageIntegrationSettings::class)
            ->set('data.enabled_mollie', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(app(ProviderSettings::class)->isEnabled('mollie'));
    }

    public function test_turning_a_provider_off_removes_it_from_the_connect_page(): void
    {
        $this->storeToggles(['exact' => true, 'mollie' => true, 'snelstart' => false]);
        $this->actingAs($this->superAdmin());

        Livewire::test(ManageIntegrationSettings::class)
            ->set('data.enabled_mollie', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(app(ProviderSettings::class)->isEnabled('mollie'));
    }

    public function test_saving_the_page_leaves_the_exact_credentials_alone(): void
    {
        $this->storeToggles(['exact' => true, 'mollie' => false, 'snelstart' => false]);
        $this->actingAs($this->superAdmin());

        Livewire::test(ManageIntegrationSettings::class)
            ->set('data.exact_client_id', 'client-123')
            ->set('data.enabled_mollie', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('client-123', app(ExactSettings::class)->client_id);
        $this->assertTrue(app(ProviderSettings::class)->isEnabled('mollie'));
    }

    public function test_a_staff_user_cannot_reach_the_page(): void
    {
        $this->assertTrue(ManageIntegrationSettings::canAccess() === false);
    }

    /** @param array<string, bool> $toggles */
    private function storeToggles(array $toggles): void
    {
        $settings = app(ProviderSettings::class);
        $settings->enabled = $toggles;
        $settings->save();
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'super-admin']);
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        return $user;
    }
}
