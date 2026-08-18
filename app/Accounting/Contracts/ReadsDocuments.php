<?php

declare(strict_types=1);

namespace App\Accounting\Contracts;

use App\Accounting\Enums\DocumentType;
use App\Accounting\PostedDocument;
use App\Accounting\Read\ReadPage;
use App\Accounting\Read\ReadQuery;
use App\Models\Connection;

interface ReadsDocuments
{
    /** @return ReadPage<PostedDocument> */
    public function readDocuments(Connection $connection, ReadQuery $query, DocumentType $type): ReadPage;
}
