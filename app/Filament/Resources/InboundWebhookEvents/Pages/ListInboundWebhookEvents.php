<?php

declare(strict_types=1);

namespace App\Filament\Resources\InboundWebhookEvents\Pages;

use App\Filament\Resources\InboundWebhookEvents\InboundWebhookEventResource;
use App\Filament\Support\InfoModalAction;
use Filament\Resources\Pages\ListRecords;

class ListInboundWebhookEvents extends ListRecords
{
    protected static string $resource = InboundWebhookEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InfoModalAction::make(
                'Over inbound webhooks',
                'Audit van inkomende partner-webhooks (Exact / Snelstart / Mollie / Cashier). '
                .'Metadata-only — géén payload of headers (AVG). Filter op provider / outcome / consumer / datum; '
                .'gebruik outcome=invalid_signature of misconfigured om problemen te isoleren.',
            ),
        ];
    }
}
