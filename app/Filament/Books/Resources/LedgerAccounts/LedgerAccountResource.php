<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\LedgerAccounts;

use App\Books\Models\Account;
use App\Filament\Books\Resources\LedgerAccounts\Pages\CreateLedgerAccount;
use App\Filament\Books\Resources\LedgerAccounts\Pages\EditLedgerAccount;
use App\Filament\Books\Resources\LedgerAccounts\Pages\ListLedgerAccounts;
use App\Filament\Books\Resources\LedgerAccounts\Schemas\LedgerAccountForm;
use App\Filament\Books\Resources\LedgerAccounts\Tables\LedgerAccountsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/*
 * Grootboek (chart of accounts) van de Books-module. Draait in het `books`-paneel
 * (toegang via User::canAccessPanel('books')). Model = App\Books\Models\Account
 * (de grootboekrekening), niet te verwarren met de Hub's consumer-Account.
 */
class LedgerAccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Grootboek';

    protected static ?string $modelLabel = 'grootboekrekening';

    protected static ?string $pluralModelLabel = 'grootboekrekeningen';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return LedgerAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LedgerAccountsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLedgerAccounts::route('/'),
            'create' => CreateLedgerAccount::route('/create'),
            'edit' => EditLedgerAccount::route('/{record}/edit'),
        ];
    }
}
