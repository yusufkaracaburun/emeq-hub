<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccessRequests;

use App\Filament\Resources\AccessRequests\Pages\ListAccessRequests;
use App\Filament\Resources\AccessRequests\Pages\ViewAccessRequest;
use App\Filament\Resources\AccessRequests\Schemas\AccessRequestInfolist;
use App\Filament\Resources\AccessRequests\Tables\AccessRequestsTable;
use App\Models\AccessRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Inbox voor onboarding-leads vanaf de publieke koppel-formulieren op de
 * partner-pagina's. Read-mostly:
 * staff bekijkt aanvragen en markeert ze afgehandeld; aanmaken/bewerken gebeurt
 * niet hier (de aanvraag komt van de publieke form, onboarden via de
 * OnboardConsumer-wizard).
 */
class AccessRequestResource extends Resource
{
    protected static ?string $model = AccessRequest::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $modelLabel = 'Koppel-aanvraag';

    protected static ?string $pluralModelLabel = 'Koppel-aanvragen';

    protected static string|\UnitEnum|null $navigationGroup = 'Koppelingen';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-consumers') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = AccessRequest::query()->where('status', 'new')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
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
        return parent::getEloquentQuery()->with('consumer');
    }

    public static function infolist(Schema $schema): Schema
    {
        return AccessRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccessRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccessRequests::route('/'),
            'view' => ViewAccessRequest::route('/{record}'),
        ];
    }
}
