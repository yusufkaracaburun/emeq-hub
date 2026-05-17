<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccountSubscriptions;

use App\Billing\Account\AccountSubscriptionManager;
use App\Billing\Account\Exceptions\InvalidStateTransitionException;
use App\Billing\Account\SubscriptionStatus;
use App\Filament\Resources\AccountSubscriptions\Pages\ListAccountSubscriptions;
use App\Filament\Resources\AccountSubscriptions\Pages\ViewAccountSubscription;
use App\Models\AccountSubscription;
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
use Filament\Tables\Table;
use Throwable;

/**
 * Plan 09-08 — Read-only Filament-Resource voor AccountSubscription met
 * 3 state-flip-actions (Pause/Resume/Cancel) die uitsluitend via
 * AccountSubscriptionManager (Phase 7-03) lopen.
 *
 * Geen Create/Edit-pages: subscription-create gebeurt via
 * POST /v1/account-subscriptions (Phase 7-04).
 */
class AccountSubscriptionResource extends Resource
{
    protected static ?string $model = AccountSubscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $recordTitleAttribute = 'description';

    protected static string|\UnitEnum|null $navigationGroup = 'Abonnementen';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-account-subscriptions') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        // Read-only Resource — geen Create/Edit pages. Schema blijft leeg.
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Subscription')
                ->columns(2)
                ->schema([
                    TextEntry::make('account.external_id')->label('Account'),
                    TextEntry::make('connection.provider')->badge(),
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn (SubscriptionStatus $state): string => self::statusColor($state)),
                    TextEntry::make('amount')
                        ->state(fn (AccountSubscription $record): string => $record->amount_value.' '.$record->amount_currency),
                    TextEntry::make('interval'),
                    TextEntry::make('description'),
                ]),

            Section::make('Mollie-IDs (opaque references — geen secrets per Phase 7 D-02)')
                ->columns(3)
                ->schema([
                    TextEntry::make('mollie_customer_id')->label('Customer ID')->copyable(),
                    TextEntry::make('mollie_subscription_id')->label('Subscription ID')->copyable(),
                    TextEntry::make('mollie_mandate_id')->label('Mandate ID')->copyable(),
                ]),

            Section::make('Status timestamps')
                ->columns(2)
                ->schema([
                    TextEntry::make('starts_at')->dateTime(),
                    TextEntry::make('paused_at')->dateTime()->placeholder('—'),
                    TextEntry::make('canceled_at')->dateTime()->placeholder('—'),
                    TextEntry::make('completed_at')->dateTime()->placeholder('—'),
                    TextEntry::make('last_webhook_event_at')->dateTime()->placeholder('—'),
                    TextEntry::make('last_payment_status')->placeholder('—'),
                ]),

            Section::make('Metadata')
                ->schema([
                    TextEntry::make('metadata')
                        ->state(fn (AccountSubscription $record): string => $record->metadata === null
                            ? '—'
                            : json_encode($record->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                        ->columnSpanFull(),
                ])
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('account.external_id')
                    ->label('Account')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('connection.provider')
                    ->label('Provider')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (SubscriptionStatus $state): string => self::statusColor($state))
                    ->sortable(),
                TextColumn::make('amount')
                    ->state(fn (AccountSubscription $record): string => $record->amount_value.' '.$record->amount_currency),
                TextColumn::make('interval'),
                TextColumn::make('description')
                    ->limit(48)
                    ->tooltip(fn (AccountSubscription $record): string => $record->description),
                TextColumn::make('last_webhook_event_at')
                    ->label('Last webhook')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(SubscriptionStatus::cases())
                        ->mapWithKeys(fn (SubscriptionStatus $s): array => [$s->value => $s->name])
                        ->all()),
                SelectFilter::make('connection_provider')
                    ->label('Provider')
                    ->relationship('connection', 'provider'),
                SelectFilter::make('account_id')
                    ->label('Account')
                    ->relationship('account', 'external_id'),
            ])
            ->recordActions([
                ViewAction::make(),
                self::pauseAction(),
                self::resumeAction(),
                self::cancelAction(),
            ])
            ->toolbarActions([]);
    }

    /**
     * Pause-action — alleen zichtbaar op Active. Delegates naar
     * AccountSubscriptionManager::pause (Phase 7-03 single-entry-point).
     * NOOIT direct $sub->update(['status' => ...]) (T-07-03-03 invariant).
     */
    private static function pauseAction(): Action
    {
        return Action::make('pause')
            ->label('Pauzeren')
            ->icon(Heroicon::Pause)
            ->color('warning')
            ->visible(fn (AccountSubscription $record): bool => $record->status === SubscriptionStatus::Active)
            ->requiresConfirmation()
            ->modalHeading('Subscription pauzeren')
            ->modalDescription('Dit zet de Hub-state op Paused. Mollie-side wordt niet aangeroepen (Phase 7-03 D-04 invariant).')
            ->action(function (AccountSubscription $record): void {
                try {
                    app(AccountSubscriptionManager::class)->pause($record, 'admin_panel_action');
                    Notification::make()
                        ->title('Subscription gepauzeerd')
                        ->success()
                        ->send();
                } catch (InvalidStateTransitionException $e) {
                    Notification::make()
                        ->title('Ongeldige state-transitie')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()
                        ->title('Pause-actie mislukt')
                        ->body('Zie logs voor details — fingerprint: '.substr(hash('sha256', $e->getMessage()), 0, 12))
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Resume-action — alleen zichtbaar op Paused. Delegates naar
     * AccountSubscriptionManager::resume.
     */
    private static function resumeAction(): Action
    {
        return Action::make('resume')
            ->label('Hervatten')
            ->icon(Heroicon::Play)
            ->color('success')
            ->visible(fn (AccountSubscription $record): bool => $record->status === SubscriptionStatus::Paused)
            ->requiresConfirmation()
            ->modalHeading('Subscription hervatten')
            ->action(function (AccountSubscription $record): void {
                try {
                    app(AccountSubscriptionManager::class)->resume($record);
                    Notification::make()
                        ->title('Subscription hervat')
                        ->success()
                        ->send();
                } catch (InvalidStateTransitionException $e) {
                    Notification::make()
                        ->title('Ongeldige state-transitie')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()
                        ->title('Resume-actie mislukt')
                        ->body('Zie logs voor details — fingerprint: '.substr(hash('sha256', $e->getMessage()), 0, 12))
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Cancel-action — zichtbaar op Active OF Paused. Delegates naar
     * AccountSubscriptionManager::cancel (roept ook Mollie SDK aan als
     * mollie_subscription_id niet null is).
     */
    private static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Annuleren')
            ->icon(Heroicon::XCircle)
            ->color('danger')
            ->visible(fn (AccountSubscription $record): bool => in_array(
                $record->status,
                [SubscriptionStatus::Active, SubscriptionStatus::Paused],
                strict: true,
            ))
            ->requiresConfirmation()
            ->modalHeading('Subscription annuleren')
            ->modalDescription('Dit roept Mollie cancelForId aan (indien mollie_subscription_id gevuld) en zet de Hub-state op Canceled. Niet terug te draaien.')
            ->action(function (AccountSubscription $record): void {
                try {
                    app(AccountSubscriptionManager::class)->cancel($record);
                    Notification::make()
                        ->title('Subscription geannuleerd')
                        ->success()
                        ->send();
                } catch (InvalidStateTransitionException $e) {
                    Notification::make()
                        ->title('Ongeldige state-transitie')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()
                        ->title('Cancel-actie mislukt')
                        ->body('Zie logs voor details — fingerprint: '.substr(hash('sha256', $e->getMessage()), 0, 12))
                        ->danger()
                        ->send();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountSubscriptions::route('/'),
            'view' => ViewAccountSubscription::route('/{record}'),
        ];
    }

    /**
     * BadgeColumn kleuren-map per state (Plan 09-08 acceptance_criteria).
     */
    private static function statusColor(SubscriptionStatus $state): string
    {
        return match ($state) {
            SubscriptionStatus::Pending => 'warning',
            SubscriptionStatus::Active => 'success',
            SubscriptionStatus::Paused => 'info',
            SubscriptionStatus::Canceled => 'danger',
            SubscriptionStatus::Completed => 'gray',
            SubscriptionStatus::Unknown => 'gray',
        };
    }
}
