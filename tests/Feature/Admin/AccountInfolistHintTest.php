<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Models\Account;
use App\Models\Consumer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountInfolistHintTest extends TestCase
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

    public function test_view_account_page_exposes_concept_via_info_action(): void
    {
        $this->actAsStaff();

        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();

        Livewire::test(ViewAccount::class, ['record' => $account->id])
            ->assertActionExists('info');
    }

    public function test_concept_is_behind_info_action_not_inline(): void
    {
        $this->actAsStaff();
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();

        $response = $this->get("/admin/accounts/{$account->id}");

        $response->assertOk();
        $response->assertDontSeeText('Een klant van een Consumer (bv. school A bij Naschool). Niet de individuele eindgebruiker/ouder.');
    }

    public function test_existing_account_fields_still_render(): void
    {
        $this->actAsStaff();

        $consumer = Consumer::factory()->create(['slug' => 'naschool']);
        $account = Account::factory()->for($consumer)->create([
            'external_id' => 'school1',
            'display_name' => 'School A',
        ]);

        $response = $this->get("/admin/accounts/{$account->id}");

        $response->assertOk();
        $response->assertSeeText('naschool');
        $response->assertSeeText('school1');
        $response->assertSeeText('School A');
    }

    public function test_koppelingen_navgroup_has_canonical_tooltip(): void
    {
        $this->actAsStaff();

        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertSeeInOrder([
            'data-group-label="Koppelingen"',
            'SaaS-apps (Consumer) → Accounts → partner-Connections + audit',
        ]);
    }
}
