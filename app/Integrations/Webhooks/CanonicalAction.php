<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

final class CanonicalAction
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    public const UNMAPPED = 'unmapped';
}
