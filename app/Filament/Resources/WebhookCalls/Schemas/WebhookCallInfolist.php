<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookCalls\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class WebhookCallInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('id')
                    ->label('ID'),
                TextEntry::make('direction')
                    ->label('Direction')
                    ->badge(),
                TextEntry::make('provider')
                    ->label('Provider')
                    ->badge(),
                TextEntry::make('consumer.slug')
                    ->label('Consumer')
                    ->placeholder('—'),
                TextEntry::make('status')
                    ->label('Status')
                    ->badge(),
                TextEntry::make('name')
                    ->label('Name'),
                TextEntry::make('url')
                    ->label('URL')
                    ->copyable(),
                TextEntry::make('payload')
                    ->label('Payload (JSON)')
                    ->state(fn ($record): string => json_encode($record->payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                    ->columnSpanFull(),
                TextEntry::make('exception')
                    ->label('Exception')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->label('Aangemaakt')
                    ->dateTime(),
            ]);
    }
}
