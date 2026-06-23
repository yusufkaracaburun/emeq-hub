<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Connections\Pages\ViewConnection;
use App\Filament\Resources\Connections\RelationManagers\AccountingRefsRelationManager;
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

class AccountingRefsRelationManagerTest extends TestCase
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

    private function exactConnectionWithRefs(): Connection
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forExact()->for($account)->create();

        $connection->accountingRefs()->create([
            'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 'c1',
            'native_id' => '032968b7-cdcc-4f83-abbb-8463e6374207',
            'label' => 'Bouwbedrijf Noord',
            'synced_at' => now(),
        ]);
        $connection->accountingRefs()->create([
            'kind' => ConnectionAccountingRef::KIND_GL,
            'code' => '8000',
            'native_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'label' => 'Omzet',
            'synced_at' => now(),
        ]);

        return $connection;
    }

    public function test_renders_relation_label_and_guid_for_a_learned_relation(): void
    {
        $admin = $this->makeStaffUser();
        $connection = $this->exactConnectionWithRefs();

        $this->actingAs($admin);

        Livewire::test(AccountingRefsRelationManager::class, [
            'ownerRecord' => $connection,
            'pageClass' => ViewConnection::class,
        ])
            ->assertSuccessful()
            ->assertSee('Bouwbedrijf Noord')
            ->assertSee('032968b7-cdcc-4f83-abbb-8463e6374207');
    }

    public function test_kind_filter_narrows_to_relations_only(): void
    {
        $admin = $this->makeStaffUser();
        $connection = $this->exactConnectionWithRefs();

        $this->actingAs($admin);

        Livewire::test(AccountingRefsRelationManager::class, [
            'ownerRecord' => $connection,
            'pageClass' => ViewConnection::class,
        ])
            ->filterTable('kind', ConnectionAccountingRef::KIND_RELATION)
            ->assertSee('Bouwbedrijf Noord')
            ->assertDontSee('Omzet');
    }

    public function test_hidden_for_connection_without_accounting_refs(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        $mollie = Connection::factory()->forMollie()->for($account)->create();

        $this->assertFalse(
            AccountingRefsRelationManager::canViewForRecord($mollie, ViewConnection::class),
        );
    }

    public function test_visible_for_connection_with_accounting_refs(): void
    {
        $connection = $this->exactConnectionWithRefs();

        $this->assertTrue(
            AccountingRefsRelationManager::canViewForRecord($connection, ViewConnection::class),
        );
    }
}
