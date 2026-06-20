<?php

declare(strict_types=1);

namespace App\Accounting\Validation;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';

    /**
     * Hoger = ernstiger; gebruikt om findings te ordenen in de respons.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Error => 3,
            self::Warning => 2,
            self::Info => 1,
        };
    }
}
