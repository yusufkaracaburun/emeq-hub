<?php

declare(strict_types=1);

namespace App\Books\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Carbon;

class ReportPdfRenderer
{
    public function __construct(private readonly ReportService $reports) {}

    public function render(string $start, string $end): DomPdf
    {
        return Pdf::loadView('books.reports.pdf', [
            'start' => $start,
            'end' => $end,
            'profitAndLoss' => $this->reports->profitAndLoss($start, $end),
            'balanceSheet' => $this->reports->balanceSheet($end),
            'issuer' => config('books.issuer'),
        ])->setPaper('a4');
    }

    public function output(string $start, string $end): string
    {
        return $this->render($start, $end)->output();
    }

    public function filename(string $start, string $end): string
    {
        return 'overzicht-'
            .Carbon::parse($start)->format('Y-m-d').'-'
            .Carbon::parse($end)->format('Y-m-d').'.pdf';
    }
}
