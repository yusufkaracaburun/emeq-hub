<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Billing\Exceptions;

use RuntimeException;

final class UnknownPlanException extends RuntimeException
{
    public static function forSlug(string $slug): self
    {
        return new self(sprintf(
            'Onbekende plan-slug: "%s". Definieer in config/billing-plans.php.',
            $slug,
        ));
    }
}
