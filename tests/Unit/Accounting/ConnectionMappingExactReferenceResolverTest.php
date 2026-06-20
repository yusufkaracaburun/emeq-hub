<?php

namespace Tests\Unit\Accounting;

use App\Accounting\Enums\DocumentType;
use App\Accounting\Exact\ConnectionMappingExactReferenceResolver;
use App\Accounting\Exceptions\AccountingMappingException;
use App\Accounting\Party;
use App\Models\Connection;
use Tests\TestCase;

class ConnectionMappingExactReferenceResolverTest extends TestCase
{
    /**
     * @param  array<string, mixed>|null  $mapping
     */
    private function connection(?array $mapping): Connection
    {
        $connection = new Connection;
        $connection->metadata = $mapping !== null ? ['accounting_mapping' => $mapping] : null;

        return $connection;
    }

    private function fullMapping(): Connection
    {
        return $this->connection([
            'vat_codes' => ['21' => '4', '9' => '2', '0' => '1'],
            'gl_accounts' => ['_default' => 'gl-def', 'omzet' => 'gl-omzet'],
            'relations' => ['ext-1' => 'cust-1'],
            'journals' => ['sales' => '70', 'purchase' => '20', 'general' => '90'],
        ]);
    }

    public function test_resolves_mapped_values(): void
    {
        $resolver = new ConnectionMappingExactReferenceResolver;
        $connection = $this->fullMapping();

        $this->assertSame('4', $resolver->vatCode(21, $connection));
        $this->assertSame('2', $resolver->vatCode(9, $connection));
        $this->assertSame('gl-omzet', $resolver->glAccountGuid('omzet', $connection));
        $this->assertSame('cust-1', $resolver->relationGuid(new Party('debtor', 'Acme', externalId: 'ext-1'), $connection));
        $this->assertSame('70', $resolver->journal(DocumentType::SalesInvoice, $connection));
        $this->assertSame('20', $resolver->journal(DocumentType::PurchaseInvoice, $connection));
    }

    public function test_income_expense_use_own_journal_when_configured(): void
    {
        $resolver = new ConnectionMappingExactReferenceResolver;
        $connection = $this->connection([
            'journals' => ['sales' => '70', 'purchase' => '20', 'income' => '71', 'expense' => '21'],
        ]);

        $this->assertSame('71', $resolver->journal(DocumentType::Income, $connection));
        $this->assertSame('21', $resolver->journal(DocumentType::Expense, $connection));
    }

    public function test_income_expense_fall_back_to_sales_purchase_journals(): void
    {
        $resolver = new ConnectionMappingExactReferenceResolver;
        $connection = $this->connection([
            'journals' => ['sales' => '70', 'purchase' => '20'],
        ]);

        // Geen eigen income/expense-dagboek geconfigureerd → verkoop/inkoop.
        $this->assertSame('70', $resolver->journal(DocumentType::Income, $connection));
        $this->assertSame('20', $resolver->journal(DocumentType::Expense, $connection));
    }

    public function test_gl_account_falls_back_to_default(): void
    {
        $resolver = new ConnectionMappingExactReferenceResolver;

        $this->assertSame('gl-def', $resolver->glAccountGuid('onbekende-categorie', $this->fullMapping()));
        $this->assertSame('gl-def', $resolver->glAccountGuid(null, $this->fullMapping()));
    }

    public function test_vat_code_or_null_returns_code_or_null(): void
    {
        $resolver = new ConnectionMappingExactReferenceResolver;

        $this->assertSame('4', $resolver->vatCodeOrNull(21, $this->fullMapping()));
        $this->assertSame('2', $resolver->vatCodeOrNull(9, $this->fullMapping()));
        $this->assertNull($resolver->vatCodeOrNull(9, $this->connection(['vat_codes' => ['21' => '4']])));
        $this->assertNull($resolver->vatCodeOrNull(21, $this->connection(null)));
    }

    public function test_throws_when_vat_rate_unmapped(): void
    {
        $resolver = new ConnectionMappingExactReferenceResolver;

        $this->expectException(AccountingMappingException::class);
        $resolver->vatCode(9, $this->connection(['vat_codes' => ['21' => '4']]));
    }

    public function test_throws_when_mapping_absent(): void
    {
        $resolver = new ConnectionMappingExactReferenceResolver;

        $this->expectException(AccountingMappingException::class);
        $resolver->journal(DocumentType::SalesInvoice, $this->connection(null));
    }
}
