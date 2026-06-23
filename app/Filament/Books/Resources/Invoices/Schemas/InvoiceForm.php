<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Invoices\Schemas;

use App\Books\Enums\InvoiceStatus;
use App\Books\Models\Invoice;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/*
 * Factuur-form. Regel-bedragen (subtotaal/BTW/totaal) staan NIET in het form —
 * die rekent de InvoiceLineObserver. Stukprijs wordt als euro's ingevoerd en
 * per regel omgezet naar integer-centen.
 */
class InvoiceForm
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

                TextInput::make('invoice_number')
                    ->label('Factuurnummer')
                    ->maxLength(50),

                Select::make('status')
                    ->label('Status')
                    ->options(collect(InvoiceStatus::cases())
                        ->mapWithKeys(fn (InvoiceStatus $status): array => [$status->value => $status->getLabel()])
                        ->all())
                    ->default(InvoiceStatus::Draft->value)
                    ->required()
                    ->native(false),

                DatePicker::make('date')
                    ->label('Factuurdatum')
                    ->default(now())
                    ->required(),

                DatePicker::make('due_date')
                    ->label('Vervaldatum'),

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
            ])
            // Geboekte factuur is onwijzigbaar (zie Invoice::booted()) → form read-only.
            ->disabled(fn (?Invoice $record): bool => (bool) $record?->isPosted());
    }
}
