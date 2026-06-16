<?php

declare(strict_types=1);

namespace App\Accounting\Contracts;

use App\Accounting\AccountingResult;
use App\Accounting\FinancialDocument;
use App\Models\Connection;

/**
 * Een boekhoudpakket-adapter: mapt een canonical FinancialDocument naar de
 * provider-API en schrijft het weg via de tokens van de Connection. Eén impl
 * per provider (Exact nu; Snelstart/Moneybird/Yuki/Twinfield later), geregistreerd
 * in AccountingTargetRegistry.
 */
interface AccountingTarget
{
    public function push(FinancialDocument $document, Connection $connection): AccountingResult;
}
