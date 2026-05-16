<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookCalls\Pages;

use App\Filament\Resources\WebhookCalls\WebhookCallResource;
use Filament\Resources\Pages\ListRecords;

class ListWebhookCalls extends ListRecords
{
    protected static string $resource = WebhookCallResource::class;

    public function getSubheading(): ?string
    {
        return 'Audit-log van alle inkomende en uitgaande webhook-calls. '
            .'Inkomend = partner (Mollie, Snelstart) → Hub; uitgaand = Hub → Consumer-callback-URL. '
            .'Filter op direction / provider / signature-status / consumer / datum. Klik door voor de raw payload.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
