<?php

declare(strict_types=1);

namespace App\Accounting\Contracts;

use App\Accounting\Enums\DocumentType;
use App\Accounting\PostedDocument;
use App\Accounting\Read\ReadPage;
use App\Accounting\Read\ReadQuery;
use App\Models\Connection;

/**
 * Capability `accounting.documents.read` — de leeskant van
 * {@see AccountingTarget::push()}.
 *
 * Eén endpoint met een type-filter en niet `/invoices` plus `/bills`, symmetrisch met
 * de schrijfzijde die ook één `/documents` heeft. Verkoop en inkoop zijn hetzelfde
 * soort ding met een andere richting; twee paden zouden twee canonieke concepten
 * suggereren waar er één is.
 */
interface ReadsDocuments
{
    /**
     * @param  DocumentType|null  $type  null = alle typen die de provider kan leveren
     * @return ReadPage<PostedDocument>
     */
    public function readDocuments(Connection $connection, ReadQuery $query, ?DocumentType $type = null): ReadPage;
}
