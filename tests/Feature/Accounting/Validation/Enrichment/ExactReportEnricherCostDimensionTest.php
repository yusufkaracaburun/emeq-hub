<?php

namespace Tests\Feature\Accounting\Validation\Enrichment;

use App\Accounting\BookingWarnings;
use App\Accounting\Validation\Finding;
use App\Accounting\Validation\Severity;
use App\Integrations\Exact\Accounting\ConnectionMappingExactReferenceResolver;
use App\Integrations\Exact\Accounting\ExactRelationResolver;
use App\Integrations\Exact\Accounting\ExactReportEnricher;
use App\Models\Account;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Models\Consumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExactReportEnricherCostDimensionTest extends TestCase
{
    use RefreshDatabase;

    private function enricher(): ExactReportEnricher
    {
        return new ExactReportEnricher(new ConnectionMappingExactReferenceResolver(new ExactRelationResolver(new BookingWarnings)));
    }

    private function connection(): Connection
    {
        $account = Account::factory()->for(Consumer::factory()->create())->create();

        return Connection::factory()->forExact()->for($account)->create();
    }

    private function seedRef(Connection $connection, string $kind, string $code): void
    {
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => $kind,
            'code' => $code,
            'native_id' => $code,
        ]);
    }

    /** @return list<Finding> */
    private function costFindings(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            fn ($f): bool => str_starts_with($f->code, 'exact.cost_center.') || str_starts_with($f->code, 'exact.cost_unit.'),
        ));
    }

    public function test_known_cost_center_and_unit_become_matched_info_findings(): void
    {
        $connection = $this->connection();
        $this->seedRef($connection, ConnectionAccountingRef::KIND_COST_CENTER, 'ADMIN');
        $this->seedRef($connection, ConnectionAccountingRef::KIND_COST_UNIT, 'PROJ-X');

        $findings = $this->costFindings($this->enricher()->enrich(
            ['lines' => [['description' => 'A', 'amount' => 100, 'cost_center' => 'ADMIN', 'cost_unit' => 'PROJ-X']]],
            $connection,
        ));

        $codes = array_map(fn ($f) => $f->code, $findings);
        $this->assertContains('exact.cost_center.matched', $codes);
        $this->assertContains('exact.cost_unit.matched', $codes);
        $this->assertSame(Severity::Info, $findings[0]->severity);
        $this->assertSame('lines.0.cost_center', $findings[0]->path);
        $this->assertFalse($findings[0]->blocking);
    }

    public function test_unknown_cost_center_becomes_unmapped_warning(): void
    {
        $connection = $this->connection();

        $findings = $this->costFindings($this->enricher()->enrich(
            ['lines' => [['description' => 'A', 'amount' => 100, 'cost_center' => 'GHOST']]],
            $connection,
        ));

        $this->assertCount(1, $findings);
        $this->assertSame('exact.cost_center.unmapped', $findings[0]->code);
        $this->assertSame(Severity::Warning, $findings[0]->severity);
        $this->assertNull($findings[0]->suggestion);
        $this->assertTrue($findings[0]->blocking);
    }

    public function test_dedupes_per_distinct_field_and_code(): void
    {
        $connection = $this->connection();
        $this->seedRef($connection, ConnectionAccountingRef::KIND_COST_CENTER, 'ADMIN');

        $findings = $this->costFindings($this->enricher()->enrich(
            ['lines' => [
                ['description' => 'A', 'amount' => 100, 'cost_center' => 'ADMIN'],
                ['description' => 'B', 'amount' => 50, 'cost_center' => 'ADMIN'],
            ]],
            $connection,
        ));

        $this->assertCount(1, $findings);
        $this->assertSame('exact.cost_center.matched', $findings[0]->code);
    }

    public function test_no_cost_findings_when_lines_carry_no_cost_codes(): void
    {
        $findings = $this->costFindings($this->enricher()->enrich(
            ['lines' => [['description' => 'A', 'amount' => 100]]],
            $this->connection(),
        ));

        $this->assertSame([], $findings);
    }
}
