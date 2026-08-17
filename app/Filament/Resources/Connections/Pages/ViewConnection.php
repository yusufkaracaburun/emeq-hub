<?php

namespace App\Filament\Resources\Connections\Pages;

use App\Enums\Provider;
use App\Filament\Actions\StartOAuthFlowAction;
use App\Filament\Resources\Connections\ConnectionResource;
use App\Filament\Support\DetailViewRecord;
use App\Models\Connection;
use Filament\Actions\ActionGroup;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ViewConnection extends DetailViewRecord
{
    protected static string $resource = ConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            StartOAuthFlowAction::forConnection(),
            ActionGroup::make([
                ConnectionResource::revokeAction(),
            ]),
        ];
    }

    public function content(Schema $schema): Schema
    {
        /** @var Connection $record */
        $record = $this->getRecord();

        return $this->detailSchema(
            $schema,
            ConnectionResource::statusStripSchema($record),
            [
                Tab::make('Toegang')
                    ->icon(Heroicon::OutlinedKey)
                    ->schema(ConnectionResource::accessSchema($record)),

                Tab::make('Boekhoud-mapping')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->schema(ConnectionResource::accountingMappingSchema($record)),

                Tab::make('Webhooks')
                    ->icon(Heroicon::OutlinedBell)
                    ->visible($record->provider === Provider::Exact)
                    ->schema(ConnectionResource::webhookSubscriptionsSchema($record)),
            ],
        );
    }
}
