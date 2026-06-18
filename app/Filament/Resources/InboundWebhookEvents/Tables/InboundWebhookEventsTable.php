<?php

declare(strict_types=1);

namespace App\Filament\Resources\InboundWebhookEvents\Tables;

use App\Enums\Provider;
use App\Models\Consumer;
use App\Support\Filament\BadgeColor;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InboundWebhookEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')
                    ->label('Provider')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Provider::tryFrom($state ?? '')?->getLabel() ?? ($state ?? '—'))
                    ->color(fn (?string $state): string => Provider::tryFrom($state ?? '')?->getColor() ?? 'gray')
                    ->sortable(),
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
                TextColumn::make('fanout_status')
                    ->label('Fan-out')
                    ->badge()
                    ->color(fn (?string $state): string => BadgeColor::fanoutStatus($state))
                    ->placeholder('—'),
                TextColumn::make('consumer.slug')
                    ->label('Consumer')
                    ->placeholder('—'),
                TextColumn::make('received_at')
                    ->label('Ontvangen')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('provider')
                    ->options([
                        'mollie' => 'Mollie',
                        'snelstart' => 'Snelstart',
                        'exact' => 'Exact',
                        'cashier' => 'Cashier',
                    ]),
                SelectFilter::make('outcome')
                    ->options([
                        'processed' => 'Processed',
                        'duplicate' => 'Duplicate',
                        'unknown_tenant' => 'Unknown tenant',
                        'malformed' => 'Malformed',
                        'invalid_signature' => 'Invalid signature',
                        'misconfigured' => 'Misconfigured',
                    ]),
                SelectFilter::make('consumer_id')
                    ->label('Consumer')
                    ->options(fn (): array => Consumer::query()
                        ->orderBy('slug')
                        ->pluck('slug', 'id')
                        ->all())
                    ->searchable(),
                Filter::make('received_at')
                    ->schema([
                        DatePicker::make('from')->label('Van'),
                        DatePicker::make('until')->label('Tot'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $q, string $date): Builder => $q->whereDate('received_at', '>=', $date)
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $q, string $date): Builder => $q->whereDate('received_at', '<=', $date)
                            );
                    }),
            ])
            ->defaultSort('received_at', 'desc')
            ->poll('30s')
            ->emptyStateHeading('Nog geen inbound webhook-events')
            ->emptyStateDescription('Zodra een partner (Mollie/Snelstart/Exact/Cashier) een webhook naar de Hub stuurt, verschijnt hier een metadata-auditrij.')
            ->emptyStateIcon('heroicon-o-inbox-arrow-down')
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
