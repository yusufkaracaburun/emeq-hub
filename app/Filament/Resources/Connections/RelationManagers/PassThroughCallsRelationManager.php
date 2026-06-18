<?php

declare(strict_types=1);

namespace App\Filament\Resources\Connections\RelationManagers;

use App\Filament\Resources\PassThroughCalls\PassThroughCallResource;
use App\Models\PassThroughCall;
use App\Support\Filament\BadgeColor;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Pass-through-calls die via deze Connection liepen — read-only relatie op de
 * Connection-detailpagina. Klik door naar de audit-detail.
 */
final class PassThroughCallsRelationManager extends RelationManager
{
    protected static string $relationship = 'passThroughCalls';

    protected static ?string $title = 'Pass-through calls';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('method')
                    ->label('Method')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('path')
                    ->label('Path')
                    ->limit(40)
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?int $state): string => BadgeColor::httpStatus($state))
                    ->sortable(),
                TextColumn::make('duration_ms')
                    ->label('Duur')
                    ->suffix(' ms')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Aangemaakt')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (PassThroughCall $record): string => PassThroughCallResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('Nog geen pass-through-calls voor deze koppeling');
    }
}
