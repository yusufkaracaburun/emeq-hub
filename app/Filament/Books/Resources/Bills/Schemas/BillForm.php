<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Bills\Schemas;

use App\Books\Enums\AccountCategory;
use App\Books\Enums\BillStatus;
use App\Books\Models\Account;
use App\Books\Models\Bill;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class BillForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('vendor_id')
                    ->label('Leverancier')
                    ->relationship('vendor', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('bill_number')
                    ->label('Factuurnummer')
                    ->maxLength(50),

                Select::make('status')
                    ->label('Status')
                    ->options(collect(BillStatus::cases())
                        ->mapWithKeys(fn (BillStatus $status): array => [$status->value => $status->getLabel()])
                        ->all())
                    ->default(BillStatus::Draft->value)
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
                            ->columnSpan(3),

                        Select::make('account_id')
                            ->label('Kostenrekening')
                            ->options(self::expenseAccountOptions())
                            ->searchable()
                            ->required()
                            ->columnSpan(3),

                        TextInput::make('quantity')
                            ->label('Aantal')
                            ->numeric()
                            ->default(1)
                            ->step('0.01')
                            ->required()
                            ->columnSpan(2),

                        TextInput::make('unit_price')
                            ->label('Stukprijs')
                            ->numeric()
                            ->prefix('€')
                            ->required()
                            ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : number_format($state / 100, 2, '.', ''))
                            ->dehydrateStateUsing(fn (?string $state): int => (int) round(((float) $state) * 100))
                            ->columnSpan(2),

                        Select::make('tax_rate')
                            ->label('BTW')
                            ->options([21 => '21%', 9 => '9%', 0 => '0%'])
                            ->default(21)
                            ->required()
                            ->native(false)
                            ->columnSpan(2),
                    ])
                    ->columns(12)
                    ->defaultItems(1)
                    ->addActionLabel('Regel toevoegen')
                    ->columnSpanFull(),

                Textarea::make('notes')
                    ->label('Notities')
                    ->maxLength(1000)
                    ->columnSpanFull(),
            ])
            ->disabled(fn (?Bill $record): bool => (bool) $record?->isPosted());
    }

    /** @return array<int, string> */
    private static function expenseAccountOptions(): array
    {
        return Account::query()
            ->where('category', AccountCategory::Expense)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Account $account): array => [$account->id => "{$account->code} — {$account->name}"])
            ->all();
    }
}
