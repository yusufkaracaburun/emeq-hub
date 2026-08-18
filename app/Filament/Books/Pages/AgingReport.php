<?php

declare(strict_types=1);

namespace App\Filament\Books\Pages;

use App\Books\Services\AgingPdfRenderer;
use App\Books\Services\AgingService;
use App\Filament\Books\Concerns\GatedToBoekhouding;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class AgingReport extends Page
{
    use GatedToBoekhouding;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Boekhouding';

    protected static ?int $navigationSort = 11;

    protected static ?string $navigationLabel = 'Ouderdomsanalyse';

    protected static ?string $title = 'Ouderdomsanalyse';

    protected string $view = 'filament.books.pages.aging-report';

    public string $asOfDate = '';

    public string $kind = 'receivable';

    public function mount(): void
    {
        $this->asOfDate = now()->toDateString();
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')
                ->label('PDF')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(function (): StreamedResponse {
                    $renderer = app(AgingPdfRenderer::class);

                    return response()->streamDownload(
                        fn () => print ($renderer->output($this->asOfDate, $this->kind)),
                        $renderer->filename($this->asOfDate, $this->kind),
                    );
                }),
        ];
    }

    public function setKind(string $kind): void
    {
        $this->kind = $kind === 'payable' ? 'payable' : 'receivable';
    }

    public function setAsOf(string $preset): void
    {
        $now = now();

        $this->asOfDate = match ($preset) {
            'end_prev_month' => $now->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            'end_prev_quarter' => $now->copy()->subQuarter()->endOfQuarter()->toDateString(),
            default => $now->toDateString(),
        };
    }

    protected function getViewData(): array
    {
        $service = app(AgingService::class);

        return [
            'report' => $this->kind === 'payable'
                ? $service->payables($this->asOfDate)
                : $service->receivables($this->asOfDate),
            'buckets' => AgingService::BUCKETS,
        ];
    }
}
