<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Bills;

use App\Books\Models\Bill;
use App\Filament\Books\BoekhoudingCluster;
use App\Filament\Books\Concerns\GatedToBoekhouding;
use App\Filament\Books\Resources\Bills\Pages\CreateBill;
use App\Filament\Books\Resources\Bills\Pages\EditBill;
use App\Filament\Books\Resources\Bills\Pages\ListBills;
use App\Filament\Books\Resources\Bills\Schemas\BillForm;
use App\Filament\Books\Resources\Bills\Tables\BillsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/*
 * Inkoopfacturen (crediteuren). Concept-CRUD met regels + auto-totalen; boeken
 * naar het grootboek via BillPoster. Spiegel van InvoiceResource.
 */
class BillResource extends Resource
{
    use GatedToBoekhouding;

    protected static ?string $cluster = BoekhoudingCluster::class;

    protected static ?string $model = Bill::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowDown;

    protected static string|\UnitEnum|null $navigationGroup = 'Inkoop';

    protected static ?string $navigationLabel = 'Inkoopfacturen';

    protected static ?string $modelLabel = 'inkoopfactuur';

    protected static ?string $pluralModelLabel = 'inkoopfacturen';

    protected static ?string $recordTitleAttribute = 'bill_number';

    public static function form(Schema $schema): Schema
    {
        return BillForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BillsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBills::route('/'),
            'create' => CreateBill::route('/create'),
            'edit' => EditBill::route('/{record}/edit'),
        ];
    }
}
