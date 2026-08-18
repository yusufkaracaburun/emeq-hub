<?php

declare(strict_types=1);

namespace App\Accounting\Contracts;

use App\Accounting\BankStatement;
use App\Accounting\Read\ReadPage;
use App\Accounting\Read\ReadQuery;
use App\Models\Connection;

interface ReadsBankStatements
{
    /**
     * @param  string  $kind  {@see BankStatement::KIND_BANK} of {@see BankStatement::KIND_CASH}
     * @return ReadPage<BankStatement>
     */
    public function readBankStatements(Connection $connection, ReadQuery $query, string $kind = BankStatement::KIND_BANK): ReadPage;
}
