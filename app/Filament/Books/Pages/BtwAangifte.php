<?php

declare(strict_types=1);

namespace App\Filament\Books\Pages;

use App\Books\Services\BtwPdfRenderer;
use App\Books\Services\BtwService;
use App\Books\Services\BtwXmlExporter;
use App\Filament\Books\Concerns\GatedToBoekhouding;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class BtwAangifte extends Page
{
    use GatedToBoekhouding;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Boekhouding';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'BTW-aangifte';

    protected static ?string $title = 'BTW-aangifte';

    protected string $view = 'filament.books.pages.btw-aangifte';

    public string $startDate = '';

    public string $endDate = '';

    public function mount(): void
    {
        $this->startDate = now()->startOfQuarter()->toDateString();
        $this->endDate = now()->endOfQuarter()->toDateString();
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
                    $renderer = app(BtwPdfRenderer::class);

                    return response()->streamDownload(
                        fn () => print ($renderer->output($this->startDate, $this->endDate)),
                        $renderer->filename($this->startDate, $this->endDate),
                    );
                }),
            Action::make('xml')
                ->label('XML')
                ->icon(Heroicon::OutlinedCodeBracket)
                ->color('gray')
                ->action(function (): StreamedResponse {
                    $exporter = app(BtwXmlExporter::class);

                    return response()->streamDownload(
                        fn () => print ($exporter->export($this->startDate, $this->endDate)),
                        $exporter->filename($this->startDate, $this->endDate),
                    );
                }),
        ];
    }

    public function setRange(string $preset): void
    {
        $now = now();

        [$start, $end] = match ($preset) {
            'prev_quarter' => [$now->copy()->subQuarter()->startOfQuarter(), $now->copy()->subQuarter()->endOfQuarter()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            default => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()],
        };

        $this->startDate = $start->toDateString();
        $this->endDate = $end->toDateString();
    }

    protected function getViewData(): array
    {
        return [
            'declaration' => app(BtwService::class)->declaration($this->startDate, $this->endDate),
        ];
    }
}
