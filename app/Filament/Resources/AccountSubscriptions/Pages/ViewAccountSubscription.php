<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccountSubscriptions\Pages;

use App\Filament\Resources\AccountSubscriptions\AccountSubscriptionResource;
use App\Filament\Support\DetailViewRecord;
use App\Models\AccountSubscription;
use Filament\Schemas\Schema;

class ViewAccountSubscription extends DetailViewRecord
{
    protected static string $resource = AccountSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        // Read-only Resource — geen Edit-action.
        return [];
    }

    public function content(Schema $schema): Schema
    {
        /** @var AccountSubscription $record */
        $record = $this->getRecord();

        return $this->detailSchema($schema, AccountSubscriptionResource::statusStripSchema($record));
    }
}
