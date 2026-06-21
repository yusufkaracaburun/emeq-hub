<?php

declare(strict_types=1);

namespace App\Books\Services;

use App\Books\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/*
 * Rendert een verkoopfactuur naar een PDF. Dun: laadt regels + debiteur, geeft
 * de Blade-view aan dompdf. De afzender-gegevens komen uit config('books.issuer').
 */
class InvoicePdfRenderer
{
    public function render(Invoice $invoice): DomPdf
    {
        $invoice->loadMissing(['lines', 'client']);

        return Pdf::loadView('books.invoices.pdf', [
            'invoice' => $invoice,
            'issuer' => config('books.issuer'),
        ])->setPaper('a4');
    }

    public function output(Invoice $invoice): string
    {
        return $this->render($invoice)->output();
    }

    public function filename(Invoice $invoice): string
    {
        $reference = $invoice->invoice_number ?: $invoice->getKey();

        return 'factuur-'.$reference.'.pdf';
    }
}
