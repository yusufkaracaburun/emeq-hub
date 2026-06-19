<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Connections\Pages\ListConnections;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\User;
use Emeq\ExactApi\Http\Request\RawExactRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Slice 3b + increment 1b — UI om de per-Connection boekhoud-mapping
 * (metadata.accounting_mapping) te beheren, met keuzelijsten gevuld uit live
 * Exact-referentiedata. Getest via de echte Filament-table-action.
 */
class ManageAccountingMappingActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.exact.client_id' => 'app_test_id',
            'services.exact.client_secret' => 'app_test_secret',
            'services.exact.redirect_uri' => 'https://hub.test/v1/oauth/exact/callback',
            'services.exact.auth_base_url' => 'https://start.exactonline.nl',
            'services.exact.api_base_url' => 'https://start.exactonline.nl',
        ]);
    }

    protected function tearDown(): void
    {
        MockClient::destroyGlobal();

        parent::tearDown();
    }

    /**
     * Onderschept de Exact-referentie-fetches die de form-schema doet, zodat tests
     * geen echte HTTP-calls maken. Lege results => keuzevelden vallen terug op tekst.
     *
     * @param  list<array<string, mixed>>  $results
     */
    private function mockExactReference(array $results = []): void
    {
        MockClient::global([
            RawExactRequest::class => MockResponse::make(['d' => ['results' => $results]], 200),
        ]);
    }

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
        $this->mockExactReference();
        $connection = $this->makeExactConnection();

        Livewire::test(ListConnections::class)
            ->callTableAction('accountingMapping', $connection, data: [
                'vat_21' => '4',
                'vat_9' => '2',
                'vat_0' => '1',
                'gl_accounts' => [
                    ['category' => '_default', 'value' => 'gl-def'],
                    ['category' => 'omzet', 'value' => 'gl-omzet'],
                ],
                'journal_sales' => '70',
                'journal_purchase' => '20',
                'journal_income' => '71',
                'journal_expense' => '21',
            ])
            ->assertHasNoTableActionErrors();

        $connection->refresh();

        $this->assertSame([
            'vat_codes' => ['21' => '4', '9' => '2', '0' => '1'],
            'gl_accounts' => ['_default' => 'gl-def', 'omzet' => 'gl-omzet'],
            'journals' => ['sales' => '70', 'purchase' => '20', 'income' => '71', 'expense' => '21'],
        ], $connection->metadata['accounting_mapping']);
    }

    public function test_action_prefills_from_existing_metadata(): void
    {
        $this->actingAs($this->makeStaffUser());
        $this->mockExactReference();
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

    public function test_action_saves_selected_vat_code_from_live_reference_data(): void
    {
        $this->actingAs($this->makeStaffUser());
        $this->mockExactReference([
            ['ID' => 'v1', 'Code' => '4', 'Description' => 'Hoog', 'Percentage' => 21],
            ['ID' => 'v2', 'Code' => '2', 'Description' => 'Laag', 'Percentage' => 9],
        ]);
        $connection = $this->makeExactConnection();

        // Met live VATCodes is vat_* een Select; een gekozen Code rondt correct af
        // naar de opslag-vorm die de resolver leest.
        Livewire::test(ListConnections::class)
            ->callTableAction('accountingMapping', $connection, data: [
                'vat_21' => '4',
                'vat_9' => '2',
            ])
            ->assertHasNoTableActionErrors();

        $connection->refresh();

        $this->assertSame(['21' => '4', '9' => '2'], $connection->metadata['accounting_mapping']['vat_codes']);
    }
}
