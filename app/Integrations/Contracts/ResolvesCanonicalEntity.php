<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

/**
 * Leest de gewijzigde entity uit een partner-webhook-payload: welk id, welke actie.
 *
 * Zonder dit moet elke consumer leren hoe elke partner z'n payload opbouwt —
 * Exact's `Content.Key`, Snelstart's `type`-suffix — om te weten wát er wijzigde.
 * Dat is precies de kennis die {@see ResolvesCanonicalEvent} al wegneemt voor de
 * eventnaam; deze contract doet hetzelfde voor entity-id en actie.
 *
 * Een provider die het één niet levert, levert `null` — verzinnen is erger dan
 * "weet niet".
 */
interface ResolvesCanonicalEntity
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function entityId(array $payload): ?string;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function action(array $payload): ?string;
}
