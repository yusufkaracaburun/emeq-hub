<?php

declare(strict_types=1);

namespace App\Accounting\Exact;

use App\Accounting\Enums\DocumentType;
use App\Accounting\Enums\TaxTreatment;
use App\Accounting\Exact\Contracts\ExactReferenceResolver;
use App\Accounting\Exceptions\AccountingMappingException;
use App\Accounting\Party;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;

/**
 * Leest de per-Connection Exact-mapping uit `connection.metadata.accounting_mapping`
 * (de override-helft van de hybride keuze — geen aparte tabel/migratie nodig). De
 * auto-afgeleide defaults (live de VATCodes/GLAccounts van de admin uitvragen) komen
 * ná de Data & Security-review, wanneer live-reads beschikbaar zijn. Ontbreekt een
 * vereiste mapping → expliciete exception i.p.v. een foute boeking.
 *
 * De mapping bevat enkel stabiele **Codes** (auto-derived uit de mirror of overschreven):
 *   "accounting_mapping": {
 *     "vat_codes":  { "21": "3", "9": "1", "0": "0",            // standard: tarief → VATCode (plat)
 *                     "reverse_charge:21": "6", "reverse_charge:9": "7" }, // verlegd: behandeling:tarief → VATCode
 *     "gl_accounts": { "_default": "<gl-code>", "omzet": "<gl-code>" }, // categorie → GL-Code
 *     "journals":   { "sales": "80", "purchase": "70" }         // doc-type → dagboek-Code (direct)
 *   }
 *
 * De VATCode-key is behandeling-aware: standard leest de platte `tarief`-key (backward-compat
 * met bestaande mappings), verlegd leest `reverse_charge:tarief`. Geen fallback van verlegd op
 * de platte key — een ontbrekende verlegd-mapping moet falen i.p.v. stil de gewone code boeken.
 *
 * GL-Code → native GUID en relatie → native GUID resolven lokaal tegen de mirror
 * (`connection_accounting_refs`) — geen live partner-call op het schrijfpad. Relaties zijn
 * niet in de mapping opgeslagen maar lazy geleerd door ExactRelationResolver.
 * income/expense vallen terug op sales/purchase als geen eigen dagboek staat.
 */
final class ConnectionMappingExactReferenceResolver implements ExactReferenceResolver
{
    public function __construct(private readonly ExactRelationResolver $relations) {}

    public function relationGuid(Party $party, Connection $connection): string
    {
        return $this->relations->resolve($party, $connection)
            ?? throw $this->missing("relatie '{$party->name}' (geen match op external_id/vat_number/naam)", 'relations');
    }

    public function vatCode(float $taxRate, TaxTreatment $treatment, Connection $connection): string
    {
        return $this->vatCodeOrNull($taxRate, $treatment, $connection)
            ?? throw $this->missing("BTW-tarief {$this->rateKey($taxRate)}% ({$treatment->value})", 'vat_codes');
    }

    /**
     * Fail-soft variant voor het validate-rapport: geeft de VATCode of null i.p.v. een
     * exception zodat een nog-niet-gemapt tarief een finding kan worden i.p.v. een 422.
     */
    public function vatCodeOrNull(float $taxRate, TaxTreatment $treatment, Connection $connection): ?string
    {
        return $this->section($connection, 'vat_codes')[$this->vatKey($taxRate, $treatment)] ?? null;
    }

    /**
     * Behandeling-aware lookup-key: standard leest de platte `tarief`-key (backward-compat),
     * verlegd leest `reverse_charge:tarief`. Géén fallback van verlegd op de platte key.
     */
    private function vatKey(float $taxRate, TaxTreatment $treatment): string
    {
        $rate = $this->rateKey($taxRate);

        return $treatment === TaxTreatment::Standard ? $rate : $treatment->value.':'.$rate;
    }

    public function glAccountGuid(?string $category, Connection $connection): ?string
    {
        $accounts = $this->section($connection, 'gl_accounts');
        $code = $accounts[$category ?? '_default'] ?? $accounts['_default'] ?? null;

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

    /**
     * Kostenplaats/-drager dragen de Code direct op de boeking (geen GUID). De mirror dient hier
     * als validatie: een onbekende Code → fail-fast met duidelijke melding i.p.v. een Exact-400.
     */
    private function validatedRefCode(?string $code, Connection $connection, string $kind, string $missing): ?string
    {
        $code = trim((string) $code);

        if ($code === '') {
            return null;
        }

        return $this->mirrorNativeId($connection, $kind, $code)
            ?? throw new AccountingMappingException(sprintf($missing, $code));
    }

    /**
     * Resolveert een stabiele Code naar de provider-native identiteit (GUID) via de mirror.
     */
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

    /**
     * Geordende dagboek-kandidaten per doc-type. income/expense mogen een eigen
     * dagboek hebben maar vallen terug op verkoop/inkoop, zodat ze zonder extra
     * config werken (income → SalesEntry, expense → PurchaseEntry; zie #12).
     *
     * @return list<string>
     */
    private function journalKeys(DocumentType $type): array
    {
        return match ($type) {
            DocumentType::SalesInvoice, DocumentType::CreditNote => ['sales'],
            DocumentType::PurchaseInvoice => ['purchase'],
            DocumentType::Income => ['income', 'sales'],
            DocumentType::Expense => ['expense', 'purchase'],
        };
    }

    private function missing(string $what, string $section): AccountingMappingException
    {
        return new AccountingMappingException(
            "Geen Exact-mapping voor {$what} — configureer metadata.accounting_mapping.{$section} op deze Connection."
        );
    }
}
