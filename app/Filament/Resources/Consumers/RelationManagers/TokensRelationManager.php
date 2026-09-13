<?php

declare(strict_types=1);

namespace App\Filament\Resources\Consumers\RelationManagers;

use App\Filament\Resources\Consumers\ConsumerResource;
use App\Models\Consumer;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;

final class TokensRelationManager extends RelationManager
{
    protected static string $relationship = 'tokens';

    protected static ?string $title = 'Tokens (PAT)';

    protected static bool $isReadOnly = false;

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->headerActions([
                Action::make('issuePat')
                    ->label('Issue PAT')
                    ->icon(Heroicon::OutlinedKey)
                    ->authorize(fn (): bool => auth()->user()?->can('manage-consumers') ?? false)
                    ->modalHeading('Issue Personal Access Token')
                    ->modalSubmitActionLabel('Issue')
                    ->schema(ConsumerResource::patFormSchema())
                    ->action(function (array $data): RedirectResponse|Redirector {
                        /** @var Consumer $consumer */
                        $consumer = $this->getOwnerRecord();

                        $result = $consumer->createToken($data['name'], ConsumerResource::resolvePatAbilities($data));

                        $userId = auth()->id();
                        Cache::put("pat-flash:user:{$userId}", $result->plainTextToken, now()->addSeconds(60));
                        Cache::put("pat-flash-name:user:{$userId}", $data['name'], now()->addSeconds(60));

                        Notification::make()
                            ->title('PAT uitgegeven — token verschijnt eenmalig op de Consumers-lijst')
                            ->success()
                            ->send();

                        return redirect(ConsumerResource::getUrl());
                    }),
            ])
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Naam')
                    ->searchable(),
                TextColumn::make('abilities')
                    ->label('Abilities')
                    ->badge()
                    ->separator(','),
                TextColumn::make('last_used_at')
                    ->label('Laatst gebruikt')
                    ->dateTime('d-m-Y H:i')
                    ->placeholder('nooit')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Uitgegeven')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label('Intrekken')
                    ->authorize(fn (): bool => auth()->user()?->can('manage-consumers') ?? false)
                    ->modalHeading('Token intrekken')
                    ->modalDescription(fn (PersonalAccessToken $record): string => "Token '{$record->name}' wordt direct ongeldig. Apps die het gebruiken krijgen 401 tot ze een nieuw token hebben."),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
