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
 *
 * Het type is verplicht. Het stond als `?DocumentType $type = null` in het contract
 * met "null = alle typen", maar geen enkele adapter kan dat waarmaken: bij Exact
 * liggen verkoop en inkoop in aparte collecties met een eigen cursor, dus koos de
 * adapter bij null stilzwijgend verkoop. Een consumer die om "alle documenten" vroeg
 * kreeg de helft, zonder dat iets dat zei. Liever expliciet vragen dan stil de
 * verkeerde helft leveren.
 */
interface ReadsDocuments
{
    /**
     * @return ReadPage<PostedDocument>
     */
    public function readDocuments(Connection $connection, ReadQuery $query, DocumentType $type): ReadPage;
}
