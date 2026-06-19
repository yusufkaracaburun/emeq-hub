<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccessRequests\Pages;

use App\Filament\Resources\AccessRequests\AccessRequestResource;
use App\Models\AccessRequest;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewAccessRequest extends ViewRecord
{
    protected static string $resource = AccessRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('handle')
                ->label('Markeer afgehandeld')
                ->icon('heroicon-o-check')
                ->color('success')
                ->visible(fn (): bool => $this->record instanceof AccessRequest && $this->record->status === 'new')
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update(['status' => 'handled']);
                }),
        ];
    }
}
