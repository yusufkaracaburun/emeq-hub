<?php

namespace App\Filament\Resources\Consumers\Pages;

use App\Filament\Pages\OnboardConsumer;
use App\Filament\Resources\Consumers\ConsumerResource;
use App\Filament\Support\InfoModalAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListConsumers extends ListRecords
{
    protected static string $resource = ConsumerResource::class;

    protected string $view = 'filament.resources.consumers.pages.list-consumers';

    protected function getHeaderActions(): array
    {
        return [
            InfoModalAction::make(
                'Wat is een Consumer?',
                'Een Consumer is een app die de Hub gebruikt — één van Emeq\'s eigen SaaS-apps (Naschool, …) of een betalende derde. '
                .'Maak één Consumer per app. Per Consumer geef je via Issue PAT een Bearer-token uit waarmee die app `/v1/*`-endpoints kan aanroepen.',
            ),
            Action::make('onboard')
                ->label('Onboarden')
                ->icon(Heroicon::OutlinedSparkles)
                ->url(OnboardConsumer::getUrl())
                ->visible(fn (): bool => OnboardConsumer::canAccess()),
        ];
    }
}
