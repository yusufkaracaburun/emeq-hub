<?php

namespace Tests\Feature\Api\V1\Accounting;

use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Emeq\ExactApi\Http\Request\Read\GetRelations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
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
     * @param  array<string, mixed>  $mappingOverrides
     * @return array{0: Consumer}
     */
    private function consumerWithExactConnection(array $mappingOverrides = []): array
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
            'metadata' => ['accounting_mapping' => array_merge(['vat_codes' => ['21' => '4']], $mappingOverrides)],
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
                'party' => ['role' => 'creditor', 'name' => 'NL Leverancier BV', 'vat_number' => 'NL000099998B57', 'iban' => 'NL91ABNA0417164300'],
                'lines' => [['description' => 'Dienst', 'amount' => 100, 'tax_rate' => 21]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('valid', true)
            ->assertJsonPath('summary.errors', 0)
            ->assertJsonMissing(['code' => 'vat_number.checksum']) // geldig controlecijfer = geen finding
            ->assertJsonMissing(['code' => 'exact.vat_code.matched']) // gekoppeld tarief = geen ruis-finding
            ->assertJsonFragment(['code' => 'exact.relation.matched', 'suggestion' => 'rel-guid-1']);
    }

    /**
     * Een lege body kwam terug als `valid: true` met nul findings. `/validate` laat
     * per-veldproblemen bewust door de edge-validatie, omdat het vinden ervan de taak van
     * de inspector is — en die keek niet of er überhaupt iets te boeken viel. Een consumer
     * die de dry-run leest als "boekt dit?" kreeg groen voor een payload waar het boeken
     * met een 422 op weigert.
     */
    public function test_an_empty_body_is_not_reported_as_bookable(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/documents/validate', [])
            ->assertStatus(200)
            ->assertJsonPath('valid', false)
            ->assertJsonFragment(['code' => 'document.type.missing', 'severity' => 'error'])
            ->assertJsonFragment(['code' => 'document.party.missing', 'severity' => 'error'])
            ->assertJsonFragment(['code' => 'document.lines.missing', 'severity' => 'error']);
    }

    /**
     * Een veld zonder regel in ValidateDocumentRequest overleeft `validated()` niet, dus
     * zag de inspector `issue_date` nooit en meldde het als ontbrekend terwijl het in de
     * body stond. Ontdekt op prod, niet in de unit-test — die voedt de validator direct.
     */
    public function test_a_field_present_in_the_body_is_not_reported_as_missing(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/documents/validate', [
                'type' => 'purchase_invoice',
                'external_id' => 'invoice-1',
                'issue_date' => '2026-08-13',
                'party' => ['role' => 'creditor', 'name' => 'NL Leverancier BV', 'kind' => 'person', 'external_id' => 'nl-lev-1'],
                'lines' => [['description' => 'Dienst', 'amount' => 100, 'tax_rate' => 21]],
            ]);

        $response->assertStatus(200)->assertJsonPath('summary.warnings', 0);

        $codes = array_column($response->json('findings'), 'code');

        $this->assertNotContains('document.issue_date.missing', $codes);
        $this->assertNotContains('document.external_id.missing', $codes);
    }

    public function test_a_document_type_the_booking_rejects_is_an_error(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/documents/validate', [
                'type' => 'not_a_type',
                'party' => ['role' => 'creditor', 'name' => 'NL Leverancier BV'],
                'lines' => [['description' => 'Dienst', 'amount' => 100, 'tax_rate' => 21]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('valid', false)
            ->assertJsonFragment(['code' => 'document.type.unknown', 'severity' => 'error']);
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
            // Gemengd geval: 2 errors (altijd blocking) + 1 advisory warning
            // (arithmetic.total_mismatch, niet blocking — `total` bestaat niet op het
            // boekcontract) → blocking telt de 2 errors, niet de warning erbij.
            ->assertJsonPath('summary.blocking', 2)
            ->assertJsonFragment(['code' => 'iban.checksum_invalid', 'blocking' => true])
            ->assertJsonFragment(['code' => 'vat_treatment.domestic_rate_on_non_eu', 'blocking' => true])
            ->assertJsonFragment(['code' => 'arithmetic.total_mismatch', 'suggestion' => 121, 'blocking' => false]);
    }

    public function test_unmapped_vat_rate_is_flagged(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/documents/validate', [
                'type' => 'purchase_invoice',
                'party' => ['role' => 'creditor', 'name' => 'NL Leverancier BV', 'vat_number' => 'NL000099998B57'],
                'lines' => [['description' => 'Laag tarief', 'amount' => 100, 'tax_rate' => 9]],
            ])
            ->assertStatus(200)
            // Severity blijft `warning` — zo erg is een ontbrekende mapping niet — maar de
            // boeking strandt er wél op, dus `valid` staat op false.
            ->assertJsonPath('valid', false)
            ->assertJsonPath('summary.errors', 0)
            ->assertJsonPath('summary.blocking', 1)
            ->assertJsonFragment(['code' => 'exact.vat_code.unmapped', 'severity' => 'warning', 'blocking' => true]);
    }

    public function test_new_supplier_is_reported_as_info_since_the_hub_creates_it(): void
    {
        // De ladder maakt de relatie zelf aan wanneer niets matcht — geen 422 meer op
        // "onbekende relatie", dus de dry-run meldt dat als Info, niet blocking.
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;
        $this->mockRelations([]); // geen treffer

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/documents/validate', [
                'type' => 'purchase_invoice',
                'party' => ['role' => 'creditor', 'name' => 'Onbekende BV', 'vat_number' => 'NL000099998B57'],
                'lines' => [['description' => 'Dienst', 'amount' => 100, 'tax_rate' => 21]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('valid', true)
            ->assertJsonPath('summary.blocking', 0)
            ->assertJsonFragment(['code' => 'exact.relation.new', 'severity' => 'info', 'blocking' => false]);
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

    /**
     * De enrichment doet een live Exact-call (relatie-lookup). Die liep vroeger óók
     * met de kill-switch uit. Nu degradeert het rapport naar de agnostische findings
     * in plaats van de partner te bellen — een dry-run hoort read-only én stil te zijn.
     */
    public function test_enrichment_is_skipped_and_no_partner_call_is_made_when_the_provider_is_off(): void
    {
        Feature::define('provider-exact-enabled', fn () => false);
        MockClient::global([]);

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/documents/validate', [
                'lines' => [['description' => 'A', 'amount' => 100, 'tax_rate' => 21]],
            ]);

        $response->assertOk();

        MockClient::global()->assertNothingSent();

        foreach ($response->json('findings') as $finding) {
            $this->assertStringStartsNotWith('exact.', (string) $finding['code']);
        }
    }
}
