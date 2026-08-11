<?php

declare(strict_types=1);

namespace App\Accounting\Contracts;

use App\Accounting\BankStatement;
use App\Accounting\Read\ReadPage;
use App\Accounting\Read\ReadQuery;
use App\Models\Connection;

/**
 * Capability `accounting.bank_statements.read`.
 *
 * Bestaat omdat de Hub op bank- en kas-webhooks abonneert: zonder lees-pad is zo'n
 * notificatie een seintje waar de ontvanger niets mee kan.
 */
interface ReadsBankStatements
{
    /**
     * @param  string  $kind  {@see BankStatement::KIND_BANK} of {@see BankStatement::KIND_CASH}
     * @return ReadPage<BankStatement>
     */
    public function readBankStatements(Connection $connection, ReadQuery $query, string $kind = BankStatement::KIND_BANK): ReadPage;
}
