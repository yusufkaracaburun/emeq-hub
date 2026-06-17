<?php

declare(strict_types=1);

namespace App\Accounting\Enums;

/**
 * Semantische uitkomst van een accounting-sync, los van de HTTP-statuscode. In de
 * respons (`status`) zodat de consumer op één veld kan branchen voor z'n sync-ledger.
 * `Pending` is gereserveerd voor de async-variant (202 + result-webhook).
 */
enum SyncStatus: string
{
    case Posted = 'posted';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Pending = 'pending';
}
