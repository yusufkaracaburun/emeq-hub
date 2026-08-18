<?php

declare(strict_types=1);

namespace App\Filament\Resources\PassThroughCalls;

use App\Filament\Resources\PassThroughCalls\Pages\ListPassThroughCalls;
use App\Filament\Resources\PassThroughCalls\Pages\ViewPassThroughCall;
use App\Filament\Resources\PassThroughCalls\Schemas\PassThroughCallInfolist;
use App\Filament\Resources\PassThroughCalls\Tables\PassThroughCallsTable;
use App\Filament\Widgets\OperationalHealthWidget;
use App\Models\PassThroughCall;
use App\Support\Filament\BadgeColor;
use App\Support\Filament\StatusStrip;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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

    /** @return list<Section> */
    public static function statusStripSchema(PassThroughCall $record): array
    {
        return StatusStrip::make([
            StatusStrip::badge('HTTP-status', (string) $record->status, fn (?string $state): string => BadgeColor::httpStatus($state)),
            StatusStrip::fact('Endpoint', $record->method.' '.$record->path, copyable: true),
            StatusStrip::fact('Duur', $record->duration_ms.' ms'),
            StatusStrip::moment('Aangemaakt', $record->created_at),
        ]);
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
