<?php

declare(strict_types=1);

namespace App\Filament\Resources\DemoRequests\Pages;

use App\Filament\Resources\DemoRequests\DemoRequestResource;
use App\Models\DemoRequest;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewDemoRequest extends ViewRecord
{
    protected static string $resource = DemoRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('handle')
                ->label('Markeer afgehandeld')
                ->icon('heroicon-o-check')
                ->color('success')
                ->visible(fn (): bool => $this->record instanceof DemoRequest && $this->record->status === 'new')
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update(['status' => 'handled']);
                }),
        ];
    }
}
