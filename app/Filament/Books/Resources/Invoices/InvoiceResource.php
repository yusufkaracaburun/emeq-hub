<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Invoices;

use App\Books\Models\Invoice;
use App\Filament\Books\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Books\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Books\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Books\Resources\Invoices\Schemas\InvoiceForm;
use App\Filament\Books\Resources\Invoices\Tables\InvoicesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/*
 * Verkoopfacturen (debiteuren). Concept-CRUD met regels + auto-totalen; posten
 * naar het grootboek volgt in een eigen slice.
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Facturen';

    protected static ?string $modelLabel = 'factuur';

    protected static ?string $pluralModelLabel = 'facturen';

    protected static ?string $recordTitleAttribute = 'invoice_number';

    public static function form(Schema $schema): Schema
    {
        return InvoiceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InvoicesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'create' => CreateInvoice::route('/create'),
            'edit' => EditInvoice::route('/{record}/edit'),
        ];
    }
}
