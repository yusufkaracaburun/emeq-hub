<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\RecurringInvoices;

use App\Books\Models\RecurringInvoice;
use App\Filament\Books\BoekhoudingCluster;
use App\Filament\Books\Concerns\GatedToBoekhouding;
use App\Filament\Books\Resources\RecurringInvoices\Pages\CreateRecurringInvoice;
use App\Filament\Books\Resources\RecurringInvoices\Pages\EditRecurringInvoice;
use App\Filament\Books\Resources\RecurringInvoices\Pages\ListRecurringInvoices;
use App\Filament\Books\Resources\RecurringInvoices\Schemas\RecurringInvoiceForm;
use App\Filament\Books\Resources\RecurringInvoices\Tables\RecurringInvoicesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/*
 * Terugkerende verkoopfacturen (templates + cadans). Editable — een template is
 * geen geboekte rij. De RecurringInvoiceGenerator post er periodiek concept-
 * facturen uit; die verschijnen in de gewone Facturen-lijst.
 */
class RecurringInvoiceResource extends Resource
{
    use GatedToBoekhouding;

    protected static ?string $cluster = BoekhoudingCluster::class;

    protected static ?string $model = RecurringInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|\UnitEnum|null $navigationGroup = 'Verkoop';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Terugkerend';

    protected static ?string $modelLabel = 'terugkerende factuur';

    protected static ?string $pluralModelLabel = 'terugkerende facturen';

    public static function form(Schema $schema): Schema
    {
        return RecurringInvoiceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecurringInvoicesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecurringInvoices::route('/'),
            'create' => CreateRecurringInvoice::route('/create'),
            'edit' => EditRecurringInvoice::route('/{record}/edit'),
        ];
    }
}
