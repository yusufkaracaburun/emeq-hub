<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Accounting\AccountingTargetRegistry;
use App\Models\Connection;
use App\Services\Exact\ExactReferenceData;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * Beheert de per-Connection boekhoud-mapping (`metadata.accounting_mapping`) die de
 * accounting-sync gebruikt om canonical waarden naar provider-referenties te vertalen
 * (tarief→VATCode, categorie→GLAccount, relatie→GUID, doc-type→dagboek). Alleen
 * zichtbaar voor providers die een AccountingTarget hebben (nu Exact).
 *
 * De keuzevelden worden gevuld met live Exact-referentiedata (VATCodes, GLAccounts,
 * crm/Accounts, Journals) via ExactReferenceData; lukt die fetch niet (pending
 * Connection, geen division, Exact onbereikbaar) dan valt elk veld terug op vrije
 * tekst-invoer. De opslag-vorm in metadata blijft identiek aan wat de resolver leest.
 */
final class ManageAccountingMappingAction
{
    public static function make(): Action
    {
        return Action::make('accountingMapping')
            ->label('Boekhoud-mapping')
            ->icon(Heroicon::OutlinedTableCells)
            ->modalHeading('Boekhoud-mapping')
            ->modalDescription('Vertaalt canonical documenten naar de referenties van dit boekhoudpakket. Keuzelijsten worden met live data van de gekoppelde administratie gevuld; lukt dat niet, vul dan handmatig in. Leeg laten = die waarde wordt niet gemapt (de sync faalt dan met een duidelijke melding).')
            ->modalSubmitActionLabel('Opslaan')
            ->visible(fn (Connection $record): bool => $record->revoked_at === null
                && app(AccountingTargetRegistry::class)->supports($record->provider->value))
            ->fillForm(fn (Connection $record): array => self::toFormState($record))
            ->schema(fn (Connection $record): array => self::schemaFor($record))
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
     * @return list<Section>
     */
    private static function schemaFor(Connection $record): array
    {
        $reference = new ExactReferenceData($record);
        $vatCodes = $reference->vatCodes();
        $glAccounts = $reference->glAccounts();
        $relations = $reference->relations();
        $journals = $reference->journals();

        return [
            Section::make('BTW-codes (tarief → VATCode)')
                ->columns(3)
                ->schema([
                    self::optionField('vat_21', '21%', $vatCodes),
                    self::optionField('vat_9', '9%', $vatCodes),
                    self::optionField('vat_0', '0%', $vatCodes),
                ]),
            Section::make('Grootboekrekeningen (categorie → GLAccount)')
                ->schema([
                    Repeater::make('gl_accounts')
                        ->label('')
                        ->addActionLabel('Categorie toevoegen')
                        ->columns(2)
                        ->schema([
                            TextInput::make('category')
                                ->label('Categorie')
                                ->helperText('Gebruik "_default" als fallback-rekening voor regels zonder eigen categorie.'),
                            self::optionField('value', 'GLAccount', $glAccounts),
                        ]),
                ]),
            Section::make('Relaties (Account external_id → crm/Account)')
                ->schema([
                    Repeater::make('relations')
                        ->label('')
                        ->addActionLabel('Relatie toevoegen')
                        ->columns(2)
                        ->schema([
                            TextInput::make('external_id')->label('external_id'),
                            self::optionField('value', 'crm/Account', $relations),
                        ]),
                ]),
            Section::make('Dagboeken (Journal)')
                ->columns(3)
                ->schema([
                    self::optionField('journal_sales', 'Verkoop', $journals),
                    self::optionField('journal_purchase', 'Inkoop', $journals),
                    self::optionField('journal_general', 'Memoriaal', $journals),
                ]),
        ];
    }

    /**
     * Keuzelijst gevuld met live Exact-data; lege referentiedata valt terug op vrije invoer.
     *
     * @param  array<string, string>  $options
     */
    private static function optionField(string $name, string $label, array $options): Select|TextInput
    {
        if ($options === []) {
            return TextInput::make($name)->label($label);
        }

        return Select::make($name)
            ->label($label)
            ->options($options)
            ->searchable()
            ->native(false);
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
            'gl_accounts' => self::toRows($mapping['gl_accounts'] ?? [], 'category'),
            'relations' => self::toRows($mapping['relations'] ?? [], 'external_id'),
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
        return [
            'vat_codes' => self::scalarMap([
                '21' => $data['vat_21'] ?? null,
                '9' => $data['vat_9'] ?? null,
                '0' => $data['vat_0'] ?? null,
            ]),
            'gl_accounts' => self::fromRows($data['gl_accounts'] ?? [], 'category'),
            'relations' => self::fromRows($data['relations'] ?? [], 'external_id'),
            'journals' => self::scalarMap([
                'sales' => $data['journal_sales'] ?? null,
                'purchase' => $data['journal_purchase'] ?? null,
                'general' => $data['journal_general'] ?? null,
            ]),
        ];
    }

    /**
     * Vaste-sleutel mapping met string-waarden; lege waarden vallen weg. Select-velden
     * leveren hun (numerieke) optie-key als int — cast naar string zodat de opslag-vorm
     * gelijk is aan handinvoer en Exact een string-code ontvangt.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private static function scalarMap(array $values): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $out[$key] = (string) $value;
        }

        return $out;
    }

    /**
     * Assoc-mapping ([key => value]) → Repeater-rijen ([{keyName, value}]).
     *
     * @param  array<string, mixed>  $assoc
     * @return list<array<string, mixed>>
     */
    private static function toRows(array $assoc, string $keyName): array
    {
        $rows = [];

        foreach ($assoc as $key => $value) {
            $rows[] = [$keyName => (string) $key, 'value' => $value];
        }

        return $rows;
    }

    /**
     * Repeater-rijen → assoc-mapping; lege key/value-rijen vallen weg.
     *
     * @param  array<int|string, mixed>  $rows
     * @return array<string, mixed>
     */
    private static function fromRows(array $rows, string $keyName): array
    {
        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = trim((string) ($row[$keyName] ?? ''));
            $value = $row['value'] ?? null;

            if ($key === '' || $value === null || $value === '') {
                continue;
            }

            $out[$key] = (string) $value;
        }

        return $out;
    }
}
