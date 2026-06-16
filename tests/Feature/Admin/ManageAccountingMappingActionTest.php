<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Connections\Pages\ListConnections;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Slice 3b — UI om de per-Connection boekhoud-mapping (metadata.accounting_mapping)
 * te beheren. Getest via de echte Filament-table-action (niet de logica direct).
 */
class ManageAccountingMappingActionTest extends TestCase
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

    private function makeExactConnection(array $state = []): Connection
    {
        $account = Account::factory()->for(Consumer::factory()->create())->create();

        return Connection::factory()->forExact()->for($account)->create($state);
    }

    public function test_action_visible_for_exact_connection(): void
    {
        $this->actingAs($this->makeStaffUser());

        Livewire::test(ListConnections::class)
            ->assertTableActionVisible('accountingMapping', $this->makeExactConnection());
    }

    public function test_action_hidden_for_mollie_connection(): void
    {
        $this->actingAs($this->makeStaffUser());
        $account = Account::factory()->for(Consumer::factory()->create())->create();
        $mollie = Connection::factory()->forMollie()->for($account)->create();

        Livewire::test(ListConnections::class)
            ->assertTableActionHidden('accountingMapping', $mollie);
    }

    public function test_action_saves_mapping_to_metadata(): void
    {
        $this->actingAs($this->makeStaffUser());
        $connection = $this->makeExactConnection();

        Livewire::test(ListConnections::class)
            ->callTableAction('accountingMapping', $connection, data: [
                'vat_21' => '4',
                'vat_9' => '2',
                'vat_0' => '1',
                'gl_accounts' => ['_default' => 'gl-def', 'omzet' => 'gl-omzet'],
                'relations' => ['ext-1' => 'cust-1'],
                'journal_sales' => '70',
                'journal_purchase' => '20',
                'journal_general' => '90',
            ])
            ->assertHasNoTableActionErrors();

        $connection->refresh();

        $this->assertSame([
            'vat_codes' => ['21' => '4', '9' => '2', '0' => '1'],
            'gl_accounts' => ['_default' => 'gl-def', 'omzet' => 'gl-omzet'],
            'relations' => ['ext-1' => 'cust-1'],
            'journals' => ['sales' => '70', 'purchase' => '20', 'general' => '90'],
        ], $connection->metadata['accounting_mapping']);
    }

    public function test_action_prefills_from_existing_metadata(): void
    {
        $this->actingAs($this->makeStaffUser());
        $connection = $this->makeExactConnection([
            'metadata' => ['accounting_mapping' => [
                'vat_codes' => ['21' => '4'],
                'journals' => ['sales' => '70'],
            ]],
        ]);

        Livewire::test(ListConnections::class)
            ->mountTableAction('accountingMapping', $connection)
            ->assertTableActionDataSet([
                'vat_21' => '4',
                'journal_sales' => '70',
            ]);
    }
}
