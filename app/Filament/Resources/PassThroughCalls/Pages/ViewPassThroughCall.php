<?php

declare(strict_types=1);

namespace App\Filament\Resources\PassThroughCalls\Pages;

use App\Filament\Resources\PassThroughCalls\PassThroughCallResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPassThroughCall extends ViewRecord
{
    protected static string $resource = PassThroughCallResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
