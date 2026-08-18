<?php

declare(strict_types=1);

namespace App\Accounting\Enums;

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
