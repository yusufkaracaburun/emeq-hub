<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Schemas;

use App\Filament\Support\InfoModalAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/**
 * Infolist voor AccountResource. De "Wat is een Account?"-toelichting leeft als
 * info-icoon-modal in de paginaheader ({@see InfoModalAction}).
 */
class AccountInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('id')
                    ->label('ID'),
                TextEntry::make('consumer.slug')
                    ->label('Consumer'),
                TextEntry::make('external_id')
                    ->label('External ID'),
                TextEntry::make('display_name')
                    ->label('Display name'),
                TextEntry::make('created_at')
                    ->label('Aangemaakt')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->label('Bijgewerkt')
                    ->dateTime(),
            ]);
    }
}
