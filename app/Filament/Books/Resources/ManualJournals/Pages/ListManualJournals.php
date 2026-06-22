<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\ManualJournals\Pages;

use App\Filament\Books\Resources\ManualJournals\ManualJournalResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListManualJournals extends ListRecords
{
    protected static string $resource = ManualJournalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Memoriaal boeken'),
        ];
    }
}
