<?php

namespace App\Filament\Resources\CashierSubscriptions\Tables;

use App\Support\Filament\BadgeColor;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Cashier\Subscription;

class CashierSubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('owner.slug')
                    ->label('Consumer')
                    ->state(fn (Subscription $record): ?string => $record->owner?->slug)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Naam')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('plan')
                    ->label('Plan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('derived_status')
                    ->label('Status')
                    ->state(fn (Subscription $record): string => self::deriveStatus($record))
                    ->badge()
                    ->color(fn (string $state): string => BadgeColor::cashierStatus($state)),
                TextColumn::make('ends_at')
                    ->label('Eindigt op')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Aangemaakt')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('derived_status')
                    ->label('Status')
                    ->options([
                        'active' => 'Active',
                        'trialing' => 'Trialing',
                        'grace' => 'Grace period',
                        'cancelled' => 'Cancelled',
                        'ended' => 'Ended',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::applyStatusFilter($query, $data['value'] ?? null)),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /**
     * Mirror van Cashier's eigen accessor-methods op de Subscription-model:
     * onGracePeriod() > onTrial() > ended() > active() — exclusieve mapping.
     */
    private static function deriveStatus(Subscription $record): string
    {
        if ($record->onTrial()) {
            return 'trialing';
        }

        if ($record->onGracePeriod()) {
            return 'grace';
        }

        if ($record->ended()) {
            return 'ended';
        }

        if ($record->active()) {
            return 'active';
        }

        return 'unknown';
    }

    /**
     * Query-equivalent van Cashier's accessors voor SelectFilter.
     * Cashier-Mollie v2 heeft geen `status`-kolom — afgeleid via where-clauses op
     * `ends_at` + `trial_ends_at` (Phase 6 D-02).
     */
    private static function applyStatusFilter(Builder $query, ?string $value): Builder
    {
        return match ($value) {
            'active' => $query->where(function (Builder $q): void {
                $q->whereNull('ends_at')
                    ->orWhere('trial_ends_at', '>', now())
                    ->orWhere('ends_at', '>', now());
            }),
            'trialing' => $query->whereNotNull('trial_ends_at')->where('trial_ends_at', '>', now()),
            'grace' => $query->whereNotNull('ends_at')->where('ends_at', '>', now()),
            'cancelled' => $query->whereNotNull('ends_at'),
            'ended' => $query->whereNotNull('ends_at')->where('ends_at', '<=', now()),
            default => $query,
        };
    }
}
