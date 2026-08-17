<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\ManualJournals\Pages;

use App\Books\Models\Transaction;
use App\Filament\Books\Resources\ManualJournals\ManualJournalResource;
use App\Filament\Support\DetailViewRecord;
use Filament\Schemas\Schema;

class ViewManualJournal extends DetailViewRecord
{
    protected static string $resource = ManualJournalResource::class;

    public function content(Schema $schema): Schema
    {
        /** @var Transaction $record */
        $record = $this->getRecord();

        return $this->detailSchema($schema, ManualJournalResource::statusStripSchema($record));
    }
}
