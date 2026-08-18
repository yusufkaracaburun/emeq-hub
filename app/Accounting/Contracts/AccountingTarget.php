<?php

declare(strict_types=1);

namespace App\Accounting\Contracts;

use App\Accounting\AccountingResult;
use App\Accounting\FinancialDocument;
use App\Models\Connection;

interface AccountingTarget
{
    public function push(FinancialDocument $document, Connection $connection): AccountingResult;
}
