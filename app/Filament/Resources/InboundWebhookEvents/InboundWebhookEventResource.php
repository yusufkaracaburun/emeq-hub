<?php

declare(strict_types=1);

namespace App\Filament\Resources\InboundWebhookEvents;

use App\Filament\Resources\InboundWebhookEvents\Pages\ListInboundWebhookEvents;
use App\Filament\Resources\InboundWebhookEvents\Pages\ViewInboundWebhookEvent;
use App\Filament\Resources\InboundWebhookEvents\Schemas\InboundWebhookEventInfolist;
use App\Filament\Resources\InboundWebhookEvents\Tables\InboundWebhookEventsTable;
use App\Filament\Widgets\OperationalHealthWidget;
use App\Models\InboundWebhookEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only viewer voor de `inbound_webhook_events`-audit (Partner → Hub).
 * Metadata-only (géén payload/headers — AVG): genoeg voor incident-triage
 * (provider/topic/action/outcome/status/fanout), niet de inhoud. Geschreven door
 * `App\Webhooks\InboundWebhookRecorder`; geen Create/Edit/Delete.
 */
class InboundWebhookEventResource extends Resource
{
    protected static ?string $model = InboundWebhookEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $modelLabel = 'Inbound webhook-event';

    protected static ?string $pluralModelLabel = 'Inbound webhook-events';

    protected static string|\UnitEnum|null $navigationGroup = 'Integraties';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-webhooks') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = OperationalHealthWidget::webhookProblemCount();

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
        return InboundWebhookEventInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InboundWebhookEventsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInboundWebhookEvents::route('/'),
            'view' => ViewInboundWebhookEvent::route('/{record}'),
        ];
    }
}
