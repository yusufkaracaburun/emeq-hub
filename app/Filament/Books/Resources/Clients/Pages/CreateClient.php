<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Clients\Pages;

use App\Filament\Books\Resources\Clients\ClientResource;
use Filament\Resources\Pages\CreateRecord;

class CreateClient extends CreateRecord
{
    protected static string $resource = ClientResource::class;
}
