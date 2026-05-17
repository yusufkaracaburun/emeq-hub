<?php

declare(strict_types=1);

namespace App\Filament\Resources\Consumers\Pages;

use App\Filament\Resources\Consumers\ConsumerResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Plan 08-04 — Filament View-page voor ConsumerResource.
 *
 * Read-only detail-view die ConsumerInfolist rendert (D-07 hint-Section + basis-velden).
 * Edit/Delete blijven beschikbaar via getPages() en de table-row-acties.
 */
class ViewConsumer extends ViewRecord
{
    protected static string $resource = ConsumerResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
