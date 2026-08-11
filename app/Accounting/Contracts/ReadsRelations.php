<?php

declare(strict_types=1);

namespace App\Accounting\Contracts;

use App\Accounting\Read\ReadPage;
use App\Accounting\Read\ReadQuery;
use App\Accounting\Relation;
use App\Models\Connection;

/**
 * Capability `relations.read` — debiteuren én crediteuren.
 *
 * Eén methode met een rolfilter in plaats van twee, omdat beide partners die we
 * kennen één relatie-entiteit met rolvlaggen hebben. `/customers` en `/suppliers`
 * zijn twee ingangen op deze ene bron.
 */
interface ReadsRelations
{
    /**
     * @param  string|null  $role  {@see Relation::ROLE_DEBTOR}, {@see Relation::ROLE_CREDITOR}, of null voor alle
     * @return ReadPage<Relation>
     */
    public function readRelations(Connection $connection, ReadQuery $query, ?string $role = null): ReadPage;
}
