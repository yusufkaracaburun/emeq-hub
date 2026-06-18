<?php

declare(strict_types=1);

namespace App\Filament\Resources\InboundWebhookEvents\Pages;

use App\Filament\Resources\InboundWebhookEvents\InboundWebhookEventResource;
use Filament\Resources\Pages\ViewRecord;

class ViewInboundWebhookEvent extends ViewRecord
{
    protected static string $resource = InboundWebhookEventResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
