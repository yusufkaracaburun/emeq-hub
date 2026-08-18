<?php

declare(strict_types=1);

namespace App\Accounting\Enums;

enum SyncStatus: string
{
    case Posted = 'posted';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Pending = 'pending';
}
