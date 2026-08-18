<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Clients;

use App\Books\Models\Client;
use App\Filament\Books\Concerns\GatedToBoekhouding;
use App\Filament\Books\Resources\Clients\Pages\CreateClient;
use App\Filament\Books\Resources\Clients\Pages\EditClient;
use App\Filament\Books\Resources\Clients\Pages\ListClients;
use App\Filament\Books\Resources\Clients\Schemas\ClientForm;
use App\Filament\Books\Resources\Clients\Tables\ClientsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ClientResource extends Resource
{
    use GatedToBoekhouding;

    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|\UnitEnum|null $navigationGroup = 'Boekhouding';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Klanten';

    protected static ?string $modelLabel = 'klant';

    protected static ?string $pluralModelLabel = 'klanten';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ClientForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClientsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }
}
