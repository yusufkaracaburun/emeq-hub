<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\ManualJournals;

use App\Books\Enums\TransactionType;
use App\Books\Models\Transaction;
use App\Filament\Books\Concerns\GatedToBoekhouding;
use App\Filament\Books\Resources\ManualJournals\Pages\CreateManualJournal;
use App\Filament\Books\Resources\ManualJournals\Pages\ListManualJournals;
use App\Filament\Books\Resources\ManualJournals\Pages\ViewManualJournal;
use App\Filament\Books\Resources\ManualJournals\Schemas\ManualJournalForm;
use App\Filament\Books\Resources\ManualJournals\Schemas\ManualJournalInfolist;
use App\Filament\Books\Resources\ManualJournals\Tables\ManualJournalsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/*
 * Memoriaal — handmatige debet/credit-journaalposten. Eigen view op het
 * Transaction-model, gescoped op type=journal. Immutable net als
 * TransactionResource: aanmaken + bekijken, géén edit/delete (corrigeren =
 * tegenboeking). Toont ook de auto-geposte factuur-/inkoop-carriers (read-only).
 */
class ManualJournalResource extends Resource
{
    use GatedToBoekhouding;

    protected static ?string $model = Transaction::class;

    protected static ?string $slug = 'memoriaal';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|\UnitEnum|null $navigationGroup = 'Boekhouding';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Memoriaal';

    protected static ?string $modelLabel = 'memoriaalboeking';

    protected static ?string $pluralModelLabel = 'memoriaalboekingen';

    protected static ?string $recordTitleAttribute = 'description';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', TransactionType::Journal);
    }

    public static function form(Schema $schema): Schema
    {
        return ManualJournalForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ManualJournalInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ManualJournalsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListManualJournals::route('/'),
            'create' => CreateManualJournal::route('/create'),
            'view' => ViewManualJournal::route('/{record}'),
        ];
    }
}
