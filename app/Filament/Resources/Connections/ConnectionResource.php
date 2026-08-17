<?php

namespace App\Filament\Resources\Connections;

use App\Enums\Provider;
use App\Filament\Actions\ManageAccountingMappingAction;
use App\Filament\Actions\ManageWebhookSubscriptionsAction;
use App\Filament\Actions\StartOAuthFlowAction;
use App\Filament\Resources\Connections\Pages\ListConnections;
use App\Filament\Resources\Connections\Pages\ViewConnection;
use App\Filament\Widgets\OperationalHealthWidget;
use App\Integrations\OAuth\OAuthFlowRegistry;
use App\Models\Connection;
use App\Support\Filament\BadgeColor;
use App\Support\ProviderAccess;
use App\Support\ProviderCredentialDescriptor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\KeyValueEntry;
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

    protected static string|\UnitEnum|null $navigationGroup = 'Koppelingen';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-connections') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = OperationalHealthWidget::expiringConnectionCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        // Read-only resource: form() bestaat om Filament's contract te honoreren
        // maar exposeert niets — alle weergave loopt via infolist().
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Mollie OAuth')
                ->visible(fn (?Connection $record): bool => $record?->provider === Provider::Mollie)
                ->columns(2)
                ->schema([
                    TextEntry::make('provider')->badge()->color(Provider::Mollie->getColor()),
                    TextEntry::make('account.external_id')->label('Account'),
                    TextEntry::make('fingerprint')
                        ->label('Fingerprint (sha256[:12])')
                        ->state(fn (Connection $record): ?string => $record->fingerprint())
                        ->copyable()
                        ->placeholder('—'),
                    TextEntry::make('status')->badge()
                        ->color(fn (?string $state): string => BadgeColor::connectionStatus($state)),
                    TextEntry::make('expires_at')->dateTime()->placeholder('—'),
                    TextEntry::make('scopes')
                        ->listWithLineBreaks()
                        ->placeholder('—'),
                    TextEntry::make('revoked_at')->dateTime()->placeholder('—'),
                    TextEntry::make('created_at')->dateTime(),
                ]),

            Section::make('Exact Online OAuth')
                ->visible(fn (?Connection $record): bool => $record?->provider === Provider::Exact)
                ->columns(2)
                ->schema([
                    TextEntry::make('provider')->badge()->color(Provider::Exact->getColor()),
                    TextEntry::make('account.external_id')->label('Account'),
                    TextEntry::make('fingerprint')
                        ->label('Fingerprint (sha256[:12])')
                        ->state(fn (Connection $record): ?string => $record->fingerprint())
                        ->copyable()
                        ->placeholder('—'),
                    TextEntry::make('status')->badge()
                        ->color(fn (?string $state): string => BadgeColor::connectionStatus($state)),
                    TextEntry::make('expires_at')->dateTime()->placeholder('—'),
                    TextEntry::make('administratie_id')
                        ->label('Division')
                        ->placeholder('—'),
                    TextEntry::make('revoked_at')->dateTime()->placeholder('—'),
                    TextEntry::make('created_at')->dateTime(),
                ]),

            Section::make('Snelstart credentials')
                ->visible(fn (?Connection $record): bool => $record?->provider === Provider::Snelstart)
                ->columns(2)
                ->schema([
                    TextEntry::make('provider')->badge()->color(Provider::Snelstart->getColor()),
                    TextEntry::make('account.external_id')->label('Account'),
                    TextEntry::make('fingerprint')
                        ->label('Fingerprint (sha256[:12])')
                        ->state(fn (Connection $record): ?string => $record->fingerprint())
                        ->copyable()
                        ->placeholder('—'),
                    TextEntry::make('status')->badge()
                        ->color(fn (?string $state): string => BadgeColor::connectionStatus($state)),
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

    /**
     * @return list<Section>
     */
    public static function accessSchema(Connection $record): array
    {
        $resources = ProviderAccess::describeResources($record->provider);

        return [
            Section::make('Verleende scopes')
                ->description(ProviderAccess::note($record->provider))
                ->schema([
                    KeyValueEntry::make('granted_scopes')
                        ->hiddenLabel()
                        ->state(ProviderAccess::describeScopes($record->scopes))
                        ->keyLabel('Scope')
                        ->valueLabel('Wat dit toestaat')
                        ->emptyMessage('Deze partner geeft geen scopes mee bij het koppelen.'),
                ]),

            Section::make('Resources die de Hub doorlaat')
                ->description('Pass-through-whitelist uit config/hub-providers.php — alles daarbuiten weigert de Hub, ongeacht wat de partner zou toestaan.')
                ->visible($resources !== [])
                ->schema([
                    KeyValueEntry::make('allowed_resources')
                        ->hiddenLabel()
                        ->state($resources)
                        ->keyLabel('Resource')
                        ->valueLabel('Waarvoor'),
                ]),
        ];
    }

    /**
     * @return list<Section>
     */
    public static function accountingMappingSchema(Connection $record): array
    {
        $mapping = $record->metadata['accounting_mapping'] ?? [];

        return [
            Section::make('Boekhoud-mapping')
                ->description('Gebruikt door de accounting-sync. Wordt na het koppelen afgeleid uit de mirror en is te wijzigen via "Boekhoud-mapping" in de header.')
                ->columns(2)
                ->schema([
                    KeyValueEntry::make('vat_codes')
                        ->label('BTW-tarief → VATCode')
                        ->state($mapping['vat_codes'] ?? [])
                        ->keyLabel('Tarief')
                        ->valueLabel('VATCode')
                        ->emptyMessage('Geen tarieven gemapt'),
                    KeyValueEntry::make('journals')
                        ->label('Doc-type → dagboek')
                        ->state($mapping['journals'] ?? [])
                        ->keyLabel('Type')
                        ->valueLabel('Dagboek')
                        ->emptyMessage('Geen dagboeken gemapt'),
                    KeyValueEntry::make('gl_accounts')
                        ->label('Categorie → GLAccount (GUID)')
                        ->state($mapping['gl_accounts'] ?? [])
                        ->keyLabel('Categorie')
                        ->valueLabel('GLAccount')
                        ->emptyMessage('Geen grootboek gemapt'),
                    KeyValueEntry::make('relations')
                        ->label('Relatie → GUID')
                        ->state($mapping['relations'] ?? [])
                        ->keyLabel('Relatie')
                        ->valueLabel('GUID')
                        ->emptyMessage('Geen relaties gemapt'),
                ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')
                    ->badge()
                    ->sortable(),
                TextColumn::make('account.external_id')
                    ->label('Account')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => BadgeColor::connectionStatus($state))
                    ->state(fn (Connection $record): string => $record->revoked_at ? 'revoked' : $record->status)
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
                    ->options(Provider::class),
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'pending' => 'Pending',
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
            ->recordUrl(fn (Connection $record): string => self::getUrl('view', ['record' => $record]))
            ->recordActions([
                StartOAuthFlowAction::forConnection()->iconButton(),
                ManageAccountingMappingAction::make()->iconButton(),
                ManageWebhookSubscriptionsAction::make()->iconButton(),
                self::revokeAction()->iconButton(),
            ])
            ->toolbarActions([]);
    }

    /**
     * Revoke-actie — gedeeld door de lijst-rij (icoon) en de Connection-detail-header.
     * Roept de upstream OAuth-revoke aan en zet revoked_at lokaal; alleen zichtbaar
     * voor een nog-actieve OAuth-connection.
     */
    public static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label('Revoke')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Connection intrekken bij provider')
            ->modalDescription('Dit roept de upstream OAuth-revoke aan en zet revoked_at lokaal. Niet ongedaan te maken.')
            ->visible(fn (Connection $record): bool => self::hasLiveOAuthLifecycle($record))
            ->action(function (Connection $record): void {
                app(OAuthFlowRegistry::class)
                    ->for($record->provider->value)
                    ->revoke($record);

                Notification::make()
                    ->title('Connection ingetrokken')
                    ->success()
                    ->send();
            });
    }

    private static function hasLiveOAuthLifecycle(Connection $record): bool
    {
        if ($record->revoked_at !== null) {
            return false;
        }

        try {
            $descriptor = ProviderCredentialDescriptor::for($record->provider->value);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $descriptor->oauthFlowKey !== null;
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\AccountingRefsRelationManager::class,
            RelationManagers\PassThroughCallsRelationManager::class,
            RelationManagers\InboundWebhookEventsRelationManager::class,
        ];
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
