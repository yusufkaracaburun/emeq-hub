<?php

declare(strict_types=1);

namespace App\Accounting\Contracts;

use App\Accounting\Read\ReadPage;
use App\Accounting\Read\ReadQuery;
use App\Accounting\TaxCode;
use App\Models\Connection;

/**
 * Capability `tax_codes.read`.
 */
interface ReadsTaxCodes
{
    /**
     * @return ReadPage<TaxCode>
     */
    public function readTaxCodes(Connection $connection, ReadQuery $query): ReadPage;
}
