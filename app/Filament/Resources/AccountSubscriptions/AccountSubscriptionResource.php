<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccountSubscriptions;

use App\Billing\Account\SubscriptionStatus;
use App\Filament\Resources\AccountSubscriptions\Pages\ListAccountSubscriptions;
use App\Filament\Resources\AccountSubscriptions\Pages\ViewAccountSubscription;
use App\Models\AccountSubscription;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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
            ])
            ->toolbarActions([]);
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
