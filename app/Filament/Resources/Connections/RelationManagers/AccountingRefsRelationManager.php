<?php

declare(strict_types=1);

namespace App\Filament\Resources\Connections\RelationManagers;

use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only spiegel van Exact's referentiedata zoals de Hub die kent
 * (connection_accounting_refs): grootboek, BTW, dagboeken én lazy geleerde
 * relaties met hun stabiele code → provider-native GUID. Hiermee kan een
 * boekhouder een GUID uit een boeking-respons terugzoeken naar een naam.
 */
final class AccountingRefsRelationManager extends RelationManager
{
    protected static string $relationship = 'accountingRefs';

    protected static ?string $title = 'Boekhoud-referentiedata';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Connection
            && $ownerRecord->accountingRefs()->exists();
    }

    /**
     * @var array<string, string>
     */
    private const KIND_LABELS = [
        ConnectionAccountingRef::KIND_GL => 'Grootboek',
        ConnectionAccountingRef::KIND_VAT => 'BTW',
        ConnectionAccountingRef::KIND_JOURNAL => 'Dagboek',
        ConnectionAccountingRef::KIND_RELATION => 'Relatie',
        ConnectionAccountingRef::KIND_COST_CENTER => 'Kostenplaats',
        ConnectionAccountingRef::KIND_COST_UNIT => 'Kostendrager',
    ];

    /**
     * @var array<string, string>
     */
    private const KIND_COLORS = [
        ConnectionAccountingRef::KIND_GL => 'info',
        ConnectionAccountingRef::KIND_VAT => 'warning',
        ConnectionAccountingRef::KIND_JOURNAL => 'gray',
        ConnectionAccountingRef::KIND_RELATION => 'success',
        ConnectionAccountingRef::KIND_COST_CENTER => 'primary',
        ConnectionAccountingRef::KIND_COST_UNIT => 'primary',
    ];

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                TextColumn::make('kind')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::KIND_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => self::KIND_COLORS[$state] ?? 'gray')
                    ->sortable(),
                TextColumn::make('label')
                    ->label('Naam')
                    ->placeholder('—')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('native_id')
                    ->label('Exact-GUID')
                    ->fontFamily('mono')
                    ->copyable()
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('synced_at')
                    ->label('Gesynct')
                    ->dateTime('d-m-Y H:i')
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->defaultSort('kind')
            ->filters([
                SelectFilter::make('kind')
                    ->label('Type')
                    ->options(self::KIND_LABELS),
            ])
            ->emptyStateHeading('Nog geen referentiedata gespiegeld voor deze koppeling');
    }
}
