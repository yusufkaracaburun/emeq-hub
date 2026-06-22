<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\RecurringInvoices\Schemas;

use App\Books\Enums\RecurringFrequency;
use App\Books\Enums\RecurringStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/*
 * Template-form voor een terugkerende factuur. Bedragen (totalen) staan niet
 * hier — die rekent de gegenereerde Invoice. Stukprijs als euro's → integer-
 * centen per regel (mirror InvoiceForm). next_date wordt bij aanmaak uit
 * start_date afgeleid (model-hook), dus niet in het form.
 */
class RecurringInvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('client_id')
                    ->label('Klant')
                    ->relationship('client', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('frequency')
                    ->label('Frequentie')
                    ->options(collect(RecurringFrequency::cases())
                        ->mapWithKeys(fn (RecurringFrequency $f): array => [$f->value => $f->getLabel()])
                        ->all())
                    ->default(RecurringFrequency::Monthly->value)
                    ->required()
                    ->native(false),

                Select::make('status')
                    ->label('Status')
                    ->options(collect(RecurringStatus::cases())
                        ->mapWithKeys(fn (RecurringStatus $s): array => [$s->value => $s->getLabel()])
                        ->all())
                    ->default(RecurringStatus::Active->value)
                    ->required()
                    ->native(false),

                DatePicker::make('start_date')
                    ->label('Startdatum')
                    ->default(now())
                    ->required(),

                TextInput::make('due_days')
                    ->label('Betaaltermijn')
                    ->numeric()
                    ->default(14)
                    ->minValue(0)
                    ->suffix('dagen')
                    ->required(),

                DatePicker::make('end_date')
                    ->label('Einddatum (optioneel)')
                    ->helperText('Laat leeg voor geen einddatum.'),

                TextInput::make('max_occurrences')
                    ->label('Max. aantal (optioneel)')
                    ->numeric()
                    ->minValue(1)
                    ->helperText('Stop na dit aantal facturen.'),

                Repeater::make('lines')
                    ->label('Regels')
                    ->relationship()
                    ->schema([
                        TextInput::make('description')
                            ->label('Omschrijving')
                            ->required()
                            ->columnSpan(2),

                        TextInput::make('quantity')
                            ->label('Aantal')
                            ->numeric()
                            ->default(1)
                            ->step('0.01')
                            ->required(),

                        TextInput::make('unit_price')
                            ->label('Stukprijs')
                            ->numeric()
                            ->prefix('€')
                            ->required()
                            ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : number_format($state / 100, 2, '.', ''))
                            ->dehydrateStateUsing(fn (?string $state): int => (int) round(((float) $state) * 100)),

                        Select::make('tax_rate')
                            ->label('BTW')
                            ->options([21 => '21%', 9 => '9%', 0 => '0%'])
                            ->default(21)
                            ->required()
                            ->native(false),
                    ])
                    ->columns(5)
                    ->defaultItems(1)
                    ->addActionLabel('Regel toevoegen')
                    ->columnSpanFull(),

                Textarea::make('notes')
                    ->label('Notities')
                    ->maxLength(1000)
                    ->columnSpanFull(),
            ]);
    }
}
