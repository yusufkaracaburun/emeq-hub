<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Accounting\AccountingTargetRegistry;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Services\Exact\ExactReferenceData;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Override-pad voor de per-Connection boekhoud-mapping (`metadata.accounting_mapping`):
 * tarief→VATCode-Code, categorie→GL-Code, doc-type→dagboek-Code. Normaal hoeft niemand
 * hier iets te doen — de Hub synct + auto-derivet bij connect; dit verfijnt enkel. Alleen
 * zichtbaar voor providers met een AccountingTarget (nu Exact).
 *
 * De mapping draagt enkel stabiele Codes; GL-Code → native GUID resolved de sync lokaal
 * tegen de mirror. Relaties staan níét in de mapping — ze worden lazy resolve-or-learned
 * uit de party-data. GL-keuzes komen uit de mirror; VAT/dagboek uit live ExactReferenceData,
 * met terugval op vrije tekst-invoer.
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
        $glAccounts = self::glOptionsFromMirror($record);
        $journals = $reference->journals();
        $costCenters = self::refOptionsFromMirror($record, ConnectionAccountingRef::KIND_COST_CENTER);
        $costUnits = self::refOptionsFromMirror($record, ConnectionAccountingRef::KIND_COST_UNIT);

        $sections = [
            Section::make('BTW-codes (tarief → VATCode)')
                ->columns(3)
                ->schema([
                    self::optionField('vat_21', '21%', $vatCodes),
                    self::optionField('vat_9', '9%', $vatCodes),
                    self::optionField('vat_0', '0%', $vatCodes),
                ]),
            // Verlegde BTW (reverse charge) krijgt eigen VATCodes (6/7) — een verlegd-tarief
            // mag niet stilletjes op de gewone code boeken. Leeg = de boeking weigert tot het
            // verlegd-tarief gemapt is. De keuzelijst bevat de "verlegd"-gelabelde Exact-codes.
            Section::make('BTW verlegd (verleggingsregeling)')
                ->columns(2)
                ->schema([
                    self::optionField('reverse_charge_vat_21', '21% verlegd', $vatCodes),
                    self::optionField('reverse_charge_vat_9', '9% verlegd', $vatCodes),
                ]),
            Section::make('Grootboekrekeningen (categorie → GL-Code)')
                ->schema([
                    Repeater::make('gl_accounts')
                        ->label('')
                        ->addActionLabel('Categorie toevoegen')
                        ->columns(2)
                        ->schema([
                            TextInput::make('category')
                                ->label('Categorie')
                                ->helperText('Gebruik "_default" als fallback-rekening voor regels zonder eigen categorie.'),
                            self::optionField('value', 'GL-Code', $glAccounts),
                        ]),
                ]),
            // Relaties staan niet in de mapping — ze worden lazy resolve-or-learned uit de
            // party-data van het document (ExactRelationResolver). De toggle bepaalt of een
            // ontbrekende relatie automatisch in het boekhoudpakket wordt aangemaakt.
            Section::make('Relaties')
                ->schema([
                    Toggle::make('auto_create_relations')
                        ->label('Onbekende relaties automatisch aanmaken')
                        ->helperText('Aan: geen match op btw-nummer/naam → maak de relatie aan in dit boekhoudpakket en onthoud \'m. Uit: de boeking faalt met een duidelijke melding tot de relatie bestaat.'),
                ]),
            Section::make('Dagboeken (Journal)')
                ->columns(2)
                ->schema([
                    self::optionField('journal_sales', 'Verkoop', $journals),
                    self::optionField('journal_purchase', 'Inkoop', $journals),
                    self::optionField('journal_income', 'Ad-hoc income', $journals)
                        ->helperText('Leeg = verkoopdagboek.'),
                    self::optionField('journal_expense', 'Ad-hoc expense', $journals)
                        ->helperText('Leeg = inkoopdagboek.'),
                ]),
        ];

        // Kostenplaats/-drager is Code-passthrough (geen mapping-laag) — toon enkel de
        // gesynchroniseerde Codes als inzicht: dit zijn de waarden die een consumer in
        // cost_center / cost_unit op een boekingsregel mag meesturen.
        if ($costCenters !== [] || $costUnits !== []) {
            $sections[] = Section::make('Kostenplaatsen & kostendragers')
                ->description('Read-only inzicht — Code-passthrough, hier valt niets te mappen. Dit zijn de geldige cost_center / cost_unit-Codes voor een boekingsregel.')
                ->columns(2)
                ->schema([
                    Placeholder::make('cost_centers_info')
                        ->label('Kostenplaatsen (cost_center)')
                        ->content(self::refListContent($costCenters)),
                    Placeholder::make('cost_units_info')
                        ->label('Kostendragers (cost_unit)')
                        ->content(self::refListContent($costUnits)),
                ]);
        }

        return $sections;
    }

    /**
     * GL-keuzelijst uit de gesynchroniseerde mirror ([Code => label]); leeg (nog niet
     * gesynct) valt terug op vrije invoer. De mapping slaat de Code op, niet de GUID.
     *
     * @return array<string, string>
     */
    private static function glOptionsFromMirror(Connection $record): array
    {
        return self::refOptionsFromMirror($record, ConnectionAccountingRef::KIND_GL);
    }

    /**
     * Mirror-keuzelijst per soort ([Code => label]); lege label valt terug op de kale Code.
     *
     * @return array<string, string>
     */
    private static function refOptionsFromMirror(Connection $record, string $kind): array
    {
        return ConnectionAccountingRef::query()
            ->where('connection_id', $record->getKey())
            ->where('kind', $kind)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (ConnectionAccountingRef $r): array => [
                $r->code => $r->label !== null && $r->label !== '' ? "{$r->code} — {$r->label}" : $r->code,
            ])
            ->all();
    }

    /**
     * Read-only lijst-weergave voor een mirror-keuzelijst in een Placeholder.
     *
     * @param  array<string, string>  $options
     */
    private static function refListContent(array $options): HtmlString
    {
        if ($options === []) {
            return new HtmlString('<span class="text-sm text-gray-500">Nog niets gesynchroniseerd.</span>');
        }

        $items = collect($options)
            ->map(fn (string $label): string => e($label))
            ->implode('<br>');

        return new HtmlString('<div class="text-sm">'.$items.'</div>');
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
            'reverse_charge_vat_21' => $vat['reverse_charge:21'] ?? null,
            'reverse_charge_vat_9' => $vat['reverse_charge:9'] ?? null,
            'gl_accounts' => self::toRows($mapping['gl_accounts'] ?? [], 'category'),
            'auto_create_relations' => ($mapping['auto_create_relations'] ?? false) === true,
            'journal_sales' => $journals['sales'] ?? null,
            'journal_purchase' => $journals['purchase'] ?? null,
            'journal_income' => $journals['income'] ?? null,
            'journal_expense' => $journals['expense'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function toMapping(array $data): array
    {
        $mapping = [
            'vat_codes' => self::scalarMap([
                '21' => $data['vat_21'] ?? null,
                '9' => $data['vat_9'] ?? null,
                '0' => $data['vat_0'] ?? null,
                'reverse_charge:21' => $data['reverse_charge_vat_21'] ?? null,
                'reverse_charge:9' => $data['reverse_charge_vat_9'] ?? null,
            ]),
            'gl_accounts' => self::fromRows($data['gl_accounts'] ?? [], 'category'),
            'journals' => self::scalarMap([
                'sales' => $data['journal_sales'] ?? null,
                'purchase' => $data['journal_purchase'] ?? null,
                'income' => $data['journal_income'] ?? null,
                'expense' => $data['journal_expense'] ?? null,
            ]),
        ];

        // Alleen opslaan als aan — afwezig = uit (de resolver checkt op === true).
        if (! empty($data['auto_create_relations'])) {
            $mapping['auto_create_relations'] = true;
        }

        return $mapping;
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
