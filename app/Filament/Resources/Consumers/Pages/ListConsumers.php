<?php

namespace App\Filament\Resources\Consumers\Pages;

use App\Filament\Resources\Consumers\ConsumerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListConsumers extends ListRecords
{
    protected static string $resource = ConsumerResource::class;

    /** Live-state na Issue-PAT: ['token' => string, 'name' => string]. Wordt geset in ConsumerResource::issuePatAction(). */
    public ?array $lastIssuedPat = null;

    protected string $view = 'filament.resources.consumers.pages.list-consumers';

    public function getSubheading(): ?string
    {
        return 'Een Consumer is een app die de Hub gebruikt — één van Emeq\'s eigen SaaS-apps (Naschool, …) of een betalende derde. '
            .'Maak één Consumer per app. Per Consumer geef je via Issue PAT een Bearer-token uit waarmee die app `/v1/*`-endpoints kan aanroepen.';
    }

    public function dismissIssuedPat(): void
    {
        $this->lastIssuedPat = null;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
