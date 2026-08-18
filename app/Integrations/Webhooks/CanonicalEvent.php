<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

final class CanonicalEvent
{
    public const BANK_STATEMENT_CHANGED = 'accounting.bank_statement.changed';

    public const CASH_STATEMENT_CHANGED = 'accounting.cash_statement.changed';

    public const RELATION_CHANGED = 'accounting.relation.changed';

    public const SALES_INVOICE_CHANGED = 'accounting.sales_invoice.changed';

    public const PURCHASE_INVOICE_CHANGED = 'accounting.purchase_invoice.changed';

    public const JOURNAL_ENTRY_CHANGED = 'accounting.journal_entry.changed';

    public const DOCUMENT_CHANGED = 'accounting.document.changed';

    public const LEDGER_ACCOUNT_CHANGED = 'accounting.ledger_account.changed';

    public const DOCUMENT_SYNCED = 'accounting.document.synced';

    public const PAYMENT_CHANGED = 'billing.payment.changed';

    public const SUBSCRIPTION_CHANGED = 'billing.subscription.changed';

    public const CONNECTION_REVOKED = 'connection.revoked';

    public const UNMAPPED = 'unmapped';
}
