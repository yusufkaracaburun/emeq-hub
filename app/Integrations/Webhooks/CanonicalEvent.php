<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

/**
 * Het canonieke event-vocabulaire van de Hub — de namen die een consumer ziet,
 * ongeacht welk partnerpakket het event veroorzaakte.
 *
 * Bewust klein gehouden: elke naam hieronder correspondeert met een event waar de
 * Hub vandaag daadwerkelijk op abonneert of dat hij zelf publiceert. Een naam
 * toevoegen hoort samen te gaan met een resolver die 'm kan produceren.
 */
final class CanonicalEvent
{
    /** Bank- of kasmutatie gewijzigd bij de partner. */
    public const BANK_STATEMENT_CHANGED = 'accounting.bank_statement.changed';

    public const CASH_STATEMENT_CHANGED = 'accounting.cash_statement.changed';

    /** Debiteur/crediteur gewijzigd. */
    public const RELATION_CHANGED = 'accounting.relation.changed';

    public const SALES_INVOICE_CHANGED = 'accounting.sales_invoice.changed';

    public const PURCHASE_INVOICE_CHANGED = 'accounting.purchase_invoice.changed';

    public const JOURNAL_ENTRY_CHANGED = 'accounting.journal_entry.changed';

    public const DOCUMENT_CHANGED = 'accounting.document.changed';

    public const LEDGER_ACCOUNT_CHANGED = 'accounting.ledger_account.changed';

    /** De Hub heeft een canoniek document weggeschreven — publiceert SyncAccountingDocumentJob. */
    public const DOCUMENT_SYNCED = 'accounting.document.synced';

    public const PAYMENT_CHANGED = 'billing.payment.changed';

    public const SUBSCRIPTION_CHANGED = 'billing.subscription.changed';

    /** De koppeling is ingetrokken — publiceert ForwardConnectionRevokedToConsumerJob. */
    public const CONNECTION_REVOKED = 'connection.revoked';

    /**
     * De partner stuurde iets waarvoor de Hub (nog) geen canonieke naam heeft.
     * De ruwe payload staat gewoon in `data`; bouw hier geen logica op.
     */
    public const UNMAPPED = 'unmapped';
}
