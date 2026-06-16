<?php

declare(strict_types=1);

namespace App\Accounting\Exact\Contracts;

use App\Accounting\Enums\DocumentType;
use App\Accounting\Party;
use App\Models\Connection;

/**
 * Seam die canonical waarden vertaalt naar Exact-specifieke referenties van de
 * gekoppelde administratie: party → crm/Account-GUID (debiteur=Customer,
 * crediteur=Supplier), BTW-tarief → VATCode, categorie → GLAccount-GUID,
 * doc-type → dagboek (Journal). Fase 3 vult de hybride default-afleiding +
 * per-Connection override in; tot dan gooit de default-impl een duidelijke exception.
 */
interface ExactReferenceResolver
{
    public function relationGuid(Party $party, Connection $connection): string;

    public function vatCode(float $taxRate, Connection $connection): string;

    public function glAccountGuid(?string $category, Connection $connection): ?string;

    public function journal(DocumentType $type, Connection $connection): string;
}
