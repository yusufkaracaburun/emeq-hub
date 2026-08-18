<?php

declare(strict_types=1);

namespace App\Integrations\Exact\Accounting;

use App\Accounting\Contracts\ReferenceResolver;
use App\Accounting\Enums\DocumentType;
use App\Accounting\Enums\TaxTreatment;
use App\Accounting\Exceptions\AccountingMappingException;
use App\Accounting\Party;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;

final class ConnectionMappingExactReferenceResolver implements ReferenceResolver
{
    public function __construct(private readonly ExactRelationResolver $relations) {}

    public function relationRef(Party $party, Connection $connection): string
    {
        return $this->relations->resolve($party, $connection);
    }

    public function vatCode(float $taxRate, TaxTreatment $treatment, Connection $connection): string
    {
        return $this->vatCodeOrNull($taxRate, $treatment, $connection)
            ?? throw $this->missing("BTW-tarief {$this->rateKey($taxRate)}% ({$treatment->value})", 'vat_codes');
    }

    public function vatCodeOrNull(float $taxRate, TaxTreatment $treatment, Connection $connection): ?string
    {
        return $this->section($connection, 'vat_codes')[$this->vatKey($taxRate, $treatment)] ?? null;
    }

    private function vatKey(float $taxRate, TaxTreatment $treatment): string
    {
        return $treatment->vatCodeKey($this->rateKey($taxRate));
    }

    public function glAccountRef(?string $category, DocumentType $type, Connection $connection): ?string
    {
        $accounts = $this->section($connection, 'gl_accounts');
        $defaultKey = $this->glAccountDefaultKey($type);
        $code = $accounts[$category ?? $defaultKey] ?? $accounts[$defaultKey] ?? $accounts['_default'] ?? null;

        if ($code === null) {
            return null;
        }

        return $this->mirrorNativeId($connection, ConnectionAccountingRef::KIND_GL, (string) $code)
            ?? throw new AccountingMappingException("Grootboek-code '{$code}' niet in de mirror — draai POST /v1/accounting/sync.");
    }

    public function costCenter(?string $code, Connection $connection): ?string
    {
        return $this->validatedRefCode(
            $code,
            $connection,
            ConnectionAccountingRef::KIND_COST_CENTER,
            "Kostenplaats-code '%s' niet in de mirror — draai POST /v1/accounting/sync.",
        );
    }

    public function costUnit(?string $code, Connection $connection): ?string
    {
        return $this->validatedRefCode(
            $code,
            $connection,
            ConnectionAccountingRef::KIND_COST_UNIT,
            "Kostendrager-code '%s' niet in de mirror — draai POST /v1/accounting/sync.",
        );
    }

    public function refCodeExists(string $code, string $kind, Connection $connection): bool
    {
        $code = trim($code);

        return $code !== '' && $this->mirrorNativeId($connection, $kind, $code) !== null;
    }

    private function validatedRefCode(?string $code, Connection $connection, string $kind, string $missing): ?string
    {
        $code = trim((string) $code);

        if ($code === '') {
            return null;
        }

        return $this->mirrorNativeId($connection, $kind, $code)
            ?? throw new AccountingMappingException(sprintf($missing, $code));
    }

    private function mirrorNativeId(Connection $connection, string $kind, string $code): ?string
    {
        $native = ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->where('kind', $kind)
            ->where('code', $code)
            ->value('native_id');

        return $native !== null ? (string) $native : null;
    }

    public function journal(DocumentType $type, Connection $connection): string
    {
        $journals = $this->section($connection, 'journals');

        foreach ($this->journalKeys($type) as $key) {
            if (isset($journals[$key])) {
                return $journals[$key];
            }
        }

        return throw $this->missing("dagboek voor '{$type->value}'", 'journals');
    }

    /** @return array<string, mixed> */
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

    /** @return list<string> */
    private function journalKeys(DocumentType $type): array
    {
        return match ($type) {
            DocumentType::SalesInvoice, DocumentType::CreditNote => [$this->journalFamily($type)],
            DocumentType::PurchaseInvoice => [$this->journalFamily($type)],
            DocumentType::Income => ['income', $this->journalFamily($type)],
            DocumentType::Expense => ['expense', $this->journalFamily($type)],
        };
    }

    private function journalFamily(DocumentType $type): string
    {
        return match ($type) {
            DocumentType::SalesInvoice, DocumentType::CreditNote, DocumentType::Income => 'sales',
            DocumentType::PurchaseInvoice, DocumentType::Expense => 'purchase',
        };
    }

    private function glAccountDefaultKey(DocumentType $type): string
    {
        return $this->journalFamily($type).'_default';
    }

    private function missing(string $what, string $section): AccountingMappingException
    {
        return new AccountingMappingException(
            "Geen Exact-mapping voor {$what} — configureer metadata.accounting_mapping.{$section} op deze Connection."
        );
    }
}
