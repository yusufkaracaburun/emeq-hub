<?php

declare(strict_types=1);

namespace App\Filament\Resources\Consumers\Schemas;

use App\Filament\Support\InfoModalAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/**
 * Infolist voor ConsumerResource. De "Wat is een Consumer?"-toelichting leeft als
 * info-icoon-modal in de paginaheader ({@see InfoModalAction}).
 */
class ConsumerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('id')
                    ->label('ID'),
                TextEntry::make('name'),
                TextEntry::make('slug'),
                TextEntry::make('webhook_callback_url')
                    ->label('Webhook callback-URL')
                    ->placeholder('Niet ingesteld'),
                TextEntry::make('created_at')
                    ->label('Aangemaakt')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->label('Bijgewerkt')
                    ->dateTime(),
            ]);
    }
}
