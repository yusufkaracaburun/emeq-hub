<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccountSubscriptions\Pages;

use App\Filament\Resources\AccountSubscriptions\AccountSubscriptionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAccountSubscription extends ViewRecord
{
    protected static string $resource = AccountSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        // Read-only Resource — geen Edit-action.
        return [];
    }
}
