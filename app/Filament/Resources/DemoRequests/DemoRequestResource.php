<?php

declare(strict_types=1);

namespace App\Filament\Resources\DemoRequests;

use App\Filament\Resources\DemoRequests\Pages\ListDemoRequests;
use App\Filament\Resources\DemoRequests\Pages\ViewDemoRequest;
use App\Filament\Resources\DemoRequests\Schemas\DemoRequestInfolist;
use App\Filament\Resources\DemoRequests\Tables\DemoRequestsTable;
use App\Models\DemoRequest;
use App\Support\Filament\BadgeColor;
use App\Support\Filament\StatusStrip;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class DemoRequestResource extends Resource
{
    protected static ?string $model = DemoRequest::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $modelLabel = 'Demo-aanvraag';

    protected static ?string $pluralModelLabel = 'Demo-aanvragen';

    protected static string|\UnitEnum|null $navigationGroup = 'Koppelingen';

    protected static ?int $navigationSort = 5;

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
        $count = DemoRequest::query()->where('status', 'new')->count();

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

    public static function infolist(Schema $schema): Schema
    {
        return DemoRequestInfolist::configure($schema);
    }

    /** @return list<Section> */
    public static function statusStripSchema(DemoRequest $record): array
    {
        return StatusStrip::make([
            StatusStrip::badge('Status', $record->status, fn (?string $state): string => BadgeColor::requestStatus($state)),
            StatusStrip::fact('Bedrijf', $record->company),
            StatusStrip::fact('Voorkeursmoment', $record->preferred_slot),
            StatusStrip::moment('Ontvangen', $record->created_at),
        ]);
    }

    public static function table(Table $table): Table
    {
        return DemoRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDemoRequests::route('/'),
            'view' => ViewDemoRequest::route('/{record}'),
        ];
    }
}
