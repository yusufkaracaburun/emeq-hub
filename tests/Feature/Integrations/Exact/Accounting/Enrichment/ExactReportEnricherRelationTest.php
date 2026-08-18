<?php

namespace Tests\Feature\Integrations\Exact\Accounting\Enrichment;

use App\Accounting\BookingWarnings;
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

class ExactReportEnricherRelationTest extends TestCase
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

    public function test_learned_relation_on_external_id_is_matched_without_a_live_lookup(): void
    {
        $connection = $this->connection();

        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 'cust-42',
            'native_id' => '11111111-2222-3333-4444-555555555555',
            'label' => 'Acme BV',
        ]);

        $findings = $this->enricher()->enrich(
            ['party' => ['role' => 'debtor', 'name' => 'Acme B.V.', 'external_id' => 'cust-42'], 'lines' => []],
            $connection,
        );

        $this->assertCount(1, $findings);
        $this->assertSame('exact.relation.matched', $findings[0]->code);
        $this->assertSame(Severity::Info, $findings[0]->severity);
        $this->assertSame('11111111-2222-3333-4444-555555555555', $findings[0]->suggestion);
        $this->assertStringContainsString('Acme BV', $findings[0]->message);
        $this->assertFalse($findings[0]->blocking);
    }

    public function test_external_id_of_another_connection_does_not_match(): void
    {
        $connection = $this->connection();

        ConnectionAccountingRef::query()->create([
            'connection_id' => $this->connection()->getKey(),
            'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 'cust-42',
            'native_id' => '11111111-2222-3333-4444-555555555555',
            'label' => 'Acme BV',
        ]);

        $findings = $this->enricher()->enrich(
            ['party' => ['role' => 'debtor', 'name' => 'Acme BV', 'external_id' => 'cust-42'], 'lines' => []],
            $connection,
        );

        $this->assertCount(1, $findings);
        $this->assertSame('exact.relation.new', $findings[0]->code);
        $this->assertSame(Severity::Info, $findings[0]->severity);
        $this->assertFalse($findings[0]->blocking);
    }
}
