<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Accounting\Contracts\ReferenceResolver;
use App\Accounting\Enums\DocumentType;
use App\Accounting\Enums\TaxTreatment;
use App\Accounting\Party;
use App\Models\Connection;

/**
 * Vervangt de referentie-resolutie door vaste waarden, zodat een accounting-test over
 * het boekingspad gaat en niet over de mapping.
 *
 * Stond vijf keer identiek gekopieerd in de accounting-tests. Eén plek betekent ook dat
 * een wijziging aan {@see ReferenceResolver} één keer landt in plaats van vijf keer —
 * en dat een vergeten vijfde niet stilletjes met de echte resolver gaat draaien.
 *
 * Let op bij provider #2: zodra de binding contextueel wordt (`when()->needs()->give()`)
 * wint die van deze `bind()` en draait de test met de echte resolver, groen en al. Zie
 * de notitie bij de binding in `AppServiceProvider`.
 */
trait BindsFakeAccountingReferences
{
    protected function bindFakeReferences(): void
    {
        $this->app->bind(ReferenceResolver::class, fn (): ReferenceResolver => new class implements ReferenceResolver
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

            public function glAccountRef(?string $category, Connection $connection): ?string
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
