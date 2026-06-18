<?php

declare(strict_types=1);

namespace App\Filament\Resources\PassThroughCalls\Tables;

use App\Enums\Provider;
use App\Models\Consumer;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PassThroughCallsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('direction')
                    ->label('Direction')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'inbound' => 'info',
                        'outbound' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('provider')
                    ->label('Provider')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Provider::tryFrom($state ?? '')?->getLabel() ?? ($state ?? '—'))
                    ->color(fn (?string $state): string => Provider::tryFrom($state ?? '')?->getColor() ?? 'gray')
                    ->sortable(),
                TextColumn::make('method')
                    ->label('Method')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('path')
                    ->label('Path')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?int $state): string => match (intdiv((int) $state, 100)) {
                        2 => 'success',
                        3 => 'gray',
                        4 => 'warning',
                        5 => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('duration_ms')
                    ->label('Duur')
                    ->suffix(' ms')
                    ->sortable(),
                TextColumn::make('consumer.slug')
                    ->label('Consumer')
                    ->placeholder('—'),
                TextColumn::make('token_type')
                    ->label('Token-type')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Aangemaakt')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('direction')
                    ->options([
                        'inbound' => 'Inbound',
                        'outbound' => 'Outbound',
                    ]),
                SelectFilter::make('provider')
                    ->options(Provider::class),
                SelectFilter::make('status_class')
                    ->label('Status')
                    ->options([
                        'success' => '2xx · success',
                        'client_error' => '4xx · client error',
                        'server_error' => '5xx · server error',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'] ?? null,
                            fn (Builder $q, string $class): Builder => match ($class) {
                                'success' => $q->whereBetween('status', [200, 299]),
                                'client_error' => $q->whereBetween('status', [400, 499]),
                                'server_error' => $q->where('status', '>=', 500),
                                default => $q,
                            }
                        );
                    }),
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
            ->poll('30s')
            ->emptyStateHeading('Nog geen pass-through-calls')
            ->emptyStateDescription('Zodra een consumer via de Hub een partner-API aanroept, verschijnt hier een auditrij.')
            ->emptyStateIcon('heroicon-o-arrows-right-left')
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
