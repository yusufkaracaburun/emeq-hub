<?php

namespace Tests\Unit\Integrations\Exact\Accounting;

use App\Accounting\BookingWarnings;
use App\Accounting\Enums\DocumentType;
use App\Accounting\Enums\TaxTreatment;
use App\Accounting\Exceptions\AccountingMappingException;
use App\Accounting\Party;
use App\Integrations\Exact\Accounting\ConnectionMappingExactReferenceResolver;
use App\Integrations\Exact\Accounting\ExactRelationResolver;
use App\Models\Account;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Models\Consumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectionMappingExactReferenceResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): ConnectionMappingExactReferenceResolver
    {
        return new ConnectionMappingExactReferenceResolver(new ExactRelationResolver(new BookingWarnings));
    }

    /** @param  array<string, mixed>|null  $mapping */
    private function connection(?array $mapping): Connection
    {
        $account = Account::factory()->for(Consumer::factory()->create())->create();

        return Connection::factory()->forExact()->for($account)->create([
            'metadata' => $mapping !== null ? ['accounting_mapping' => $mapping] : null,
        ]);
    }

    private function fullMapping(): Connection
    {
        return $this->connection([
            'vat_codes' => ['21' => '4', '9' => '2', '0' => '1'],
            'gl_accounts' => ['_default' => 'gl-def', 'omzet' => 'gl-omzet'],
            'journals' => ['sales' => '70', 'purchase' => '20', 'general' => '90'],
        ]);
    }

    private function seedRef(Connection $connection, string $kind, string $code, string $nativeId): void
    {
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => $kind,
            'code' => $code,
            'native_id' => $nativeId,
        ]);
    }

    public function test_resolves_mapped_values(): void
    {
        $resolver = $this->resolver();
        $connection = $this->fullMapping();
        $this->seedRef($connection, ConnectionAccountingRef::KIND_GL, 'gl-omzet', 'gl-omzet-id');
        $this->seedRef($connection, ConnectionAccountingRef::KIND_RELATION, 'ext-1', 'cust-1');

        $this->assertSame('4', $resolver->vatCode(21, TaxTreatment::Standard, $connection));
        $this->assertSame('2', $resolver->vatCode(9, TaxTreatment::Standard, $connection));
        $this->assertSame('gl-omzet-id', $resolver->glAccountRef('omzet', DocumentType::SalesInvoice, $connection));
        $this->assertSame('cust-1', $resolver->relationRef(new Party('debtor', 'Acme', externalId: 'ext-1'), $connection));
        $this->assertSame('70', $resolver->journal(DocumentType::SalesInvoice, $connection));
        $this->assertSame('20', $resolver->journal(DocumentType::PurchaseInvoice, $connection));
    }

    public function test_income_expense_use_own_journal_when_configured(): void
    {
        $resolver = $this->resolver();
        $connection = $this->connection([
            'journals' => ['sales' => '70', 'purchase' => '20', 'income' => '71', 'expense' => '21'],
        ]);

        $this->assertSame('71', $resolver->journal(DocumentType::Income, $connection));
        $this->assertSame('21', $resolver->journal(DocumentType::Expense, $connection));
    }

    public function test_income_expense_fall_back_to_sales_purchase_journals(): void
    {
        $resolver = $this->resolver();
        $connection = $this->connection([
            'journals' => ['sales' => '70', 'purchase' => '20'],
        ]);

        $this->assertSame('70', $resolver->journal(DocumentType::Income, $connection));
        $this->assertSame('20', $resolver->journal(DocumentType::Expense, $connection));
    }

    public function test_gl_code_falls_back_to_default_and_resolves_via_mirror(): void
    {
        $resolver = $this->resolver();
        $connection = $this->fullMapping();
        $this->seedRef($connection, ConnectionAccountingRef::KIND_GL, 'gl-def', 'gl-def-id');

        $this->assertSame('gl-def-id', $resolver->glAccountRef('onbekende-categorie', DocumentType::SalesInvoice, $connection));
        $this->assertSame('gl-def-id', $resolver->glAccountRef(null, DocumentType::SalesInvoice, $connection));
    }

    public function test_throws_when_gl_code_not_in_mirror(): void
    {
        $resolver = $this->resolver();

        $this->expectException(AccountingMappingException::class);
        $resolver->glAccountRef('omzet', DocumentType::SalesInvoice, $this->fullMapping());
    }

    public function test_gl_code_uses_document_type_default_before_falling_back_to_shared_default(): void
    {
        $resolver = $this->resolver();
        $connection = $this->connection([
            'gl_accounts' => ['_default' => 'gl-def', 'sales_default' => 'gl-sales', 'purchase_default' => 'gl-purchase'],
        ]);
        $this->seedRef($connection, ConnectionAccountingRef::KIND_GL, 'gl-sales', 'gl-sales-id');
        $this->seedRef($connection, ConnectionAccountingRef::KIND_GL, 'gl-purchase', 'gl-purchase-id');

        $this->assertSame('gl-sales-id', $resolver->glAccountRef(null, DocumentType::SalesInvoice, $connection));
        $this->assertSame('gl-sales-id', $resolver->glAccountRef(null, DocumentType::CreditNote, $connection));
        $this->assertSame('gl-sales-id', $resolver->glAccountRef(null, DocumentType::Income, $connection));
        $this->assertSame('gl-purchase-id', $resolver->glAccountRef(null, DocumentType::PurchaseInvoice, $connection));
        $this->assertSame('gl-purchase-id', $resolver->glAccountRef(null, DocumentType::Expense, $connection));
    }

    public function test_explicit_category_wins_over_document_type_default(): void
    {
        $resolver = $this->resolver();
        $connection = $this->connection([
            'gl_accounts' => ['omzet' => 'gl-omzet', 'sales_default' => 'gl-sales', 'purchase_default' => 'gl-purchase'],
        ]);
        $this->seedRef($connection, ConnectionAccountingRef::KIND_GL, 'gl-omzet', 'gl-omzet-id');

        $this->assertSame('gl-omzet-id', $resolver->glAccountRef('omzet', DocumentType::PurchaseInvoice, $connection));
    }

    public function test_gl_code_falls_back_to_shared_default_when_no_document_type_default_is_mapped(): void
    {
        $resolver = $this->resolver();
        $connection = $this->connection(['gl_accounts' => ['_default' => 'gl-def']]);
        $this->seedRef($connection, ConnectionAccountingRef::KIND_GL, 'gl-def', 'gl-def-id');

        $this->assertSame('gl-def-id', $resolver->glAccountRef(null, DocumentType::SalesInvoice, $connection));
        $this->assertSame('gl-def-id', $resolver->glAccountRef(null, DocumentType::PurchaseInvoice, $connection));
        $this->assertSame('gl-def-id', $resolver->glAccountRef(null, DocumentType::Income, $connection));
        $this->assertSame('gl-def-id', $resolver->glAccountRef(null, DocumentType::Expense, $connection));
    }

    public function test_vat_code_or_null_returns_code_or_null(): void
    {
        $resolver = $this->resolver();

        $this->assertSame('4', $resolver->vatCodeOrNull(21, TaxTreatment::Standard, $this->fullMapping()));
        $this->assertSame('2', $resolver->vatCodeOrNull(9, TaxTreatment::Standard, $this->fullMapping()));
        $this->assertNull($resolver->vatCodeOrNull(9, TaxTreatment::Standard, $this->connection(['vat_codes' => ['21' => '4']])));
        $this->assertNull($resolver->vatCodeOrNull(21, TaxTreatment::Standard, $this->connection(null)));
    }

    public function test_reverse_charge_uses_composite_key_without_falling_back_to_standard(): void
    {
        $resolver = $this->resolver();
        $connection = $this->connection(['vat_codes' => ['21' => '3', '9' => '1', 'reverse_charge:21' => '6', 'reverse_charge:9' => '7']]);

        $this->assertSame('6', $resolver->vatCode(21, TaxTreatment::ReverseCharge, $connection));
        $this->assertSame('7', $resolver->vatCode(9, TaxTreatment::ReverseCharge, $connection));
        $this->assertSame('3', $resolver->vatCode(21, TaxTreatment::Standard, $connection));

        $this->assertNull($resolver->vatCodeOrNull(21, TaxTreatment::ReverseCharge, $this->connection(['vat_codes' => ['21' => '3']])));
    }

    public function test_throws_when_vat_rate_unmapped(): void
    {
        $resolver = $this->resolver();

        $this->expectException(AccountingMappingException::class);
        $resolver->vatCode(9, TaxTreatment::Standard, $this->connection(['vat_codes' => ['21' => '4']]));
    }

    public function test_throws_when_mapping_absent(): void
    {
        $resolver = $this->resolver();

        $this->expectException(AccountingMappingException::class);
        $resolver->journal(DocumentType::SalesInvoice, $this->connection(null));
    }

    public function test_cost_center_and_unit_validate_against_mirror_and_pass_code_through(): void
    {
        $resolver = $this->resolver();
        $connection = $this->connection(null);
        $this->seedRef($connection, ConnectionAccountingRef::KIND_COST_CENTER, 'ADMIN', 'ADMIN');
        $this->seedRef($connection, ConnectionAccountingRef::KIND_COST_UNIT, 'PROJ-X', 'PROJ-X');

        $this->assertSame('ADMIN', $resolver->costCenter('ADMIN', $connection));
        $this->assertSame('PROJ-X', $resolver->costUnit('PROJ-X', $connection));
    }

    public function test_cost_center_and_unit_return_null_when_blank(): void
    {
        $resolver = $this->resolver();
        $connection = $this->connection(null);

        $this->assertNull($resolver->costCenter(null, $connection));
        $this->assertNull($resolver->costCenter('', $connection));
        $this->assertNull($resolver->costUnit(null, $connection));
    }

    public function test_throws_when_cost_center_not_in_mirror(): void
    {
        $resolver = $this->resolver();

        $this->expectException(AccountingMappingException::class);
        $resolver->costCenter('ONBEKEND', $this->connection(null));
    }
}
