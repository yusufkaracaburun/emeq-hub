<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Accounting\AccountingTargetRegistry;
use App\Models\Connection;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * Beheert de per-Connection boekhoud-mapping (`metadata.accounting_mapping`) die de
 * accounting-sync gebruikt om canonical waarden naar provider-referenties te vertalen
 * (tarief→VATCode, categorie→GLAccount, relatie→GUID, doc-type→dagboek). Alleen
 * zichtbaar voor providers die een AccountingTarget hebben (nu Exact).
 */
final class ManageAccountingMappingAction
{
    public static function make(): Action
    {
        return Action::make('accountingMapping')
            ->label('Boekhoud-mapping')
            ->icon(Heroicon::OutlinedTableCells)
            ->modalHeading('Boekhoud-mapping')
            ->modalDescription('Vertaalt canonical documenten naar de referenties van dit boekhoudpakket. Leeg laten = die waarde wordt niet gemapt (de sync faalt dan met een duidelijke melding).')
            ->modalSubmitActionLabel('Opslaan')
            ->visible(fn (Connection $record): bool => $record->revoked_at === null
                && app(AccountingTargetRegistry::class)->supports($record->provider->value))
            ->fillForm(fn (Connection $record): array => self::toFormState($record))
            ->schema([
                Section::make('BTW-codes (tarief → VATCode)')
                    ->columns(3)
                    ->schema([
                        TextInput::make('vat_21')->label('21%'),
                        TextInput::make('vat_9')->label('9%'),
                        TextInput::make('vat_0')->label('0%'),
                    ]),
                Section::make('Grootboekrekeningen (categorie → GLAccount-GUID)')
                    ->schema([
                        KeyValue::make('gl_accounts')
                            ->keyLabel('Categorie')
                            ->valueLabel('GLAccount-GUID')
                            ->helperText('Gebruik de sleutel "_default" als fallback-rekening voor regels zonder eigen categorie.'),
                    ]),
                Section::make('Relaties (Account external_id → crm/Account-GUID)')
                    ->schema([
                        KeyValue::make('relations')
                            ->keyLabel('external_id')
                            ->valueLabel('crm/Account-GUID'),
                    ]),
                Section::make('Dagboeken (Journal)')
                    ->columns(3)
                    ->schema([
                        TextInput::make('journal_sales')->label('Verkoop'),
                        TextInput::make('journal_purchase')->label('Inkoop'),
                        TextInput::make('journal_general')->label('Memoriaal'),
                    ]),
            ])
            ->action(function (array $data, Connection $record): void {
                $metadata = $record->metadata ?? [];
                $metadata['accounting_mapping'] = self::toMapping($data);
                $record->metadata = $metadata;
                $record->save();

                Notification::make()
                    ->title('Boekhoud-mapping opgeslagen')
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<string, mixed>
     */
    private static function toFormState(Connection $record): array
    {
        $mapping = $record->metadata['accounting_mapping'] ?? [];
        $vat = $mapping['vat_codes'] ?? [];
        $journals = $mapping['journals'] ?? [];

        return [
            'vat_21' => $vat['21'] ?? null,
            'vat_9' => $vat['9'] ?? null,
            'vat_0' => $vat['0'] ?? null,
            'gl_accounts' => $mapping['gl_accounts'] ?? [],
            'relations' => $mapping['relations'] ?? [],
            'journal_sales' => $journals['sales'] ?? null,
            'journal_purchase' => $journals['purchase'] ?? null,
            'journal_general' => $journals['general'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function toMapping(array $data): array
    {
        $notEmpty = fn (mixed $v): bool => $v !== null && $v !== '';

        return [
            'vat_codes' => array_filter([
                '21' => $data['vat_21'] ?? null,
                '9' => $data['vat_9'] ?? null,
                '0' => $data['vat_0'] ?? null,
            ], $notEmpty),
            'gl_accounts' => $data['gl_accounts'] ?? [],
            'relations' => $data['relations'] ?? [],
            'journals' => array_filter([
                'sales' => $data['journal_sales'] ?? null,
                'purchase' => $data['journal_purchase'] ?? null,
                'general' => $data['journal_general'] ?? null,
            ], $notEmpty),
        ];
    }
}
