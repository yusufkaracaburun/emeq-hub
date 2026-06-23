<?php

namespace Tests\Unit\Accounting\Validation\Enrichment;

use App\Accounting\Exact\ConnectionMappingExactReferenceResolver;
use App\Accounting\Exact\ExactRelationResolver;
use App\Accounting\Validation\Enrichment\ExactReportEnricher;
use App\Accounting\Validation\Severity;
use App\Models\Connection;
use Tests\TestCase;

class ExactReportEnricherTest extends TestCase
{
    private function enricher(): ExactReportEnricher
    {
        return new ExactReportEnricher(new ConnectionMappingExactReferenceResolver(new ExactRelationResolver));
    }

    /**
     * Connection zonder administratie_id → ExactReferenceData::fetch() levert [] (geen live
     * call), zodat deze unit-tests de pure VATCode-logica isoleren. De relatie-lookup tegen
     * een echte Exact-response loopt via ValidateDocumentTest (MockClient).
     *
     * @param  array<string, string>  $vatCodes
     */
    private function connection(array $vatCodes): Connection
    {
        $connection = new Connection;
        $connection->metadata = ['accounting_mapping' => ['vat_codes' => $vatCodes]];

        return $connection;
    }

    public function test_maps_known_vat_rate_to_code(): void
    {
        $findings = $this->enricher()->enrich(
            ['lines' => [['description' => 'A', 'amount' => 100, 'tax_rate' => 21]]],
            $this->connection(['21' => '4']),
        );

        $this->assertCount(1, $findings);
        $this->assertSame('exact.vat_code.matched', $findings[0]->code);
        $this->assertSame(Severity::Info, $findings[0]->severity);
        $this->assertSame('4', $findings[0]->suggestion);
        $this->assertSame('lines.0.tax_rate', $findings[0]->path);
    }

    public function test_flags_unmapped_vat_rate_as_warning(): void
    {
        $findings = $this->enricher()->enrich(
            ['lines' => [['description' => 'A', 'amount' => 100, 'tax_rate' => 9]]],
            $this->connection(['21' => '4']),
        );

        $this->assertCount(1, $findings);
        $this->assertSame('exact.vat_code.unmapped', $findings[0]->code);
        $this->assertSame(Severity::Warning, $findings[0]->severity);
        $this->assertNull($findings[0]->suggestion);
    }

    public function test_dedupes_vat_findings_per_distinct_rate(): void
    {
        $findings = $this->enricher()->enrich(
            ['lines' => [
                ['description' => 'A', 'amount' => 100, 'tax_rate' => 21],
                ['description' => 'B', 'amount' => 50, 'tax_rate' => 21],
                ['description' => 'C', 'amount' => 10, 'tax_rate' => 9],
            ]],
            $this->connection(['21' => '4', '9' => '2']),
        );

        $this->assertCount(2, $findings); // 21% + 9%, niet 3
        $this->assertSame(['exact.vat_code.matched', 'exact.vat_code.matched'], array_map(fn ($f) => $f->code, $findings));
    }

    public function test_reverse_charge_line_matches_verlegd_code_and_distinguishes_from_standard(): void
    {
        $findings = $this->enricher()->enrich(
            ['lines' => [
                ['description' => 'Gewoon', 'amount' => 100, 'tax_rate' => 21],
                ['description' => 'Verlegd', 'amount' => 100, 'tax_rate' => 21, 'tax_treatment' => 'reverse_charge'],
            ]],
            $this->connection(['21' => '3', 'reverse_charge:21' => '6']),
        );

        // Twee findings: standard 21 → '3' én verlegd 21 → '6' (niet gededupt op enkel tarief).
        $this->assertCount(2, $findings);
        $this->assertSame('3', $findings[0]->suggestion);
        $this->assertSame('exact.vat_code.matched', $findings[1]->code);
        $this->assertSame('6', $findings[1]->suggestion);
        $this->assertStringContainsString('verlegd', $findings[1]->message);
    }

    public function test_unmapped_reverse_charge_does_not_fall_back_to_standard_code(): void
    {
        $findings = $this->enricher()->enrich(
            ['lines' => [['description' => 'Verlegd', 'amount' => 100, 'tax_rate' => 21, 'tax_treatment' => 'reverse_charge']]],
            $this->connection(['21' => '3']), // alleen standaard gemapt
        );

        $this->assertCount(1, $findings);
        $this->assertSame('exact.vat_code.unmapped', $findings[0]->code);
        $this->assertSame(Severity::Warning, $findings[0]->severity);
    }

    public function test_skips_non_numeric_tax_rate(): void
    {
        $findings = $this->enricher()->enrich(
            ['lines' => [['description' => 'A', 'amount' => 100, 'tax_rate' => 'n.v.t.']]],
            $this->connection(['21' => '4']),
        );

        $this->assertSame([], $findings);
    }

    public function test_no_relation_finding_without_party(): void
    {
        $findings = $this->enricher()->enrich(
            ['lines' => [['description' => 'A', 'amount' => 100, 'tax_rate' => 21]]],
            $this->connection(['21' => '4']),
        );

        $relation = array_values(array_filter($findings, fn ($f) => str_starts_with($f->code, 'exact.relation.')));
        $this->assertSame([], $relation);
    }

    public function test_relation_label_follows_party_role(): void
    {
        foreach (['debtor' => 'Afnemer', 'creditor' => 'Leverancier'] as $role => $label) {
            $findings = $this->enricher()->enrich(
                ['party' => ['role' => $role, 'name' => 'Acme BV'], 'lines' => []],
                $this->connection(['21' => '4']),
            );

            $relation = array_values(array_filter($findings, fn ($f) => $f->code === 'exact.relation.new'));
            $this->assertCount(1, $relation, "verwacht relation.new-finding voor rol {$role}");
            $this->assertStringStartsWith($label, $relation[0]->message);
        }
    }
}
