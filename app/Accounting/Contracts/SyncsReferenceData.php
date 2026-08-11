<?php

declare(strict_types=1);

namespace App\Accounting\Contracts;

use App\Models\Connection;

/**
 * Capability `references.sync`.
 *
 * Spiegelt de stabiele referentiedata van de gekoppelde administratie naar
 * `connection_accounting_refs` én leidt de ontbrekende default-mapping eruit af.
 * Bewust één stap: er is geen aanroeper die het één zonder het ander doet, en de
 * volgorde als invariant op drie plekken herhalen levert alleen drift op.
 */
interface SyncsReferenceData
{
    /**
     * @return int aantal gespiegelde referentie-rijen
     */
    public function syncReferences(Connection $connection): int;
}
