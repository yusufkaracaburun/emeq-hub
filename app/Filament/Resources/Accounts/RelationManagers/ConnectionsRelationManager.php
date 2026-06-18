<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Filament\Actions\StartOAuthFlowAction;
use App\Filament\Resources\Connections\ConnectionResource;
use App\Models\Account;
use App\Models\Connection;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;

final class ConnectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'connections';

    protected static ?string $title = 'Connections';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->headerActions([
                Action::make('addConnection')
                    ->label('Connection toevoegen')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Provider kiezen')
                    ->schema([
                        Select::make('provider')
                            ->label('Provider')
                            ->helperText('Alleen providers met OAuth-flow zijn beschikbaar.')
                            ->options(StartOAuthFlowAction::oauthCapableProviders())
                            ->required(),
                    ])
                    ->modalSubmitActionLabel('Start koppeling')
                    ->visible(fn (): bool => auth()->user()?->can('manage-connections') ?? false)
                    ->action(function (array $data): RedirectResponse|Redirector {
                        /** @var Account $account */
                        $account = $this->getOwnerRecord();

                        return StartOAuthFlowAction::dispatch($account, $data['provider']);
                    }),
            ])
            ->recordUrl(fn (Connection $record): string => ConnectionResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('provider')
                    ->label('Provider')
                    ->badge(),
                TextColumn::make('fingerprint')
                    ->label('Fingerprint')
                    ->state(fn (Connection $record): ?string => $record->fingerprint())
                    ->placeholder('—')
                    ->fontFamily('mono'),
                TextColumn::make('expires_at')
                    ->label('Token expires')
                    ->dateTime('d-m-Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                IconColumn::make('revoked_at')
                    ->label('Revoked')
                    ->boolean()
                    ->trueIcon('heroicon-o-x-circle')
                    ->trueColor('danger')
                    ->falseIcon('heroicon-o-check-circle')
                    ->falseColor('success'),
                TextColumn::make('created_at')
                    ->label('Aangemaakt')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
