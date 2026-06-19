<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccessRequests\Tables;

use App\Filament\Pages\OnboardConsumer;
use App\Filament\Resources\AccessRequests\AccessRequestResource;
use App\Models\AccessRequest;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AccessRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company')
                    ->label('Bedrijf')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('contact_name')
                    ->label('Contact')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('providers')
                    ->label('Integraties')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'handled' => 'success',
                        'declined' => 'gray',
                        default => 'warning',
                    })
                    ->sortable(),
                TextColumn::make('consumer.slug')
                    ->label('Consumer')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Ontvangen')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'new' => 'Nieuw',
                        'handled' => 'Afgehandeld',
                        'declined' => 'Afgewezen',
                    ]),
            ])
            ->recordActions([
                Action::make('onboard')
                    ->label('Onboard')
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->visible(fn (AccessRequest $record): bool => $record->status === 'new')
                    ->url(fn (AccessRequest $record): string => OnboardConsumer::getUrl(['from_request' => $record->id])),
                Action::make('handle')
                    ->label('Markeer afgehandeld')
                    ->icon('heroicon-o-check')
                    ->color('gray')
                    ->visible(fn (AccessRequest $record): bool => $record->status === 'new')
                    ->requiresConfirmation()
                    ->action(fn (AccessRequest $record) => $record->update(['status' => 'handled'])),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->poll('30s')
            ->emptyStateHeading('Nog geen koppel-aanvragen')
            ->emptyStateDescription('Aanvragen vanaf de koppel-formulieren op de partner-pagina\'s verschijnen hier.')
            ->emptyStateIcon('heroicon-o-envelope')
            ->recordUrl(fn (AccessRequest $record): string => AccessRequestResource::getUrl('view', ['record' => $record]));
    }
}
