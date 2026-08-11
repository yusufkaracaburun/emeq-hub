<?php

declare(strict_types=1);

namespace App\Accounting\Contracts;

use App\Accounting\Enums\DocumentType;
use App\Accounting\Enums\TaxTreatment;
use App\Accounting\Party;
use App\Models\Connection;

/**
 * Vertaalt canonieke waarden naar de partner-identiteiten van de gekoppelde
 * administratie: party → relatie, (behandeling, btw-tarief) → btw-code, categorie →
 * grootboekrekening, doctype → dagboek. Eén implementatie per provider-adapter.
 *
 * `Ref` betekent "identiteit zoals de partner die kent" en belooft bewust geen vorm:
 * Exact geeft een GUID voor relaties en grootboek, maar Codes voor btw, dagboek en
 * kostenplaats — en de volgende partner kan een integer teruggeven.
 */
interface ReferenceResolver
{
    public function relationRef(Party $party, Connection $connection): string;

    public function vatCode(float $taxRate, TaxTreatment $treatment, Connection $connection): string;

    public function glAccountRef(?string $category, Connection $connection): ?string;

    public function journal(DocumentType $type, Connection $connection): string;

    /**
     * Valideert een kostenplaats-code tegen de mirror en geeft 'm ongewijzigd terug.
     * Null/leeg → null (regel zonder kostenplaats).
     */
    public function costCenter(?string $code, Connection $connection): ?string;

    /**
     * Valideert een kostendrager-code tegen de mirror en geeft 'm ongewijzigd terug.
     */
    public function costUnit(?string $code, Connection $connection): ?string;
}
