<?php

declare(strict_types=1);

namespace App\Filament\Resources\Connections\RelationManagers;

use App\Filament\Resources\InboundWebhookEvents\InboundWebhookEventResource;
use App\Models\InboundWebhookEvent;
use App\Support\Filament\BadgeColor;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Inbound partner→Hub-webhooks die op deze Connection zijn gerouteerd — read-only
 * relatie op de Connection-detailpagina. Klik door naar de audit-detail.
 */
final class InboundWebhookEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'inboundWebhookEvents';

    protected static ?string $title = 'Inbound webhooks';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('topic')
                    ->label('Topic')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('action')
                    ->label('Action')
                    ->placeholder('—'),
                TextColumn::make('outcome')
                    ->label('Outcome')
                    ->badge()
                    ->color(fn (?string $state): string => BadgeColor::webhookOutcome($state))
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?int $state): string => BadgeColor::httpStatus($state))
                    ->sortable(),
                TextColumn::make('received_at')
                    ->label('Ontvangen')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('received_at', 'desc')
            ->recordUrl(fn (InboundWebhookEvent $record): string => InboundWebhookEventResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('Nog geen inbound webhooks voor deze koppeling');
    }
}
