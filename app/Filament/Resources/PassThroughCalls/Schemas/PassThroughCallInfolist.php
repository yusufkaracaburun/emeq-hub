<?php

declare(strict_types=1);

namespace App\Filament\Resources\PassThroughCalls\Schemas;

use App\Enums\Provider;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PassThroughCallInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Call')
                ->columns(2)
                ->schema([
                    TextEntry::make('direction')->badge(),
                    TextEntry::make('provider')->badge()
                        ->formatStateUsing(fn (?string $state): string => Provider::tryFrom($state ?? '')?->getLabel() ?? ($state ?? '—'))
                        ->color(fn (?string $state): string => Provider::tryFrom($state ?? '')?->getColor() ?? 'gray'),
                    TextEntry::make('method')->badge()->color('gray'),
                    TextEntry::make('path')->copyable(),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('duration_ms')->label('Duur')->suffix(' ms'),
                    TextEntry::make('token_type')->label('Token-type')->placeholder('—'),
                ]),

            Section::make('Scope')
                ->columns(2)
                ->schema([
                    TextEntry::make('consumer.slug')->label('Consumer')->placeholder('—'),
                    TextEntry::make('account.external_id')->label('Account')->placeholder('—'),
                    TextEntry::make('connection.provider')->label('Connection')->badge()->placeholder('—'),
                    TextEntry::make('event_id')->label('Event ID')->placeholder('—'),
                ]),

            Section::make('Diagnostics (fingerprint-only — geen rauwe tokens)')
                ->columns(2)
                ->schema([
                    TextEntry::make('request_fingerprint')->label('Request-fingerprint')->copyable()->placeholder('—'),
                    TextEntry::make('partner_token_fingerprint')->label('Partner-token-fingerprint')->copyable()->placeholder('—'),
                    TextEntry::make('response_size_bytes')->label('Response (bytes)')->placeholder('—'),
                    TextEntry::make('query_keys')->label('Query-keys')->placeholder('—'),
                    TextEntry::make('upstream_error')->label('Upstream-error')->placeholder('—'),
                    TextEntry::make('created_at')->label('Aangemaakt')->dateTime(),
                ]),

            Section::make('Response-body (alleen bij fouten ≥400)')
                ->visible(fn ($record): bool => filled($record->response_body))
                ->schema([
                    TextEntry::make('response_body')
                        ->hiddenLabel()
                        ->copyable()
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
