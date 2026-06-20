<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Vendors\Pages;

use App\Filament\Books\Resources\Vendors\VendorResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVendor extends CreateRecord
{
    protected static string $resource = VendorResource::class;
}
