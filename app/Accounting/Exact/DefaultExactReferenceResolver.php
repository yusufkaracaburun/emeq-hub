<?php

declare(strict_types=1);

namespace App\Accounting\Exact;

use App\Accounting\Exact\Contracts\ExactReferenceResolver;
use App\Accounting\Exceptions\AccountingMappingException;
use App\Accounting\Party;
use App\Models\Connection;

/**
 * Placeholder tot fase 3 (hybride mapping: auto-afleiding uit de admin + per-Connection
 * override). Bewust falend i.p.v. stilzwijgend foute bookings produceren — een lege
 * VATCode/GLAccount zou een verkeerde BTW-aangifte opleveren. Tests binden een echte
 * fake; de live-flow wacht op de mapping-configuratie.
 */
final class DefaultExactReferenceResolver implements ExactReferenceResolver
{
    public function customerGuid(Party $party, Connection $connection): string
    {
        throw $this->notConfigured('Customer-GUID');
    }

    public function vatCode(float $taxRate, Connection $connection): string
    {
        throw $this->notConfigured('VATCode');
    }

    public function glAccountGuid(?string $category, Connection $connection): ?string
    {
        throw $this->notConfigured('GLAccount');
    }

    private function notConfigured(string $what): AccountingMappingException
    {
        return new AccountingMappingException(
            "Exact {$what}-mapping is nog niet geconfigureerd voor deze administratie (fase 3)."
        );
    }
}
