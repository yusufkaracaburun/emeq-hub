<?php

namespace Tests\Feature\Api\V1\Accounting;

use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValidateDocumentTest extends TestCase
{
    use RefreshDatabase;

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
        ]);

        return [$consumer];
    }

    public function test_clean_draft_is_valid(): void
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
            ->assertJsonCount(0, 'findings');
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
