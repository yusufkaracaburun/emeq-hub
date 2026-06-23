<?php

declare(strict_types=1);

namespace App\Books\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/*
 * Rendert de ouderdomsanalyse (debiteuren/crediteuren) op een peildatum naar PDF.
 * Dun: leest AgingService, geeft een plain-HTML Blade aan dompdf. Afzender uit
 * config('books.issuer'), net als de andere Books-renderers.
 */
class AgingPdfRenderer
{
    public function __construct(private readonly AgingService $aging) {}

    public function render(string $asOf, string $kind): DomPdf
    {
        $report = $kind === 'payable'
            ? $this->aging->payables($asOf)
            : $this->aging->receivables($asOf);

        return Pdf::loadView('books.reports.aging', [
            'report' => $report,
            'buckets' => AgingService::BUCKETS,
            'issuer' => config('books.issuer'),
        ])->setPaper('a4', 'landscape');
    }

    public function output(string $asOf, string $kind): string
    {
        return $this->render($asOf, $kind)->output();
    }

    public function filename(string $asOf, string $kind): string
    {
        $prefix = $kind === 'payable' ? 'crediteuren' : 'debiteuren';

        return $prefix.'-ouderdomsanalyse-'.$asOf.'.pdf';
    }
}
