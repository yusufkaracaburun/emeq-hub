<?php

declare(strict_types=1);

namespace App\Filament\Resources\PassThroughCalls\Pages;

use App\Filament\Resources\PassThroughCalls\PassThroughCallResource;
use App\Filament\Support\HasDetailLayout;
use App\Models\PassThroughCall;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewPassThroughCall extends ViewRecord
{
    use HasDetailLayout;

    protected static string $resource = PassThroughCallResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function content(Schema $schema): Schema
    {
        /** @var PassThroughCall $record */
        $record = $this->getRecord();

        return $this->detailSchema($schema, PassThroughCallResource::statusStripSchema($record));
    }
}
