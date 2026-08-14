<?php

namespace Tests\Unit\Integrations\Exact\Accounting;

use App\Accounting\Validation\Severity;
use App\Integrations\Exact\Accounting\ConnectionMappingExactReferenceResolver;
use App\Integrations\Exact\Accounting\ExactRelationResolver;
use App\Integrations\Exact\Accounting\ExactReportEnricher;
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

    public function test_mapped_vat_rate_produces_no_finding(): void
    {
        // Een gekoppeld tarief is geen actiepunt — de interne VATCode zegt de consument niets.
        $findings = $this->enricher()->enrich(
            ['lines' => [['description' => 'A', 'amount' => 100, 'tax_rate' => 21]]],
            $this->connection(['21' => '4']),
        );

        $this->assertSame([], $findings);
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
        // Geen mapping → elk distinct ongekoppeld tarief levert één unmapped-finding (21 + 9, niet 3).
        $findings = $this->enricher()->enrich(
            ['lines' => [
                ['description' => 'A', 'amount' => 100, 'tax_rate' => 21],
                ['description' => 'B', 'amount' => 50, 'tax_rate' => 21],
                ['description' => 'C', 'amount' => 10, 'tax_rate' => 9],
            ]],
            $this->connection([]),
        );

        $this->assertCount(2, $findings);
        $this->assertSame(['exact.vat_code.unmapped', 'exact.vat_code.unmapped'], array_map(fn ($f) => $f->code, $findings));
    }

    public function test_reverse_charge_and_standard_are_keyed_separately(): void
    {
        // Alleen verlegd 21 is gemapt; standaard 21 niet → de behandelingen delen geen sleutel.
        $findings = $this->enricher()->enrich(
            ['lines' => [
                ['description' => 'Gewoon', 'amount' => 100, 'tax_rate' => 21],
                ['description' => 'Verlegd', 'amount' => 100, 'tax_rate' => 21, 'tax_treatment' => 'reverse_charge'],
            ]],
            $this->connection(['reverse_charge:21' => '6']),
        );

        // Standaard 21 is ongekoppeld → warning; verlegd 21 matcht → geen finding.
        $this->assertCount(1, $findings);
        $this->assertSame('exact.vat_code.unmapped', $findings[0]->code);
        $this->assertSame('lines.0.tax_rate', $findings[0]->path);
        $this->assertStringNotContainsString('verlegd', $findings[0]->message);
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

    public function test_unknown_relation_is_a_warning_without_create_if_missing(): void
    {
        // Zonder party.create_if_missing weigert de boeking met een 422 — de dry-run moet die
        // weigering spiegelen, niet als vrijblijvende info langskomen.
        $findings = $this->enricher()->enrich(
            ['party' => ['role' => 'creditor', 'name' => 'Acme BV'], 'lines' => []],
            $this->connection(['21' => '4']),
        );

        $relation = array_values(array_filter($findings, fn ($f) => $f->code === 'exact.relation.new'));
        $this->assertCount(1, $relation);
        $this->assertSame(Severity::Warning, $relation[0]->severity);
        $this->assertStringContainsString('geweigerd', $relation[0]->message);
    }

    public function test_unknown_relation_is_info_when_create_if_missing_requested(): void
    {
        $connection = new Connection;
        $connection->metadata = ['accounting_mapping' => ['vat_codes' => ['21' => '4']]];

        $findings = $this->enricher()->enrich(
            ['party' => ['role' => 'creditor', 'name' => 'Acme BV', 'create_if_missing' => true], 'lines' => []],
            $connection,
        );

        $relation = array_values(array_filter($findings, fn ($f) => $f->code === 'exact.relation.new'));
        $this->assertCount(1, $relation);
        $this->assertSame(Severity::Info, $relation[0]->severity);
        $this->assertStringContainsString('automatisch aangemaakt', $relation[0]->message);
    }
}
