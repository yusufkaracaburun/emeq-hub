<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookCalls\Tables;

use App\Models\Consumer;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\WebhookClient\Models\WebhookCall;

class WebhookCallsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('direction')
                    ->label('Direction')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'incoming' => 'info',
                        'outgoing' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('provider')
                    ->label('Provider')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'mollie' => 'warning',
                        'snelstart' => 'info',
                        'cashier' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('consumer.slug')
                    ->label('Consumer')
                    ->placeholder('—'),
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'processed' => 'success',
                        'pending' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Aangemaakt')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('direction')
                    ->options([
                        'incoming' => 'Incoming',
                        'outgoing' => 'Outgoing',
                    ]),
                SelectFilter::make('provider')
                    ->options(fn (): array => WebhookCall::query()
                        ->whereNotNull('provider')
                        ->distinct()
                        ->orderBy('provider')
                        ->pluck('provider', 'provider')
                        ->all()),
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'processed' => 'Processed',
                        'failed' => 'Failed',
                    ]),
                SelectFilter::make('consumer_id')
                    ->label('Consumer')
                    ->options(fn (): array => Consumer::query()
                        ->orderBy('slug')
                        ->pluck('slug', 'id')
                        ->all())
                    ->searchable(),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('Van'),
                        DatePicker::make('until')->label('Tot'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '>=', $date)
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '<=', $date)
                            );
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
