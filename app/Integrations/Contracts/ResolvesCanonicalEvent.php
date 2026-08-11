<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

/**
 * Vertaalt de payload van één partner naar een canonieke event-naam.
 *
 * Elke provider beschrijft zijn events anders — Exact met een `Topic`, Snelstart
 * met `Entity.Action`, Mollie met een id-prefix. De consumer hoort daar niets van
 * te merken: die krijgt één envelope met één vocabulaire, ongeacht welk pakket
 * z'n eindgebruiker koppelde.
 *
 * Een payload die niet te herleiden is levert `null`. De envelope zet dan
 * {@see CanonicalEvent::UNMAPPED}; verzinnen wat een onbekend event betekent is
 * erger dan zeggen dat je het niet weet.
 */
interface ResolvesCanonicalEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function resolve(array $payload): ?string;
}
