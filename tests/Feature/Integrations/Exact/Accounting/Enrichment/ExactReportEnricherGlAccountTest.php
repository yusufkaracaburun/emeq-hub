<?php

namespace Tests\Feature\Integrations\Exact\Accounting\Enrichment;

use App\Accounting\BookingWarnings;
use App\Accounting\Validation\Finding;
use App\Accounting\Validation\Severity;
use App\Integrations\Exact\Accounting\ConnectionMappingExactReferenceResolver;
use App\Integrations\Exact\Accounting\ExactRelationResolver;
use App\Integrations\Exact\Accounting\ExactReportEnricher;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExactReportEnricherGlAccountTest extends TestCase
{
    use RefreshDatabase;

    private function enricher(): ExactReportEnricher
    {
        return new ExactReportEnricher(new ConnectionMappingExactReferenceResolver(new ExactRelationResolver(new BookingWarnings)));
    }

    /** @param  array<string, string>  $glAccounts */
    private function connection(array $glAccounts): Connection
    {
        $account = Account::factory()->for(Consumer::factory()->create())->create();

        return Connection::factory()->forExact()->for($account)->create([
            'metadata' => ['accounting_mapping' => ['gl_accounts' => $glAccounts]],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<Finding>
     */
    private function glFindings(array $lines, Connection $connection, string $type = 'purchase_invoice'): array
    {
        return array_values(array_filter(
            $this->enricher()->enrich(['type' => $type, 'lines' => $lines], $connection),
            fn (Finding $f): bool => str_starts_with($f->code, 'exact.gl_account.'),
        ));
    }

    public function test_mapped_category_produces_no_finding(): void
    {
        $findings = $this->glFindings(
            [['description' => 'A', 'amount' => 100, 'category' => 'huisvesting']],
            $this->connection(['huisvesting' => '4300']),
        );

        $this->assertSame([], $findings);
    }

    public function test_unmapped_category_with_suspense_is_a_non_blocking_warning_naming_the_suspense_account(): void
    {
        $findings = $this->glFindings(
            [
                ['description' => 'A', 'amount' => 100, 'category' => 'huisvesting'],
                ['description' => 'B', 'amount' => 50, 'category' => 'huisvesting'],
            ],
            $this->connection(['suspense' => '2999', 'purchase_default' => '4999']),
        );

        $this->assertCount(1, $findings);
        $this->assertSame('exact.gl_account.unmapped_category', $findings[0]->code);
        $this->assertSame(Severity::Warning, $findings[0]->severity);
        $this->assertFalse($findings[0]->blocking);
        $this->assertSame('lines.0.category', $findings[0]->path);
        $this->assertStringContainsString("'huisvesting'", $findings[0]->message);
        $this->assertStringContainsString('2999', $findings[0]->message);
        $this->assertSame('huisvesting', $findings[0]->current);
    }

    public function test_unmapped_category_without_suspense_is_a_blocking_error_even_with_defaults(): void
    {
        $findings = $this->glFindings(
            [
                ['description' => 'A', 'amount' => 100, 'category' => 'huisvesting'],
                ['description' => 'B', 'amount' => 50, 'category' => 'huisvesting'],
            ],
            $this->connection(['purchase_default' => '4999', '_default' => '8000']),
        );

        $this->assertCount(1, $findings);
        $this->assertSame('exact.gl_account.unmapped_category', $findings[0]->code);
        $this->assertSame(Severity::Error, $findings[0]->severity);
        $this->assertTrue($findings[0]->blocking);
        $this->assertStringContainsString("'huisvesting'", $findings[0]->message);
        $this->assertStringContainsString('gl_accounts.suspense', $findings[0]->message);
    }

    public function test_missing_default_is_blocking(): void
    {
        $findings = $this->glFindings(
            [['description' => 'A', 'amount' => 100]],
            $this->connection(['sales_default' => '8000', 'suspense' => '2999']),
        );

        $this->assertCount(1, $findings);
        $this->assertSame('exact.gl_account.missing_default', $findings[0]->code);
        $this->assertSame(Severity::Warning, $findings[0]->severity);
        $this->assertTrue($findings[0]->blocking);
        $this->assertSame('lines.0.category', $findings[0]->path);
        $this->assertStringContainsString('gl_accounts.purchase_default', $findings[0]->message);
    }

    public function test_line_without_category_and_without_default_is_blocking_once(): void
    {
        $findings = $this->glFindings(
            [
                ['description' => 'A', 'amount' => 100],
                ['description' => 'B', 'amount' => 50],
            ],
            $this->connection([]),
            'sales_invoice',
        );

        $this->assertCount(1, $findings);
        $this->assertSame('exact.gl_account.missing_default', $findings[0]->code);
        $this->assertTrue($findings[0]->blocking);
        $this->assertStringContainsString('gl_accounts.sales_default', $findings[0]->message);
    }

    public function test_no_gl_findings_without_a_known_document_type(): void
    {
        $findings = $this->glFindings(
            [['description' => 'A', 'amount' => 100]],
            $this->connection([]),
            'onbekend',
        );

        $this->assertSame([], $findings);
    }
}
