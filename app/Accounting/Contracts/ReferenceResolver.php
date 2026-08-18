<?php

declare(strict_types=1);

namespace App\Accounting\Contracts;

use App\Accounting\Enums\DocumentType;
use App\Accounting\Enums\TaxTreatment;
use App\Accounting\Party;
use App\Models\Connection;

interface ReferenceResolver
{
    public function relationRef(Party $party, Connection $connection): string;

    public function vatCode(float $taxRate, TaxTreatment $treatment, Connection $connection): string;

    public function glAccountRef(?string $category, DocumentType $type, Connection $connection): ?string;

    public function journal(DocumentType $type, Connection $connection): string;

    public function costCenter(?string $code, Connection $connection): ?string;

    public function costUnit(?string $code, Connection $connection): ?string;
}
