<?php

declare(strict_types=1);

namespace App\Filament\Resources\PassThroughCalls\Pages;

use App\Filament\Resources\PassThroughCalls\PassThroughCallResource;
use App\Filament\Support\InfoModalAction;
use Filament\Resources\Pages\ListRecords;

class ListPassThroughCalls extends ListRecords
{
    protected static string $resource = PassThroughCallResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InfoModalAction::make(
                'Over pass-through-calls',
                'Immutable audit-log van alle pass-through-calls (Consumer → Hub → Partner → terug). '
                .'Eén rij per request. Filter op direction / provider / status-klasse / consumer / datum — '
                .'gebruik 5xx · server error om partner-failures te isoleren. Klik door voor fingerprints en upstream-error.',
            ),
        ];
    }
}
