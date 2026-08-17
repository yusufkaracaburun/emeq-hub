<?php

declare(strict_types=1);

namespace App\Accounting\Exceptions;

use RuntimeException;

/**
 * Meer dan één relatie matcht op KvK- of btw-nummer. De administratie is dan al
 * dubbelzinnig; er nog een derde relatie bij zetten maakt het erger. Aparte klasse
 * van {@see AccountingMappingException} omdat de HTTP-laag hier 409 op zet, niet 422 —
 * de consumer lost dit op met `party.relation_id`, niet met een andere payload-vorm.
 */
class RelationAmbiguousException extends RuntimeException
{
    /**
     * @param  list<array{id: string, name: string}>  $candidates
     */
    public function __construct(string $message, public readonly array $candidates)
    {
        parent::__construct($message);
    }
}
