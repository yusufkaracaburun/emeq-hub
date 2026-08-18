<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Accounting\Contracts\ReferenceResolver;
use App\Accounting\Enums\DocumentType;
use App\Accounting\Enums\TaxTreatment;
use App\Accounting\Party;
use App\Integrations\Exact\Accounting\ExactAccountingTarget;
use App\Models\Connection;

trait BindsFakeAccountingReferences
{
    protected function bindFakeReferences(): void
    {
        $this->app->when(ExactAccountingTarget::class)
            ->needs(ReferenceResolver::class)
            ->give(fn (): ReferenceResolver => new class implements ReferenceResolver
            {
                public function relationRef(Party $party, Connection $connection): string
                {
                    return $party->role === 'creditor' ? 'supp-guid' : 'cust-guid';
                }

                public function vatCode(float $taxRate, TaxTreatment $treatment, Connection $connection): string
                {
                    if ($treatment === TaxTreatment::ReverseCharge) {
                        return $taxRate >= 21.0 ? '6' : '7';
                    }

                    return $taxRate >= 21.0 ? '4' : '2';
                }

                public function glAccountRef(?string $category, DocumentType $type, Connection $connection): ?string
                {
                    return 'gl-guid';
                }

                public function journal(DocumentType $type, Connection $connection): string
                {
                    return in_array($type, [DocumentType::PurchaseInvoice, DocumentType::Expense], true) ? '20' : '90';
                }

                public function costCenter(?string $code, Connection $connection): ?string
                {
                    return $code;
                }

                public function costUnit(?string $code, Connection $connection): ?string
                {
                    return $code;
                }
            });
    }
}
