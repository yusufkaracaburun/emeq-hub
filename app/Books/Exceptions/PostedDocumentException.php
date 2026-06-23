<?php

declare(strict_types=1);

namespace App\Books\Exceptions;

use RuntimeException;

/**
 * Een geboekte factuur/inkoopfactuur is onwijzigbaar: de regels/totalen zijn naar
 * het grootboek geboekt, dus een mutatie zou de boeking en het grootboek laten
 * divergeren. Eerst de boeking ongedaan maken, dán wijzigen.
 */
final class PostedDocumentException extends RuntimeException
{
    public static function immutable(): self
    {
        return new self('Een geboekte factuur is onwijzigbaar — maak eerst de boeking ongedaan.');
    }
}
