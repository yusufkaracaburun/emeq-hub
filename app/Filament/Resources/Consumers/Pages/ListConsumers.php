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
            // Plan 08-02: launch-pad voor de Filament OnboardConsumer-wizard. Visible-gate
            // hergebruikt OnboardConsumer::canAccess() (manage-consumers) — staff zonder
            // permission ziet de actie niet en kan de Page-route niet bereiken (D-04 RBAC).
            // Geen kale CreateAction: die levert een Consumer zonder PAT en zonder
            // app_url, en dus een die niets kan koppelen. Onboarden is de enige weg in.
            Action::make('onboard')
                ->label('Onboarden')
                ->icon(Heroicon::OutlinedSparkles)
                ->url(OnboardConsumer::getUrl())
                ->visible(fn (): bool => OnboardConsumer::canAccess()),
        ];
    }
}
