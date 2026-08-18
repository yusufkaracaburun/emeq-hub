<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use UnitEnum;

class Documentation extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Beheer';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Documentatie';

    protected static ?string $title = 'Consumer-integratiehandleiding';

    protected string $view = 'filament.pages.documentation';

    private const GUIDE_PATH = 'docs/consumer-integration-guide.md';

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('apiReference')
                ->label('API-reference')
                ->icon(Heroicon::OutlinedCodeBracket)
                ->color('gray')
                ->url(route('scramble.docs.ui'), shouldOpenInNewTab: true),
        ];
    }

    public function guideHtml(): string
    {
        $path = base_path(self::GUIDE_PATH);

        if (! is_file($path)) {
            return '<p>Handleiding niet gevonden ('.e(self::GUIDE_PATH).').</p>';
        }

        return Str::markdown((string) file_get_contents($path), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
}
