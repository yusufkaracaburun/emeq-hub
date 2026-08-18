<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Settings\LegalSettings;
use BackedEnum;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

class ManageLegalPages extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Beheer';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Juridische teksten';

    protected static ?string $title = 'Juridische teksten';

    protected string $view = 'filament.pages.manage-legal-pages';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public function mount(): void
    {
        $legal = app(LegalSettings::class);

        $this->form->fill([
            'privacy_statement' => $legal->privacy_statement,
            'terms_statement' => $legal->terms_statement,
            'dpa_statement' => $legal->dpa_statement,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Privacyverklaring')
                    ->description('Publiek zichtbaar op /privacy. Markdown; wordt server-side naar HTML gerenderd.')
                    ->schema([
                        MarkdownEditor::make('privacy_statement')
                            ->label('Privacyverklaring (markdown)')
                            ->required()
                            ->columnSpanFull(),
                    ]),
                Section::make('Algemene voorwaarden')
                    ->description('Publiek zichtbaar op /voorwaarden. Markdown; wordt server-side naar HTML gerenderd.')
                    ->schema([
                        MarkdownEditor::make('terms_statement')
                            ->label('Algemene voorwaarden (markdown)')
                            ->required()
                            ->columnSpanFull(),
                    ]),
                Section::make('Verwerkersovereenkomst')
                    ->description('Publiek zichtbaar op /verwerkersovereenkomst. Markdown; wordt server-side naar HTML gerenderd.')
                    ->schema([
                        MarkdownEditor::make('dpa_statement')
                            ->label('Verwerkersovereenkomst (markdown)')
                            ->required()
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $today = Carbon::now()->toDateString();

        $legal = app(LegalSettings::class);
        $legal->privacy_statement = (string) $data['privacy_statement'];
        $legal->privacy_updated_at = $today;
        $legal->terms_statement = (string) $data['terms_statement'];
        $legal->terms_updated_at = $today;
        $legal->dpa_statement = (string) $data['dpa_statement'];
        $legal->dpa_updated_at = $today;
        $legal->save();

        Notification::make()->title('Juridische teksten opgeslagen')->success()->send();
    }
}
