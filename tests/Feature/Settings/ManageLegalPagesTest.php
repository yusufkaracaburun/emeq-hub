<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Filament\Pages\ManageLegalPages;
use App\Models\User;
use App\Settings\LegalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManageLegalPagesTest extends TestCase
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

        $this->assertTrue(ManageLegalPages::canAccess());
    }

    public function test_staff_cannot_access(): void
    {
        $this->actingAs($this->userWithRole('staff'));

        $this->assertFalse(ManageLegalPages::canAccess());
    }

    public function test_save_persists_both_texts_and_stamps_dates(): void
    {
        $this->actingAs($this->userWithRole('super-admin'));

        Livewire::test(ManageLegalPages::class)
            ->fillForm([
                'privacy_statement' => '# Nieuwe verklaring',
                'terms_statement' => '# Nieuwe voorwaarden',
                'dpa_statement' => '# Nieuwe verwerkersovereenkomst',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        app()->forgetInstance(LegalSettings::class);
        $legal = app(LegalSettings::class);

        $this->assertSame('# Nieuwe verklaring', $legal->privacy_statement);
        $this->assertSame('# Nieuwe voorwaarden', $legal->terms_statement);
        $this->assertSame('# Nieuwe verwerkersovereenkomst', $legal->dpa_statement);
        $this->assertSame(now()->toDateString(), $legal->privacy_updated_at);
        $this->assertSame(now()->toDateString(), $legal->terms_updated_at);
        $this->assertSame(now()->toDateString(), $legal->dpa_updated_at);
    }
}
