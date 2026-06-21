<?php

declare(strict_types=1);

namespace App\Filament\Books\Pages;

use App\Books\Services\ReportService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/*
 * Financiële overzichten: Winst & Verlies (periode) + Balans (per einddatum).
 * De berekening leeft in ReportService; deze pagina levert alleen de periode-
 * keuze (live Livewire-props) en de view-data.
 */
class Overzichten extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Boekhouding';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Overzichten';

    protected static ?string $title = 'Overzichten';

    protected string $view = 'filament.books.pages.overzichten';

    public string $startDate = '';

    public string $endDate = '';

    public function mount(): void
    {
        $this->startDate = now()->startOfYear()->toDateString();
        $this->endDate = now()->toDateString();
    }

    /**
     * Snelle periode-presets voor de filterbalk.
     */
    public function setRange(string $preset): void
    {
        $now = now();

        [$start, $end] = match ($preset) {
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'quarter' => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()],
            'prev_year' => [$now->copy()->subYear()->startOfYear(), $now->copy()->subYear()->endOfYear()],
            default => [$now->copy()->startOfYear(), $now],
        };

        $this->startDate = $start->toDateString();
        $this->endDate = $end->toDateString();
    }

    protected function getViewData(): array
    {
        $reports = app(ReportService::class);

        return [
            'profitAndLoss' => $reports->profitAndLoss($this->startDate, $this->endDate),
            'balanceSheet' => $reports->balanceSheet($this->endDate),
        ];
    }
}
