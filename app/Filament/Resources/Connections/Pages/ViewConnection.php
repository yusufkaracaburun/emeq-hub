<?php

namespace App\Filament\Resources\Connections\Pages;

use App\Filament\Actions\ManageAccountingMappingAction;
use App\Filament\Actions\StartOAuthFlowAction;
use App\Filament\Resources\Connections\ConnectionResource;
use App\Models\Connection;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ViewConnection extends ViewRecord
{
    protected static string $resource = ConnectionResource::class;

    protected function getHeaderActions(): array
    {
        // Dezelfde acties als de lijst-rij, nu ook vanaf de detailpagina bereikbaar.
        return [
            StartOAuthFlowAction::forConnection(),
            ManageAccountingMappingAction::make(),
            ConnectionResource::refreshTokenAction(),
            ConnectionResource::revokeAction(),
        ];
    }

    /**
     * Eén tabbalk voor de hele koppeling. Filament bouwt zelf alleen tabs uit
     * relation managers, dus stellen we de balk hier samen: het infolist-overzicht
     * en de twee schema-tabs (toegang, mapping) staan naast de relation managers
     * in plaats van erboven.
     */
    public function content(Schema $schema): Schema
    {
        /** @var Connection $record */
        $record = $this->getRecord();

        return $schema->components([
            Tabs::make()
                ->contained(false)
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make('Overzicht')
                        ->icon(Heroicon::OutlinedIdentification)
                        ->schema([$this->getInfolistContentComponent()]),

                    // Het page-schema draagt zelf geen record; deze twee tabs
                    // lezen de Connection, dus krijgen ze 'm expliciet mee.
                    Tab::make('Toegang')
                        ->icon(Heroicon::OutlinedKey)
                        ->schema(ConnectionResource::accessSchema($record)),

                    Tab::make('Boekhoud-mapping')
                        ->icon(Heroicon::OutlinedArrowsRightLeft)
                        ->schema(ConnectionResource::accountingMappingSchema($record)),

                    ...$this->getRelationManagerTabs(),
                ]),
        ]);
    }

    /**
     * @return list<Tab>
     */
    private function getRelationManagerTabs(): array
    {
        $record = $this->getRecord();

        return collect($this->getCachedRelationManagers())
            ->map(fn (string $manager): Tab => $manager::getTabComponent($record, static::class)
                ->schema([
                    Livewire::make($manager, [
                        'ownerRecord' => $record,
                        'pageClass' => static::class,
                        ...$manager::getDefaultProperties(),
                    ])->key($manager),
                ]))
            ->values()
            ->all();
    }
}
