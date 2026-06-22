<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Transactions;

use App\Books\Models\Transaction;
use App\Filament\Books\BoekhoudingCluster;
use App\Filament\Books\Concerns\GatedToBoekhouding;
use App\Filament\Books\Resources\Transactions\Pages\CreateTransaction;
use App\Filament\Books\Resources\Transactions\Pages\ListTransactions;
use App\Filament\Books\Resources\Transactions\Pages\ViewTransaction;
use App\Filament\Books\Resources\Transactions\Schemas\TransactionForm;
use App\Filament\Books\Resources\Transactions\Schemas\TransactionInfolist;
use App\Filament\Books\Resources\Transactions\Tables\TransactionsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/*
 * Boekingen (deposit/withdrawal) van de Books-module. Een Transaction post bij
 * create via de observer een gebalanceerde grootboek-boeking → daarom immutable:
 * alleen aanmaken + bekijken, géén edit/delete (corrigeren = tegenboeking). De
 * journal-type (memoriaal) komt in een latere fase met een eigen regel-UI.
 */
class TransactionResource extends Resource
{
    use GatedToBoekhouding;

    protected static ?string $cluster = BoekhoudingCluster::class;

    protected static ?string $model = Transaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|\UnitEnum|null $navigationGroup = 'Boekhouding';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Transacties';

    protected static ?string $modelLabel = 'transactie';

    protected static ?string $pluralModelLabel = 'transacties';

    protected static ?string $recordTitleAttribute = 'description';

    public static function form(Schema $schema): Schema
    {
        return TransactionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TransactionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TransactionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransactions::route('/'),
            'create' => CreateTransaction::route('/create'),
            'view' => ViewTransaction::route('/{record}'),
        ];
    }
}
