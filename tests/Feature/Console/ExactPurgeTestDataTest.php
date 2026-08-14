<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use Emeq\ExactApi\Http\Request\Delete\DeleteAccount;
use Emeq\ExactApi\Http\Request\Delete\DeleteDocument;
use Emeq\ExactApi\Http\Request\Delete\DeletePurchaseEntry;
use Emeq\ExactApi\Http\Request\Delete\DeleteSalesEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

class ExactPurgeTestDataTest extends TestCase
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

    private function exactConnection(): Connection
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();

        return Connection::factory()->forExact()->for($account)->create();
    }

    /**
     * @return list<MockResponse>
     */
    private function inventoryResponses(): array
    {
        return [
            MockResponse::make(['d' => ['results' => [
                ['EntryID' => 'se-1', 'EntryNumber' => 26800021, 'YourRef' => 'Emeq · f1'],
            ]]], 200),
            MockResponse::make(['d' => ['results' => []]], 200),
            MockResponse::make(['d' => ['results' => [
                ['ID' => 'doc-1', 'Subject' => 'Emeq · f1'],
            ]]], 200),
            MockResponse::make(['d' => ['results' => [
                ['ID' => 'acc-1', 'Code' => '1000007', 'Name' => 'Bouwbedrijf Noord'],
                ['ID' => 'tax-1', 'Code' => '1000002', 'Name' => 'Belastingdienst Omzetbelasting'],
            ]]], 200),
        ];
    }

    public function test_dry_run_lists_but_deletes_nothing(): void
    {
        $mock = MockClient::global($this->inventoryResponses());
        $connection = $this->exactConnection();

        $this->artisan('exact:purge-test-data', ['connection' => $connection->id])
            ->expectsOutputToContain('se-1')
            ->expectsOutputToContain('doc-1')
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();

        $mock->assertNotSent(DeleteSalesEntry::class);
        $mock->assertNotSent(DeleteDocument::class);
        $mock->assertNotSent(DeleteAccount::class);
    }

    public function test_force_deletes_entries_documents_and_explicit_relations_only(): void
    {
        $mock = MockClient::global([
            ...$this->inventoryResponses(),
            MockResponse::make([], 204), // delete sales se-1
            MockResponse::make([], 204), // delete document doc-1
            MockResponse::make([], 204), // delete relation acc-1
        ]);
        $connection = $this->exactConnection();

        $this->artisan('exact:purge-test-data', [
            'connection' => $connection->id,
            '--force' => true,
            '--relations' => 'acc-1',
        ])->assertSuccessful();

        $mock->assertSent(DeleteSalesEntry::class);
        $mock->assertSent(DeleteDocument::class);
        $mock->assertSent(DeleteAccount::class);
        $mock->assertNotSent(DeletePurchaseEntry::class); // 0 purchase entries
        // Belastingdienst (tax-1) niet opgegeven → niet verwijderd
        $mock->assertSent(fn ($request): bool => ! str_contains($request->resolveEndpoint(), 'tax-1'));

        // Documents blokkeren relaties bij Exact → moeten vóór de relatie-delete weg.
        $responses = $mock->getRecordedResponses();
        $documentIndex = null;
        $relationIndex = null;
        foreach ($responses as $index => $response) {
            $request = $response->getPendingRequest()->getRequest();
            if ($request instanceof DeleteDocument) {
                $documentIndex = $index;
            }
            if ($request instanceof DeleteAccount) {
                $relationIndex = $index;
            }
        }
        $this->assertNotNull($documentIndex);
        $this->assertNotNull($relationIndex);
        $this->assertLessThan($relationIndex, $documentIndex);
    }

    public function test_document_delete_failure_surfaces_exact_error_and_lets_the_rest_continue(): void
    {
        $mock = MockClient::global([
            ...$this->inventoryResponses(),
            MockResponse::make([], 204), // delete sales se-1
            MockResponse::make([
                'error' => ['code' => ['value' => 'AR13'], 'message' => ['lang' => 'en-US', 'value' => 'Kan niet verwijderen: Document - Gebruikt in: Bijlage (1)']],
            ], 500), // delete document doc-1 faalt
        ]);
        $connection = $this->exactConnection();

        $this->artisan('exact:purge-test-data', [
            'connection' => $connection->id,
            '--force' => true,
        ])
            ->expectsOutputToContain('Kan niet verwijderen: Document - Gebruikt in: Bijlage (1)')
            ->assertFailed();

        $mock->assertSent(DeleteSalesEntry::class);
        $mock->assertSent(DeleteDocument::class);
    }

    public function test_fails_for_non_exact_connection(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        $mollie = Connection::factory()->forMollie()->for($account)->create();

        $this->artisan('exact:purge-test-data', ['connection' => $mollie->id])
            ->assertFailed();
    }
}
