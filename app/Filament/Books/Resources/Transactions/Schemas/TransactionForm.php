<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Transactions\Schemas;

use App\Books\Enums\TransactionType;
use App\Books\Models\Account;
use App\Books\Models\BankAccount;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TransactionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label('Type')
                    ->options([
                        TransactionType::Deposit->value => 'Ontvangst',
                        TransactionType::Withdrawal->value => 'Uitgave',
                    ])
                    ->default(TransactionType::Deposit->value)
                    ->required()
                    ->native(false),

                Select::make('bank_account_id')
                    ->label('Bankrekening')
                    ->relationship('bankAccount', 'id')
                    ->getOptionLabelFromRecordUsing(fn (BankAccount $record): string => $record->account
                        ? "{$record->account->code} — {$record->account->name}"
                        : "Bankrekening #{$record->getKey()}")
                    ->preload()
                    ->required(),

                Select::make('account_id')
                    ->label('Tegenrekening (grootboek)')
                    ->relationship('account', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Account $record): string => "{$record->code} — {$record->name}")
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('amount')
                    ->label('Bedrag')
                    ->numeric()
                    ->prefix('€')
                    ->minValue(0.01)
                    ->step('0.01')
                    ->required(),

                DateTimePicker::make('posted_at')
                    ->label('Boekdatum')
                    ->default(now())
                    ->seconds(false)
                    ->required(),

                TextInput::make('description')
                    ->label('Omschrijving')
                    ->maxLength(255)
                    ->required(),

                TextInput::make('reference')
                    ->label('Referentie')
                    ->maxLength(255),

                Textarea::make('notes')
                    ->label('Notities')
                    ->maxLength(1000)
                    ->columnSpanFull(),
            ]);
    }
}
