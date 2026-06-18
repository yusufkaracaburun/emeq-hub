<?php

declare(strict_types=1);

namespace App\Filament\Resources\InboundWebhookEvents\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InboundWebhookEventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Event')
                ->columns(2)
                ->schema([
                    TextEntry::make('provider')->badge(),
                    TextEntry::make('topic')->placeholder('—'),
                    TextEntry::make('action')->placeholder('—'),
                    TextEntry::make('outcome')->badge(),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('fanout_status')->label('Fan-out')->badge()->placeholder('—'),
                    TextEntry::make('received_at')->label('Ontvangen')->dateTime(),
                ]),

            Section::make('Scope')
                ->columns(2)
                ->schema([
                    TextEntry::make('consumer.slug')->label('Consumer')->placeholder('—'),
                    TextEntry::make('account.external_id')->label('Account')->placeholder('—'),
                    TextEntry::make('connection.provider')->label('Connection')->badge()->placeholder('—'),
                    TextEntry::make('event_id')->label('Event ID')->copyable()->placeholder('—'),
                    TextEntry::make('request_fingerprint')->label('Request-fingerprint')->copyable()->placeholder('—'),
                ]),
        ]);
    }
}
