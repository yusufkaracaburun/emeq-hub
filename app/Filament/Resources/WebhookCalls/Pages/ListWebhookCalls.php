<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookCalls\Pages;

use App\Filament\Resources\WebhookCalls\WebhookCallResource;
use Filament\Resources\Pages\ListRecords;

class ListWebhookCalls extends ListRecords
{
    protected static string $resource = WebhookCallResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
