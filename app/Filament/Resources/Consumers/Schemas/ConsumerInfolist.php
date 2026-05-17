<?php

declare(strict_types=1);

namespace App\Filament\Resources\Consumers\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Plan 08-04 — Infolist voor ConsumerResource met D-07 hint-Section bovenaan.
 *
 * Eerste Section bevat canonical D-07 / UI-SPEC §S4 copy, default-collapsed,
 * geen interactie behalve native collapse/expand. Daarna basis Consumer-velden.
 */
class ConsumerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Wat is een Consumer?')
                    ->description('Eén SaaS-app die de Hub gebruikt (Naschool, Planny, externe app). Authenticeert met een Bearer-PAT. Een Consumer heeft Accounts (zijn klanten) en die Accounts hebben Connections (partner-koppelingen).')
                    ->collapsed()
                    ->schema([]),
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
