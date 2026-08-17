<?php

declare(strict_types=1);

namespace App\Filament\Resources\InboundWebhookEvents\Pages;

use App\Filament\Resources\InboundWebhookEvents\InboundWebhookEventResource;
use App\Filament\Support\HasDetailLayout;
use App\Models\InboundWebhookEvent;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewInboundWebhookEvent extends ViewRecord
{
    use HasDetailLayout;

    protected static string $resource = InboundWebhookEventResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function content(Schema $schema): Schema
    {
        /** @var InboundWebhookEvent $record */
        $record = $this->getRecord();

        return $this->detailSchema($schema, InboundWebhookEventResource::statusStripSchema($record));
    }
}
