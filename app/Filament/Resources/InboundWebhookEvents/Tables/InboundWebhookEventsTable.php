<?php

declare(strict_types=1);

namespace App\Filament\Resources\InboundWebhookEvents\Tables;

use App\Models\Consumer;
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
                    ->color(fn (?string $state): string => match ($state) {
                        'processed' => 'success',
                        'duplicate' => 'gray',
                        'unknown_tenant' => 'warning',
                        'malformed', 'invalid_signature', 'misconfigured' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?int $state): string => match (intdiv((int) $state, 100)) {
                        2 => 'success',
                        4 => 'warning',
                        5 => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('fanout_status')
                    ->label('Fan-out')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'dispatched' => 'success',
                        'skipped_no_callback' => 'warning',
                        default => 'gray',
                    })
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
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
