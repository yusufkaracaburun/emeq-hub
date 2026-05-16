<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccountSubscriptions\Pages;

use App\Filament\Resources\AccountSubscriptions\AccountSubscriptionResource;
use Filament\Resources\Pages\ListRecords;

class ListAccountSubscriptions extends ListRecords
{
    protected static string $resource = AccountSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        // Read-only Resource — geen Create-action. Subscription-create gebeurt
        // via POST /v1/account-subscriptions (Phase 7-04).
        return [];
    }
}
