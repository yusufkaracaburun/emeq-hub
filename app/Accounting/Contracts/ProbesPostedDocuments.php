<?php

declare(strict_types=1);

namespace App\Accounting\Contracts;

use App\Accounting\FinancialDocument;
use App\Accounting\PostedDocument;
use App\Models\Connection;

interface ProbesPostedDocuments
{
    /**
     * @return PostedDocument|null het document zoals het bij de partner staat, of null
     *                             wanneer het er niet is of niet vindbaar is
     */
    public function findPostedDocument(FinancialDocument $document, Connection $connection): ?PostedDocument;
}
