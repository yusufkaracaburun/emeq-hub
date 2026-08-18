<?php

declare(strict_types=1);

namespace App\Accounting\Contracts;

use App\Accounting\Read\ReadPage;
use App\Accounting\Read\ReadQuery;
use App\Accounting\Relation;
use App\Models\Connection;

interface ReadsRelations
{
    /**
     * @param  string|null  $role  {@see Relation::ROLE_DEBTOR}, {@see Relation::ROLE_CREDITOR}, of null voor alle
     * @return ReadPage<Relation>
     */
    public function readRelations(Connection $connection, ReadQuery $query, ?string $role = null): ReadPage;
}
