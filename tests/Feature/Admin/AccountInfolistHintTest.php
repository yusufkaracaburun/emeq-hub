<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Account;
use App\Models\Consumer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Plan 08-04 Task 2 — bewijst dat AccountInfolist een 'Wat is een Account?'-hint-Section
 * heeft bovenaan met de canonical D-07 / UI-SPEC §S4 copy, EN dat de Tenants-navgroup
 * in het admin-paneel een tooltip (title-attribuut) heeft met de canonical uitleg.
 */
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

    public function test_view_account_page_renders_hint_section_heading_and_body(): void
    {
        $this->actAsStaff();

        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();

        $response = $this->get("/admin/accounts/{$account->id}");

        $response->assertOk();
        $response->assertSeeText('Wat is een Account?');
        $response->assertSeeText('Een klant van een Consumer (bv. school A bij Naschool). Niet de individuele eindgebruiker/ouder.');
    }

    public function test_hint_section_is_collapsed_by_default(): void
    {
        $this->actAsStaff();
        $account = Account::factory()->create();

        $response = $this->get("/admin/accounts/{$account->id}");

        $response->assertOk();
        // Filament v4 emit `isCollapsed:  true` (let op: dubbele spatie via @js-helper) in het
        // Alpine x-data van een ->collapsed() Section. `x-data` zit op de outer <section>,
        // vóór de heading-tekst in de DOM. Volgorde-assertie scope-t aan de hint-Section.
        $html = $response->getContent();
        $this->assertNotFalse(
            strpos((string) $html, 'isCollapsed:  true'),
            'Verwacht `isCollapsed:  true` in x-data van de hint-Section (Section is niet default-collapsed).'
        );
        $response->assertSeeInOrder([
            'isCollapsed:  true',
            'Wat is een Account?',
        ]);
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

    public function test_tenants_navgroup_has_canonical_tooltip(): void
    {
        $this->actAsStaff();

        $response = $this->get('/admin');

        $response->assertOk();
        // Sidebar-`<li>` voor groep "Tenants" heeft data-group-label="Tenants" + title-attribuut
        // (extraSidebarAttributes(['title' => ...]) op NavigationGroup). Volgorde-assertie
        // voorkomt false-positive match in body-content.
        $response->assertSeeInOrder([
            'data-group-label="Tenants"',
            'SaaS-apps (Consumer) → hun klanten (Account) → partner-koppelingen (Connection)',
        ]);
    }
}
