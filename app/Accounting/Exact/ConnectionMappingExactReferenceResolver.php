<?php

declare(strict_types=1);

namespace App\Accounting\Exact;

use App\Accounting\Enums\DocumentType;
use App\Accounting\Exact\Contracts\ExactReferenceResolver;
use App\Accounting\Exceptions\AccountingMappingException;
use App\Accounting\Party;
use App\Models\Connection;

/**
 * Leest de per-Connection Exact-mapping uit `connection.metadata.accounting_mapping`
 * (de override-helft van de hybride keuze — geen aparte tabel/migratie nodig). De
 * auto-afgeleide defaults (live de VATCodes/GLAccounts van de admin uitvragen) komen
 * ná de Data & Security-review, wanneer live-reads beschikbaar zijn. Ontbreekt een
 * vereiste mapping → expliciete exception i.p.v. een foute boeking.
 *
 * Verwachte metadata-vorm:
 *   "accounting_mapping": {
 *     "vat_codes":  { "21": "4", "9": "2", "0": "1" },          // tarief → VATCode
 *     "gl_accounts": { "_default": "<guid>", "omzet": "<guid>" }, // categorie → GLAccount-GUID
 *     "relations":  { "<party.external_id>": "<crm-account-guid>" },
 *     "journals":   { "sales": "70", "purchase": "20", "general": "90" }
 *   }
 */
final class ConnectionMappingExactReferenceResolver implements ExactReferenceResolver
{
    public function relationGuid(Party $party, Connection $connection): string
    {
        $relations = $this->section($connection, 'relations');
        $guid = $party->externalId !== null ? ($relations[$party->externalId] ?? null) : null;

        return $guid ?? throw $this->missing("relatie '{$party->name}'", 'relations');
    }

    public function vatCode(float $taxRate, Connection $connection): string
    {
        $codes = $this->section($connection, 'vat_codes');

        return $codes[$this->rateKey($taxRate)] ?? throw $this->missing("BTW-tarief {$this->rateKey($taxRate)}%", 'vat_codes');
    }

    public function glAccountGuid(?string $category, Connection $connection): ?string
    {
        $accounts = $this->section($connection, 'gl_accounts');

        return $accounts[$category ?? '_default'] ?? $accounts['_default'] ?? null;
    }

    public function journal(DocumentType $type, Connection $connection): string
    {
        $journals = $this->section($connection, 'journals');
        $key = $this->journalKey($type);

        return $journals[$key] ?? throw $this->missing("dagboek voor '{$type->value}'", 'journals');
    }

    /**
     * @return array<string, mixed>
     */
    private function section(Connection $connection, string $section): array
    {
        $mapping = $connection->metadata['accounting_mapping'] ?? [];
        $value = is_array($mapping) ? ($mapping[$section] ?? []) : [];

        return is_array($value) ? $value : [];
    }

    private function rateKey(float $taxRate): string
    {
        return rtrim(rtrim(number_format($taxRate, 2, '.', ''), '0'), '.');
    }

    private function journalKey(DocumentType $type): string
    {
        return match ($type) {
            DocumentType::SalesInvoice, DocumentType::CreditNote => 'sales',
            DocumentType::PurchaseInvoice => 'purchase',
            DocumentType::Income, DocumentType::Expense => 'general',
        };
    }

    private function missing(string $what, string $section): AccountingMappingException
    {
        return new AccountingMappingException(
            "Geen Exact-mapping voor {$what} — configureer metadata.accounting_mapping.{$section} op deze Connection."
        );
    }
}
