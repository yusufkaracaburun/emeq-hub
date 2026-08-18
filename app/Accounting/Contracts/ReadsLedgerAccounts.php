<?php

declare(strict_types=1);

namespace App\Accounting\Contracts;

use App\Accounting\LedgerAccount;
use App\Accounting\Read\ReadPage;
use App\Accounting\Read\ReadQuery;
use App\Models\Connection;

interface ReadsLedgerAccounts
{
    /** @return ReadPage<LedgerAccount> */
    public function readLedgerAccounts(Connection $connection, ReadQuery $query): ReadPage;
}
