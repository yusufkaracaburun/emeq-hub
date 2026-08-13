<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Connections\Pages\ViewConnection;
use App\Models\Account;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Models\Consumer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConnectionDetailTabsTest extends TestCase
{
    use RefreshDatabase;

    private function makeStaffUser(): User
    {
        Role::firstOrCreate(['name' => 'staff']);
        Permission::firstOrCreate(['name' => 'manage-connections']);
        $user = User::factory()->create();
        $user->assignRole('staff');
        $user->givePermissionTo('manage-connections');

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeConnection(string $state, array $attributes = []): Connection
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();

        return Connection::factory()->{$state}()->for($account)->create($attributes);
    }

    public function test_page_shows_every_tab_including_the_relation_managers(): void
    {
        $this->actingAs($this->makeStaffUser());

        $exact = $this->makeConnection('forExact');
        $exact->accountingRefs()->create([
            'kind' => ConnectionAccountingRef::KIND_GL,
            'code' => '8000',
            'native_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'label' => 'Omzet',
            'synced_at' => now(),
        ]);

        Livewire::test(ViewConnection::class, ['record' => $exact->getKey()])
            ->assertSuccessful()
            ->assertSee('Overzicht')
            ->assertSee('Toegang')
            ->assertSee('Boekhoud-mapping')
            ->assertSee('Boekhoud-referentiedata')
            ->assertSee('Pass-through calls')
            ->assertSee('Inbound webhooks');
    }

    public function test_exact_access_tab_explains_the_absent_scopes_and_lists_the_whitelist(): void
    {
        $this->actingAs($this->makeStaffUser());

        Livewire::test(ViewConnection::class, ['record' => $this->makeConnection('forExact')->getKey()])
            ->assertSee('Exact geeft geen scopes mee in de token.', escape: false)
            ->assertSee('salesentry/SalesEntries')
            ->assertSee('Verkoopboekingen');
    }

    public function test_mollie_access_tab_lists_the_granted_scopes_with_an_explanation(): void
    {
        $this->actingAs($this->makeStaffUser());
        $mollie = $this->makeConnection('forMollie', ['scopes' => ['payments.read', 'organizations.read']]);

        Livewire::test(ViewConnection::class, ['record' => $mollie->getKey()])
            ->assertSee('payments.read')
            ->assertSee('Betalingen inzien')
            ->assertSee('organizations.read');
    }

    public function test_snelstart_access_tab_explains_that_there_are_no_scopes(): void
    {
        $this->actingAs($this->makeStaffUser());

        Livewire::test(ViewConnection::class, ['record' => $this->makeConnection('forSnelstart')->getKey()])
            ->assertSee('clientkey', escape: false)
            ->assertDontSee('Resources die de Hub doorlaat');
    }

    public function test_accounting_mapping_tab_renders_the_mapped_values(): void
    {
        $this->actingAs($this->makeStaffUser());
        $exact = $this->makeConnection('forExact', [
            'metadata' => [
                'accounting_mapping' => [
                    'journals' => ['sales_invoice' => '70'],
                    'vat_codes' => ['21' => '4'],
                ],
            ],
        ]);

        Livewire::test(ViewConnection::class, ['record' => $exact->getKey()])
            ->assertSee('sales_invoice')
            ->assertSee('BTW-tarief → VATCode', escape: false);
    }
}
