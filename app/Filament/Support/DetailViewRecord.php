<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

abstract class DetailViewRecord extends ViewRecord
{
    /**
     * @param  list<Section>  $strip
     * @param  list<Tab>  $tabs
     */
    protected function detailSchema(Schema $schema, array $strip, array $tabs = []): Schema
    {
        $tabs = [...$tabs, ...$this->relationManagerTabs()];

        if ($tabs === []) {
            return $schema->components([
                ...$strip,
                $this->getInfolistContentComponent(),
            ]);
        }

        return $schema->components([
            ...$strip,

            Tabs::make()
                ->contained(false)
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make('Overzicht')
                        ->icon(Heroicon::OutlinedIdentification)
                        ->schema([$this->getInfolistContentComponent()]),

                    ...$tabs,
                ]),
        ]);
    }

    /** @return list<Tab> */
    protected function relationManagerTabs(): array
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
