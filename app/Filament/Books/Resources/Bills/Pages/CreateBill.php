<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Bills\Pages;

use App\Filament\Books\Resources\Bills\BillResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBill extends CreateRecord
{
    protected static string $resource = BillResource::class;
}
