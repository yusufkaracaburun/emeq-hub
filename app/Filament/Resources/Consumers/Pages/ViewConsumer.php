<?php

declare(strict_types=1);

namespace App\Filament\Resources\Consumers\Pages;

use App\Filament\Resources\Consumers\ConsumerResource;
use App\Filament\Support\DetailViewRecord;
use App\Filament\Support\InfoModalAction;
use App\Models\Consumer;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;

class ViewConsumer extends DetailViewRecord
{
    protected static string $resource = ConsumerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InfoModalAction::make(
                'Wat is een Consumer?',
                'Eén SaaS-app die de Hub gebruikt (Naschool, Planny, externe app). Authenticeert met een Bearer-PAT. Een Consumer heeft Accounts (zijn klanten) en die Accounts hebben Connections (partner-koppelingen).',
            ),
            EditAction::make(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        /** @var Consumer $record */
        $record = $this->getRecord();

        return $this->detailSchema($schema, ConsumerResource::statusStripSchema($record));
    }
}
