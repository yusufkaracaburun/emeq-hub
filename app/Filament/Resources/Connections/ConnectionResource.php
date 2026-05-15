<?php

namespace App\Filament\Resources\Connections;

use App\Filament\Resources\Connections\Pages\ListConnections;
use App\Filament\Resources\Connections\Pages\ViewConnection;
use App\Models\Connection;
use App\OAuth\OAuthFlowRegistry;
use App\Support\ProviderCredentialDescriptor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ConnectionResource extends Resource
{
    protected static ?string $model = Connection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    public static function form(Schema $schema): Schema
    {
        // Read-only resource: form() bestaat om Filament's contract te honoreren
        // maar exposeert niets — alle weergave loopt via infolist().
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Mollie OAuth')
                ->visible(fn (?Connection $record): bool => $record?->provider === 'mollie')
                ->columns(2)
                ->schema([
                    TextEntry::make('provider')->badge()->color('success'),
                    TextEntry::make('account.external_id')->label('Account'),
                    TextEntry::make('fingerprint')
                        ->label('Fingerprint (sha256[:12])')
                        ->state(fn (Connection $record): ?string => $record->fingerprint())
                        ->copyable()
                        ->placeholder('—'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('expires_at')->dateTime()->placeholder('—'),
                    TextEntry::make('scopes')
                        ->listWithLineBreaks()
                        ->placeholder('—'),
                    TextEntry::make('revoked_at')->dateTime()->placeholder('—'),
                    TextEntry::make('created_at')->dateTime(),
                ]),

            Section::make('Snelstart credentials')
                ->visible(fn (?Connection $record): bool => $record?->provider === 'snelstart')
                ->columns(2)
                ->schema([
                    TextEntry::make('provider')->badge()->color('info'),
                    TextEntry::make('account.external_id')->label('Account'),
                    TextEntry::make('fingerprint')
                        ->label('Fingerprint (sha256[:12])')
                        ->state(fn (Connection $record): ?string => $record->fingerprint())
                        ->copyable()
                        ->placeholder('—'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('subscription_id')
                        ->label('Subscription ID')
                        ->placeholder('—'),
                    TextEntry::make('administratie_id')
                        ->label('Administratie ID')
                        ->placeholder('—'),
                    TextEntry::make('revoked_at')->dateTime()->placeholder('—'),
                    TextEntry::make('created_at')->dateTime(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')
                    ->badge()
                    ->colors([
                        'success' => 'mollie',
                        'info' => 'snelstart',
                    ])
                    ->sortable(),
                TextColumn::make('account.external_id')
                    ->label('Account')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('fingerprint')
                    ->label('Fingerprint')
                    ->state(fn (Connection $record): ?string => $record->fingerprint())
                    ->placeholder('—'),
                TextColumn::make('revoked_at')
                    ->label('Revoked at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('provider')
                    ->options([
                        'mollie' => 'Mollie',
                        'snelstart' => 'Snelstart',
                    ]),
                SelectFilter::make('consumer')
                    ->relationship('account.consumer', 'slug')
                    ->label('Consumer'),
                TernaryFilter::make('revoked')
                    ->label('Revoked?')
                    ->nullable()
                    ->trueLabel('Revoked')
                    ->falseLabel('Active')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('revoked_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('revoked_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('revoke')
                    ->label('Revoke')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Connection intrekken bij provider')
                    ->modalDescription('Dit roept de upstream OAuth-revoke aan en zet revoked_at lokaal. Niet ongedaan te maken.')
                    ->visible(function (Connection $record): bool {
                        if ($record->revoked_at !== null) {
                            return false;
                        }

                        try {
                            $descriptor = ProviderCredentialDescriptor::for($record->provider);
                        } catch (\InvalidArgumentException) {
                            return false;
                        }

                        return $descriptor->oauthFlowKey !== null;
                    })
                    ->action(function (Connection $record): void {
                        app(OAuthFlowRegistry::class)
                            ->for($record->provider)
                            ->revoke($record);

                        Notification::make()
                            ->title('Connection ingetrokken')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConnections::route('/'),
            'view' => ViewConnection::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
