<?php

declare(strict_types=1);

namespace App\Filament\Resources\PassThroughCalls;

use App\Filament\Resources\PassThroughCalls\Pages\ListPassThroughCalls;
use App\Filament\Resources\PassThroughCalls\Pages\ViewPassThroughCall;
use App\Filament\Resources\PassThroughCalls\Schemas\PassThroughCallInfolist;
use App\Filament\Resources\PassThroughCalls\Tables\PassThroughCallsTable;
use App\Filament\Widgets\OperationalHealthWidget;
use App\Models\PassThroughCall;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only viewer voor de immutable `pass_through_calls`-audit (Consumer → Hub →
 * Partner → terug). Geen Create/Edit/Delete: rijen worden alleen door
 * PassThroughController via create() geschreven ($timestamps = false).
 *
 * Voorzien in ADR pass-through-calls-table.md §Consequenties ("5e resource").
 */
class PassThroughCallResource extends Resource
{
    protected static ?string $model = PassThroughCall::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $modelLabel = 'Pass-through call';

    protected static ?string $pluralModelLabel = 'Pass-through calls';

    protected static string|\UnitEnum|null $navigationGroup = 'Koppelingen';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-pass-through-calls') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = OperationalHealthWidget::failedPassThroughCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['consumer', 'account', 'connection']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PassThroughCallInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PassThroughCallsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPassThroughCalls::route('/'),
            'view' => ViewPassThroughCall::route('/{record}'),
        ];
    }
}
