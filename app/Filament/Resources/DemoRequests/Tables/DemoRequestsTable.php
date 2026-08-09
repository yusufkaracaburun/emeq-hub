<?php

declare(strict_types=1);

namespace App\Filament\Resources\DemoRequests\Tables;

use App\Filament\Resources\DemoRequests\DemoRequestResource;
use App\Models\DemoRequest;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DemoRequestsTable
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
                TextColumn::make('preferred_slot')
                    ->label('Voorkeursmoment')
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
                Action::make('handle')
                    ->label('Markeer afgehandeld')
                    ->icon('heroicon-o-check')
                    ->color('gray')
                    ->visible(fn (DemoRequest $record): bool => $record->status === 'new')
                    ->requiresConfirmation()
                    ->action(fn (DemoRequest $record) => $record->update(['status' => 'handled'])),
                Action::make('decline')
                    ->label('Afwijzen')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (DemoRequest $record): bool => $record->status === 'new')
                    ->requiresConfirmation()
                    ->modalDescription('De aanvraag blijft bewaard, maar verdwijnt uit de nieuwe-inbox.')
                    ->action(fn (DemoRequest $record) => $record->update(['status' => 'declined'])),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->poll('30s')
            ->emptyStateHeading('Nog geen demo-aanvragen')
            ->emptyStateDescription('Aanvragen vanaf de publieke /demo-pagina verschijnen hier.')
            ->emptyStateIcon('heroicon-o-calendar-days')
            ->recordUrl(fn (DemoRequest $record): string => DemoRequestResource::getUrl('view', ['record' => $record]));
    }
}
