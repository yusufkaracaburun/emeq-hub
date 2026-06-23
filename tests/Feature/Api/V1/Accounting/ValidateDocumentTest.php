<?php

namespace Tests\Feature\Api\V1\Accounting;

use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Emeq\ExactApi\Http\Request\Read\GetRelations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

class ValidateDocumentTest extends TestCase
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

        // De Exact-enrichment doet een live crm/Accounts-lookup; default: één match.
        $this->mockRelations([['ID' => 'rel-guid-1', 'Code' => 'C001', 'Name' => 'NL Leverancier BV']]);
    }

    protected function tearDown(): void
    {
        MockClient::destroyGlobal();

        parent::tearDown();
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function mockRelations(array $rows): void
    {
        MockClient::destroyGlobal();
        MockClient::global([
            GetRelations::class => MockResponse::make(['d' => ['results' => $rows]], 200),
        ]);
    }

    /**
     * @return array{0: Consumer}
     */
    private function consumerWithExactConnection(): array
    {
        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create([
            'external_id' => 'school1',
            'display_name' => 'School 1',
        ]);

        Connection::factory()->forExact()->create([
            'account_id' => $account->id,
            'status' => 'active',
            'expires_at' => now()->addSeconds(600),
            'metadata' => ['accounting_mapping' => ['vat_codes' => ['21' => '4']]],
        ]);

        return [$consumer];
    }

    public function test_clean_draft_is_valid_and_carries_exact_enrichment(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/documents/validate', [
                'type' => 'purchase_invoice',
                'currency' => 'EUR',
                'subtotal' => 100,
                'total' => 121,
                'party' => ['role' => 'creditor', 'name' => 'NL Leverancier BV', 'vat_number' => 'NL123456789B01', 'iban' => 'NL91ABNA0417164300'],
                'lines' => [['description' => 'Dienst', 'amount' => 100, 'tax_rate' => 21]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('valid', true)
            ->assertJsonPath('summary.errors', 0)
            ->assertJsonMissing(['code' => 'vat_number.checksum']) // geldig controlecijfer = geen finding
            ->assertJsonMissing(['code' => 'exact.vat_code.matched']) // gekoppeld tarief = geen ruis-finding
            ->assertJsonFragment(['code' => 'exact.relation.matched', 'suggestion' => 'rel-guid-1']);
    }

    public function test_invalid_vat_checksum_blocks_as_error(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        // NL001234567B01 heeft het juiste formaat maar faalt de 11-proef — Exact weigert dit
        // hard (HTTP 500). De dry-run spiegelt dat als error → valid=false, zodat de consument
        // niet alsnog gaat boeken en op een 422 stuk loopt.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/documents/validate', [
                'type' => 'purchase_invoice',
                'subtotal' => 100,
                'total' => 121,
                'party' => ['role' => 'creditor', 'name' => 'Bouwbedrijf Noord', 'vat_number' => 'NL001234567B01'],
                'lines' => [['description' => 'Dienst', 'amount' => 100, 'tax_rate' => 21]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('valid', false) // error blokkeert
            ->assertJsonFragment(['code' => 'vat_number.checksum', 'severity' => 'error']);
    }

    public function test_dirty_draft_returns_findings_and_suggestions(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/documents/validate', [
                'type' => 'purchase_invoice',
                'total' => 120, // 100 + 21 = 121 → mismatch, suggestie 121
                'party' => ['role' => 'creditor', 'name' => 'Swiss AG', 'vat_number' => 'CHE123456789', 'iban' => 'NL00BANK0123456789'],
                'lines' => [['description' => 'Import', 'amount' => 100, 'tax_rate' => 21]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('valid', false)
            ->assertJsonFragment(['code' => 'iban.checksum_invalid'])
            ->assertJsonFragment(['code' => 'vat_treatment.domestic_rate_on_non_eu'])
            ->assertJsonFragment(['code' => 'arithmetic.total_mismatch', 'suggestion' => 121]);
    }

    public function test_unmapped_vat_rate_is_flagged(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/documents/validate', [
                'type' => 'purchase_invoice',
                'party' => ['role' => 'creditor', 'name' => 'NL Leverancier BV', 'vat_number' => 'NL123456789B01'],
                'lines' => [['description' => 'Laag tarief', 'amount' => 100, 'tax_rate' => 9]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('valid', true) // warning blokkeert niet
            ->assertJsonFragment(['code' => 'exact.vat_code.unmapped', 'severity' => 'warning']);
    }

    public function test_new_supplier_when_no_exact_match(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;
        $this->mockRelations([]); // geen treffer

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/documents/validate', [
                'type' => 'purchase_invoice',
                'party' => ['role' => 'creditor', 'name' => 'Onbekende BV', 'vat_number' => 'NL123456789B01'],
                'lines' => [['description' => 'Dienst', 'amount' => 100, 'tax_rate' => 21]],
            ])
            ->assertStatus(200)
            ->assertJsonFragment(['code' => 'exact.relation.new']);
    }

    public function test_without_exact_ability_returns_403(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::MOLLIE_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/documents/validate', [
                'lines' => [['description' => 'A', 'amount' => 1, 'tax_rate' => 0]],
            ])
            ->assertStatus(403);
    }

    public function test_missing_account_header_returns_400(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/accounting/documents/validate', [
                'lines' => [['description' => 'A', 'amount' => 1, 'tax_rate' => 0]],
            ])
            ->assertStatus(400)
            ->assertJson(['error' => 'missing_account_header']);
    }
}
