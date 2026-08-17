<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccessRequests\Schemas;

use App\Support\Filament\BadgeColor;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AccessRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Aanvraag')
                ->columns(2)
                ->schema([
                    TextEntry::make('company')->label('Bedrijf'),
                    TextEntry::make('contact_name')->label('Contact'),
                    TextEntry::make('email')->label('E-mail')->copyable(),
                    TextEntry::make('app_url')->label('App-URL')->placeholder('—')->copyable(),
                    TextEntry::make('providers')->label('Integraties')->badge(),
                    TextEntry::make('status')->label('Status')->badge()
                        ->color(fn (?string $state): string => BadgeColor::requestStatus($state)),
                    TextEntry::make('consumer.slug')->label('Ge-onboard als')->placeholder('— nog niet'),
                    TextEntry::make('created_at')->label('Ontvangen')->dateTime(),
                ]),

            Section::make('Bericht')
                ->visible(fn ($record): bool => filled($record->message))
                ->schema([
                    TextEntry::make('message')->label('')->prose(),
                ]),
        ]);
    }
}
