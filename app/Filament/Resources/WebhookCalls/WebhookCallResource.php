<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookCalls;

use App\Filament\Resources\WebhookCalls\Pages\ListWebhookCalls;
use App\Filament\Resources\WebhookCalls\Pages\ViewWebhookCall;
use App\Filament\Resources\WebhookCalls\Schemas\WebhookCallInfolist;
use App\Filament\Resources\WebhookCalls\Tables\WebhookCallsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Spatie\WebhookClient\Models\WebhookCall;

/**
 * Plan 09-07 — read-only viewer voor Spatie webhook_calls + 09-01 audit-kolommen.
 *
 * Geen Create/Edit/Delete-pages. Rijen worden geschreven door Spatie's webhook-server
 * en Hub's eigen webhook-dispatchers (Mollie/Snelstart/Cashier).
 */
class WebhookCallResource extends Resource
{
    protected static ?string $model = WebhookCall::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $modelLabel = 'Webhook call';

    protected static ?string $pluralModelLabel = 'Webhook calls';

    public static function infolist(Schema $schema): Schema
    {
        return WebhookCallInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WebhookCallsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhookCalls::route('/'),
            'view' => ViewWebhookCall::route('/{record}'),
        ];
    }
}
