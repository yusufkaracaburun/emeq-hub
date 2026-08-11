<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Integrations\Exact\Accounting\ExactReferenceSync;
use App\Models\Account;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Models\Consumer;
use Emeq\ExactApi\Http\Request\Read\GetCostCenters;
use Emeq\ExactApi\Http\Request\Read\GetCostUnits;
use Emeq\ExactApi\Http\Request\Read\GetGlAccounts;
use Emeq\ExactApi\Http\Request\Read\GetJournals;
use Emeq\ExactApi\Http\Request\Read\GetVatCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

class ExactReferenceSyncTest extends TestCase
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

    private function makeExactConnection(): Connection
    {
        $account = Account::factory()->for(Consumer::factory()->create())->create();

        return Connection::factory()->forExact()->for($account)->create();
    }

    private function mockReferenceData(): void
    {
        MockClient::global([
            GetGlAccounts::class => MockResponse::make(['d' => ['results' => [
                ['ID' => 'gl-omzet-guid', 'Code' => '8000', 'Description' => 'Omzet'],
                ['ID' => 'gl-kosten-guid', 'Code' => '4000', 'Description' => 'Kosten'],
            ]]], 200),
            GetVatCodes::class => MockResponse::make(['d' => ['results' => [
                ['Code' => '3', 'Description' => 'Hoog excl', 'Percentage' => 0.21],
                ['Code' => '1', 'Description' => 'Laag excl', 'Percentage' => 0.09],
            ]]], 200),
            GetJournals::class => MockResponse::make(['d' => ['results' => [
                ['Code' => '80', 'Description' => 'Verkoopboek', 'Type' => 20],
                ['Code' => '70', 'Description' => 'Inkoopboek', 'Type' => 22],
            ]]], 200),
            GetCostCenters::class => MockResponse::make(['d' => ['results' => [
                ['Code' => 'ADMIN', 'Description' => 'Administratie'],
                ['Code' => 'SALES', 'Description' => 'Verkoop'],
            ]]], 200),
            GetCostUnits::class => MockResponse::make(['d' => ['results' => [
                ['Code' => 'PROJ-X', 'Description' => 'Project X'],
            ]]], 200),
        ]);
    }

    public function test_sync_mirrors_gl_vat_journals_into_refs(): void
    {
        $this->mockReferenceData();
        $connection = $this->makeExactConnection();

        $count = app(ExactReferenceSync::class)->sync($connection);

        $this->assertSame(9, $count);

        $gl = ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->where('kind', ConnectionAccountingRef::KIND_GL)
            ->where('code', '8000')
            ->firstOrFail();
        $this->assertSame('gl-omzet-guid', $gl->native_id);
        $this->assertSame('Omzet', $gl->label);
        $this->assertNotNull($gl->synced_at);

        $vat = ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->where('kind', ConnectionAccountingRef::KIND_VAT)
            ->where('code', '3')
            ->firstOrFail();
        $this->assertSame('3', $vat->native_id);
        $this->assertEquals(21.0, $vat->attrs['percentage']);

        $journal = ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->where('kind', ConnectionAccountingRef::KIND_JOURNAL)
            ->where('code', '80')
            ->firstOrFail();
        $this->assertSame(20, $journal->attrs['type']);

        // Kostenplaats/-drager: native_id = Code (Exact boekt op Code, niet GUID).
        $costCenter = ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->where('kind', ConnectionAccountingRef::KIND_COST_CENTER)
            ->where('code', 'ADMIN')
            ->firstOrFail();
        $this->assertSame('ADMIN', $costCenter->native_id);
        $this->assertSame('Administratie', $costCenter->label);

        $costUnit = ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->where('kind', ConnectionAccountingRef::KIND_COST_UNIT)
            ->where('code', 'PROJ-X')
            ->firstOrFail();
        $this->assertSame('PROJ-X', $costUnit->native_id);
    }

    public function test_sync_is_idempotent_and_prunes_disappeared_codes(): void
    {
        $connection = $this->makeExactConnection();

        // Een verouderde GL-Code die in de volgende sync verdwijnt + een lazy relatie die blijft.
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_GL,
            'code' => '9999',
            'native_id' => 'stale-guid',
            'synced_at' => now()->subDay(),
        ]);
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 'KLANT-1',
            'native_id' => 'relation-guid',
            'synced_at' => now()->subDay(),
        ]);

        $this->mockReferenceData();
        app(ExactReferenceSync::class)->sync($connection);

        // Verdwenen GL-Code gepruned, relatie ongemoeid, geen duplicaten.
        $this->assertDatabaseMissing('connection_accounting_refs', [
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_GL,
            'code' => '9999',
        ]);
        $this->assertDatabaseHas('connection_accounting_refs', [
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 'KLANT-1',
        ]);
        $this->assertSame(2, ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->where('kind', ConnectionAccountingRef::KIND_GL)
            ->count());
    }
}
