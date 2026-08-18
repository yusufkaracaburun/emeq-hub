<?php

declare(strict_types=1);

namespace App\Books\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

class BtwPdfRenderer
{
    public function __construct(private readonly BtwService $btw) {}

    public function render(string $start, string $end): DomPdf
    {
        return Pdf::loadView('books.reports.btw', [
            'declaration' => $this->btw->declaration($start, $end),
            'issuer' => config('books.issuer'),
        ])->setPaper('a4');
    }

    public function output(string $start, string $end): string
    {
        return $this->render($start, $end)->output();
    }

    public function filename(string $start, string $end): string
    {
        return 'btw-aangifte-'.$start.'-'.$end.'.pdf';
    }
}
