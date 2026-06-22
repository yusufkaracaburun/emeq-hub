<?php

declare(strict_types=1);

namespace App\Filament\Books\Pages;

use App\Books\Services\ReportPdfRenderer;
use App\Books\Services\ReportService;
use App\Filament\Books\BoekhoudingCluster;
use App\Filament\Books\Concerns\GatedToBoekhouding;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/*
 * Financiële overzichten: Winst & Verlies (periode) + Balans (per einddatum).
 * De berekening leeft in ReportService; deze pagina levert alleen de periode-
 * keuze (live Livewire-props) en de view-data.
 */
class Overzichten extends Page
{
    use GatedToBoekhouding;

    protected static ?string $cluster = BoekhoudingCluster::class;

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
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')
                ->label('PDF')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(function (): StreamedResponse {
                    $renderer = app(ReportPdfRenderer::class);

                    return response()->streamDownload(
                        fn () => print ($renderer->output($this->startDate, $this->endDate)),
                        $renderer->filename($this->startDate, $this->endDate),
                    );
                }),
        ];
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
