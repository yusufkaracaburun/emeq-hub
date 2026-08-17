<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccessRequests\Pages;

use App\Filament\Pages\OnboardConsumer;
use App\Filament\Resources\AccessRequests\AccessRequestResource;
use App\Filament\Support\DetailViewRecord;
use App\Models\AccessRequest;
use Filament\Actions\Action;
use Filament\Schemas\Schema;

class ViewAccessRequest extends DetailViewRecord
{
    protected static string $resource = AccessRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('onboard')
                ->label('Onboard')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->visible(fn (): bool => $this->record instanceof AccessRequest && $this->record->status === 'new')
                ->url(fn (): string => OnboardConsumer::getUrl(['from_request' => $this->record->getKey()])),
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

    public function content(Schema $schema): Schema
    {
        /** @var AccessRequest $record */
        $record = $this->getRecord();

        return $this->detailSchema($schema, AccessRequestResource::statusStripSchema($record));
    }
}
