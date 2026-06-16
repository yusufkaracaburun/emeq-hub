<?php

declare(strict_types=1);

namespace App\Accounting\Enums;

/**
 * Canonical document-soort die consumers naar de Hub sturen. Provider-onafhankelijk;
 * elke AccountingTarget-adapter mapt deze naar zijn eigen endpoints.
 */
enum DocumentType: string
{
    case SalesInvoice = 'sales_invoice';
    case PurchaseInvoice = 'purchase_invoice';
    case Income = 'income';
    case Expense = 'expense';
    case CreditNote = 'credit_note';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t): string => $t->value, self::cases());
    }
}
