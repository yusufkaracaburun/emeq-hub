<?php

declare(strict_types=1);

namespace App\Accounting\Contracts;

use App\Models\Connection;

interface SyncsReferenceData
{
    /** @return int aantal gespiegelde referentie-rijen */
    public function syncReferences(Connection $connection): int;
}
