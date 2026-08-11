<?php

declare(strict_types=1);

namespace App\Accounting\Contracts;

use App\Accounting\FinancialDocument;
use App\Accounting\PostedDocument;
use App\Models\Connection;

/**
 * Capability `accounting.documents.probe`.
 *
 * Beantwoordt de enige vraag die na een mislukte boeking echt telt: *is hij toch
 * geland?* Dat is het gat dat `provider_entity_links` niet dicht — die legt alleen
 * vast wat de Hub heeft zien slagen. Als de partner commit en de respons ons niet
 * bereikt, weet de Hub van niets en boekt een retry opnieuw.
 *
 * De implementatie zoekt op de herkomst die de adapter zelf bij het boeken
 * meeschrijft. Vindt hij niets, dan geeft hij `null` — er wordt niet gegokt.
 */
interface ProbesPostedDocuments
{
    /**
     * @return PostedDocument|null het document zoals het bij de partner staat, of null
     *                             wanneer het er niet is of niet vindbaar is
     */
    public function findPostedDocument(FinancialDocument $document, Connection $connection): ?PostedDocument;
}
