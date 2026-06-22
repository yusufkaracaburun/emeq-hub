<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\ManualJournals\Pages;

use App\Filament\Books\Resources\ManualJournals\ManualJournalResource;
use Filament\Resources\Pages\ViewRecord;

class ViewManualJournal extends ViewRecord
{
    protected static string $resource = ManualJournalResource::class;
}
