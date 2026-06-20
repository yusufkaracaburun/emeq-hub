<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Transactions\Pages;

use App\Filament\Books\Resources\Transactions\TransactionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewTransaction extends ViewRecord
{
    protected static string $resource = TransactionResource::class;
}
