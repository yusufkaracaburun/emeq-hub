<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Billing\Account\SubscriptionStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class AccountSubscriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'accountSubscriptions';

    protected static ?string $title = 'Subscriptions';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('mollie_subscription_id')
            ->columns([
                TextColumn::make('mollie_subscription_id')
                    ->label('Mollie ID')
                    ->fontFamily('mono')
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (SubscriptionStatus $state): string => $state->getColor())
                    ->formatStateUsing(fn (SubscriptionStatus $state): string => $state->value),
                TextColumn::make('amount_value')
                    ->label('Bedrag')
                    ->formatStateUsing(fn ($state, $record) => $state ? "{$record->amount_currency} {$state}" : '—'),
                TextColumn::make('interval')
                    ->label('Interval')
                    ->placeholder('—'),
                TextColumn::make('connection.provider')
                    ->label('Via')
                    ->badge(),
                TextColumn::make('created_at')
                    ->label('Aangemaakt')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(SubscriptionStatus::cases())
                        ->mapWithKeys(fn (SubscriptionStatus $s): array => [$s->value => ucfirst($s->value)])
                        ->all()),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
