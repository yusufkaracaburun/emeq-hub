<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Transactions\Tables;

use App\Books\Enums\TransactionType;
use App\Filament\Books\Support\PaymentActions;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('posted_at', 'desc')
            ->columns([
                TextColumn::make('posted_at')
                    ->label('Datum')
                    ->date('d-m-Y')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->toggleable(),

                TextColumn::make('description')
                    ->label('Omschrijving')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('account.name')
                    ->label('Tegenrekening')
                    ->toggleable()
                    ->placeholder('—'),

                TextColumn::make('bankAccount.account.code')
                    ->label('Bank')
                    ->toggleable()
                    ->placeholder('—'),

                TextColumn::make('amount')
                    ->label('Bedrag')
                    ->formatStateUsing(fn (int $state): string => '€ '.number_format($state / 100, 2, ',', '.'))
                    ->alignEnd()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Type')
                    ->options([
                        TransactionType::Deposit->value => 'Ontvangst',
                        TransactionType::Withdrawal->value => 'Uitgave',
                    ]),
            ])
            ->recordActions([
                PaymentActions::reconcile(),
                ViewAction::make()->iconButton(),
            ]);
    }
}
