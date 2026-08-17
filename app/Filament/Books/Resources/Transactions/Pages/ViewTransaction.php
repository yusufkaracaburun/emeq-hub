<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Transactions\Pages;

use App\Books\Models\Transaction;
use App\Filament\Books\Resources\Transactions\TransactionResource;
use App\Filament\Support\DetailViewRecord;
use Filament\Schemas\Schema;

class ViewTransaction extends DetailViewRecord
{
    protected static string $resource = TransactionResource::class;

    public function content(Schema $schema): Schema
    {
        /** @var Transaction $record */
        $record = $this->getRecord();

        return $this->detailSchema($schema, TransactionResource::statusStripSchema($record));
    }
}
