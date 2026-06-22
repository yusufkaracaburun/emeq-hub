<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Vendors;

use App\Books\Models\Vendor;
use App\Filament\Books\Concerns\GatedToBoekhouding;
use App\Filament\Books\Resources\Vendors\Pages\CreateVendor;
use App\Filament\Books\Resources\Vendors\Pages\EditVendor;
use App\Filament\Books\Resources\Vendors\Pages\ListVendors;
use App\Filament\Books\Resources\Vendors\Schemas\VendorForm;
use App\Filament\Books\Resources\Vendors\Tables\VendorsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/*
 * Leveranciers (crediteuren) van emeq's eigen boekhouding. Géén Hub-equivalent.
 */
class VendorResource extends Resource
{
    use GatedToBoekhouding;

    protected static ?string $model = Vendor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|\UnitEnum|null $navigationGroup = 'Facturatie';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Leveranciers';

    protected static ?string $modelLabel = 'leverancier';

    protected static ?string $pluralModelLabel = 'leveranciers';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return VendorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VendorsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendors::route('/'),
            'create' => CreateVendor::route('/create'),
            'edit' => EditVendor::route('/{record}/edit'),
        ];
    }
}
