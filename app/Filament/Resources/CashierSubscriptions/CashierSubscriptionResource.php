<?php

namespace App\Filament\Resources\CashierSubscriptions;

use App\Filament\Resources\CashierSubscriptions\Pages\ListCashierSubscriptions;
use App\Filament\Resources\CashierSubscriptions\Pages\ViewCashierSubscription;
use App\Filament\Resources\CashierSubscriptions\Schemas\CashierSubscriptionInfolist;
use App\Filament\Resources\CashierSubscriptions\Tables\CashierSubscriptionsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Laravel\Cashier\Subscription;

class CashierSubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::CreditCard;

    protected static ?string $navigationLabel = 'Cashier subscriptions';

    protected static ?string $modelLabel = 'Cashier subscription';

    protected static ?string $pluralModelLabel = 'Cashier subscriptions';

    protected static string|\UnitEnum|null $navigationGroup = 'Abonnementen';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-billing') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function infolist(Schema $schema): Schema
    {
        return CashierSubscriptionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CashierSubscriptionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashierSubscriptions::route('/'),
            'view' => ViewCashierSubscription::route('/{record}'),
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
