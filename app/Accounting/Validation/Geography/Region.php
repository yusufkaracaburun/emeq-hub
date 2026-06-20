<?php

declare(strict_types=1);

namespace App\Accounting\Validation\Geography;

enum Region: string
{
    case Domestic = 'nl';
    case Eu = 'eu';
    case NonEu = 'non_eu';
    case Unknown = 'unknown';
}
