<?php

declare(strict_types=1);

namespace App\Accounting\Contracts;

use App\Accounting\FinancialDocument;
use App\Models\Connection;

interface ConfirmsPostedDocuments
{
    /**
     * Asks the partner whether the entity a link points at is still there.
     *
     * Distinct from {@see ProbesPostedDocuments::findPostedDocument()}, which
     * searches by our own reference and answers null for "absent" as well as
     * for "could not look". Rebooking hinges on telling those apart: a false
     * "gone" writes a second entry into someone's administration.
     *
     * @param  string  $providerEntityId  the id recorded on the link
     * @return bool|null true when the entity is still there, false when the partner
     *                   does not know it any more, null when the answer settles neither
     */
    public function postedDocumentStillExists(
        FinancialDocument $document,
        string $providerEntityId,
        Connection $connection,
    ): ?bool;
}
