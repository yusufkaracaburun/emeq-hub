<?php

declare(strict_types=1);

namespace App\Filament\Resources\DemoRequests\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DemoRequestInfolist
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
                    TextEntry::make('preferred_slot')->label('Voorkeursmoment')->badge(),
                    TextEntry::make('status')->label('Status')->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'handled' => 'success',
                            'declined' => 'gray',
                            default => 'warning',
                        }),
                    TextEntry::make('privacy_accepted_at')->label('Akkoord privacybeleid')->dateTime(),
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
