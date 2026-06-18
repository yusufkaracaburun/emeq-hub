<?php

declare(strict_types=1);

namespace App\Filament\Resources\InboundWebhookEvents\Schemas;

use App\Enums\Provider;
use App\Support\Filament\BadgeColor;
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
                    TextEntry::make('provider')->badge()
                        ->formatStateUsing(fn (?string $state): string => Provider::tryFrom($state ?? '')?->getLabel() ?? ($state ?? '—'))
                        ->color(fn (?string $state): string => Provider::tryFrom($state ?? '')?->getColor() ?? 'gray'),
                    TextEntry::make('topic')->placeholder('—'),
                    TextEntry::make('action')->placeholder('—'),
                    TextEntry::make('outcome')->badge()
                        ->color(fn (?string $state): string => BadgeColor::webhookOutcome($state)),
                    TextEntry::make('status')->badge()
                        ->color(fn (?int $state): string => BadgeColor::httpStatus($state)),
                    TextEntry::make('fanout_status')->label('Fan-out')->badge()
                        ->color(fn (?string $state): string => BadgeColor::fanoutStatus($state))
                        ->placeholder('—'),
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
