<?php

namespace App\Filament\Resources\CashierSubscriptions\Schemas;

use App\Support\Filament\BadgeColor;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Laravel\Cashier\Subscription;

class CashierSubscriptionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('owner.slug')
                    ->label('Consumer')
                    ->state(fn (Subscription $record): ?string => $record->owner?->slug),
                TextEntry::make('name')
                    ->label('Naam'),
                TextEntry::make('plan')
                    ->label('Plan'),
                TextEntry::make('derived_status')
                    ->label('Status')
                    ->state(fn (Subscription $record): string => self::deriveStatus($record))
                    ->badge()
                    ->color(fn (string $state): string => BadgeColor::cashierStatus($state)),
                TextEntry::make('trial_ends_at')
                    ->label('Trial eindigt')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('ends_at')
                    ->label('Eindigt op')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('cycle_started_at')
                    ->label('Cycle gestart')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('cycle_ends_at')
                    ->label('Cycle eindigt')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('created_at')
                    ->label('Aangemaakt')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->label('Laatste update')
                    ->dateTime(),
            ]);
    }

    public static function deriveStatus(Subscription $record): string
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
}
