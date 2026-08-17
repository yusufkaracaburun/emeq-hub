<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

/**
 * Het canonieke actie-vocabulaire naast {@see CanonicalEvent} — wat er met de
 * entity gebeurde, niet welk soort entity het is.
 */
final class CanonicalAction
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    /** De partner leverde een actie die de Hub niet kent. */
    public const UNMAPPED = 'unmapped';
}
