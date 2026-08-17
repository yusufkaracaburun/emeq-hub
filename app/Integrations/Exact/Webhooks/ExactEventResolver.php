<?php

declare(strict_types=1);

namespace App\Integrations\Exact\Webhooks;

use App\Integrations\Contracts\ResolvesCanonicalEvent;
use App\Integrations\Webhooks\CanonicalEvent;

final class ExactEventResolver implements ResolvesCanonicalEvent
{
    public function resolve(array $payload): ?string
    {
        $topic = $payload['Content']['Topic'] ?? $payload['Topic'] ?? null;

        return match ($topic) {
            'BankEntries' => CanonicalEvent::BANK_STATEMENT_CHANGED,
            'CashEntries' => CanonicalEvent::CASH_STATEMENT_CHANGED,
            'Accounts' => CanonicalEvent::RELATION_CHANGED,
            'SalesEntries' => CanonicalEvent::SALES_INVOICE_CHANGED,
            'PurchaseEntries' => CanonicalEvent::PURCHASE_INVOICE_CHANGED,
            'GeneralJournalEntries' => CanonicalEvent::JOURNAL_ENTRY_CHANGED,
            'Documents' => CanonicalEvent::DOCUMENT_CHANGED,
            'GLAccounts' => CanonicalEvent::LEDGER_ACCOUNT_CHANGED,
            default => null,
        };
    }
}
