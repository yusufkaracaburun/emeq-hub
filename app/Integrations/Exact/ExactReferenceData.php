<?php

declare(strict_types=1);

namespace App\Integrations\Exact;

use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use Emeq\ExactApi\Contracts\ExactCredentialResolver;
use Emeq\ExactApi\Contracts\TokenStore;
use Emeq\ExactApi\Exact;
use Emeq\ExactApi\Http\Request\Read\GetCostCenters;
use Emeq\ExactApi\Http\Request\Read\GetCostUnits;
use Emeq\ExactApi\Http\Request\Read\GetGlAccounts;
use Emeq\ExactApi\Http\Request\Read\GetJournals;
use Emeq\ExactApi\Http\Request\Read\GetRelations;
use Emeq\ExactApi\Http\Request\Read\GetVatCodes;
use Emeq\ExactApi\OData\Envelope;
use Saloon\Http\Request as SdkRequest;
use Throwable;

/**
 * Haalt Exact-referentiedata (BTW-codes, grootboekrekeningen, relaties, dagboeken)
 * op voor de boekhoud-mapping-UI in de admin. Draait server-side op één Connection
 * — geen consumer-request, dus géén ExactForwarder/pass-through-audit — en bindt de
 * SDK per-call zoals ResolveExactAccount + ExactAccountingTarget.
 *
 * De endpoints + response-envelope leven in de emeq/exact-api SDK (named read-requests
 * + Envelope); deze service kiest alleen de `$select` en mapt de records naar UI-labels.
 * Faalt zacht: bij een ontbrekende division, een pending Connection of een Exact-fout
 * levert elke methode een lege lijst, zodat de UI terugvalt op handinvoer.
 */
final class ExactReferenceData
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * BTW-codes: [Code => label]. De Code is de waarde die de mapping als VATCode opslaat.
     *
     * @return array<string, string>
     */
    public function vatCodes(): array
    {
        $out = [];

        foreach ($this->fetch(new GetVatCodes(['$select' => 'Code,Description,Percentage'])) as $row) {
            $code = trim((string) ($row['Code'] ?? ''));

            if ($code === '') {
                continue;
            }

            $out[$code] = $this->label($code, $row['Description'] ?? null).$this->percentageSuffix($row['Percentage'] ?? null);
        }

        return $out;
    }

    /**
     * Grootboekrekeningen: [GUID => label]. De ID (GUID) is de mapping-waarde.
     *
     * @return array<string, string>
     */
    public function glAccounts(): array
    {
        $out = [];

        foreach ($this->fetch(new GetGlAccounts(['$select' => 'ID,Code,Description'])) as $row) {
            $id = (string) ($row['ID'] ?? '');

            if ($id === '') {
                continue;
            }

            $out[$id] = $this->label(trim((string) ($row['Code'] ?? '')), $row['Description'] ?? null);
        }

        return $out;
    }

    /**
     * Relaties: [GUID => Naam]. De ID (GUID) is de mapping-waarde (crm/Account-GUID).
     *
     * @return array<string, string>
     */
    public function relations(): array
    {
        $out = [];

        foreach ($this->fetch(new GetRelations(['$select' => 'ID,Name,Code'])) as $row) {
            $id = (string) ($row['ID'] ?? '');

            if ($id === '') {
                continue;
            }

            $out[$id] = (string) ($row['Name'] ?? $id);
        }

        return $out;
    }

    /**
     * Dagboeken: [Code => label]. De Code is de mapping-waarde (Journal).
     *
     * @return array<string, string>
     */
    public function journals(): array
    {
        $out = [];

        foreach ($this->fetch(new GetJournals(['$select' => 'Code,Description,Type'])) as $row) {
            $code = trim((string) ($row['Code'] ?? ''));

            if ($code === '') {
                continue;
            }

            $out[$code] = $this->label($code, $row['Description'] ?? null);
        }

        return $out;
    }

    /**
     * Kostenplaatsen: [Code => label]. De Code is de mapping-/boekings-waarde (CostCenter).
     *
     * @return array<string, string>
     */
    public function costCenters(): array
    {
        $out = [];

        foreach ($this->fetch(new GetCostCenters(['$select' => 'Code,Description'])) as $row) {
            $code = trim((string) ($row['Code'] ?? ''));

            if ($code === '') {
                continue;
            }

            $out[$code] = $this->label($code, $row['Description'] ?? null);
        }

        return $out;
    }

    /**
     * Kostendragers: [Code => label]. De Code is de mapping-/boekings-waarde (CostUnit).
     *
     * @return array<string, string>
     */
    public function costUnits(): array
    {
        $out = [];

        foreach ($this->fetch(new GetCostUnits(['$select' => 'Code,Description'])) as $row) {
            $code = trim((string) ($row['Code'] ?? ''));

            if ($code === '') {
                continue;
            }

            $out[$code] = $this->label($code, $row['Description'] ?? null);
        }

        return $out;
    }

    /**
     * Rich mirror-rijen voor de reference-sync: stabiele `code` → provider-native `native_id`.
     * GL: native_id = de GUID. VAT/journal: native_id = de Code (Exact accepteert die direct
     * op de boeking); `attrs` draagt kind-specifieke velden (percentage / type).
     *
     * @return list<array{kind: string, code: string, native_id: string, label: string, attrs: array<string, mixed>}>
     */
    public function mirrorRows(): array
    {
        return [
            ...$this->glAccountRows(),
            ...$this->vatCodeRows(),
            ...$this->journalRows(),
            ...$this->costCenterRows(),
            ...$this->costUnitRows(),
        ];
    }

    /**
     * @return list<array{kind: string, code: string, native_id: string, label: string, attrs: array<string, mixed>}>
     */
    public function glAccountRows(): array
    {
        $out = [];

        foreach ($this->fetch(new GetGlAccounts(['$select' => 'ID,Code,Description'])) as $row) {
            $code = trim((string) ($row['Code'] ?? ''));
            $id = (string) ($row['ID'] ?? '');

            if ($code === '' || $id === '') {
                continue;
            }

            $out[] = [
                'kind' => ConnectionAccountingRef::KIND_GL,
                'code' => $code,
                'native_id' => $id,
                'label' => trim((string) ($row['Description'] ?? '')),
                'attrs' => [],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{kind: string, code: string, native_id: string, label: string, attrs: array<string, mixed>}>
     */
    public function vatCodeRows(): array
    {
        $out = [];

        foreach ($this->fetch(new GetVatCodes(['$select' => 'Code,Description,Percentage'])) as $row) {
            $code = trim((string) ($row['Code'] ?? ''));

            if ($code === '') {
                continue;
            }

            $out[] = [
                'kind' => ConnectionAccountingRef::KIND_VAT,
                'code' => $code,
                'native_id' => $code,
                'label' => trim((string) ($row['Description'] ?? '')),
                // Exact draagt Percentage als fractie (0.21) → naar heel-percentage (21) voor de mapping-match.
                'attrs' => ['percentage' => isset($row['Percentage']) ? (float) $row['Percentage'] * 100 : null],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{kind: string, code: string, native_id: string, label: string, attrs: array<string, mixed>}>
     */
    public function journalRows(): array
    {
        $out = [];

        foreach ($this->fetch(new GetJournals(['$select' => 'Code,Description,Type'])) as $row) {
            $code = trim((string) ($row['Code'] ?? ''));

            if ($code === '') {
                continue;
            }

            $out[] = [
                'kind' => ConnectionAccountingRef::KIND_JOURNAL,
                'code' => $code,
                'native_id' => $code,
                'label' => trim((string) ($row['Description'] ?? '')),
                'attrs' => ['type' => isset($row['Type']) ? (int) $row['Type'] : null],
            ];
        }

        return $out;
    }

    /**
     * Kostenplaatsen: native_id = Code (Exact accepteert de Code direct op de boekingsregel,
     * `Edm.String` — anders dan GLAccount/GUID).
     *
     * @return list<array{kind: string, code: string, native_id: string, label: string, attrs: array<string, mixed>}>
     */
    public function costCenterRows(): array
    {
        $out = [];

        foreach ($this->fetch(new GetCostCenters(['$select' => 'Code,Description'])) as $row) {
            $code = trim((string) ($row['Code'] ?? ''));

            if ($code === '') {
                continue;
            }

            $out[] = [
                'kind' => ConnectionAccountingRef::KIND_COST_CENTER,
                'code' => $code,
                'native_id' => $code,
                'label' => trim((string) ($row['Description'] ?? '')),
                'attrs' => [],
            ];
        }

        return $out;
    }

    /**
     * Kostendragers: native_id = Code (zie costCenterRows).
     *
     * @return list<array{kind: string, code: string, native_id: string, label: string, attrs: array<string, mixed>}>
     */
    public function costUnitRows(): array
    {
        $out = [];

        foreach ($this->fetch(new GetCostUnits(['$select' => 'Code,Description'])) as $row) {
            $code = trim((string) ($row['Code'] ?? ''));

            if ($code === '') {
                continue;
            }

            $out[] = [
                'kind' => ConnectionAccountingRef::KIND_COST_UNIT,
                'code' => $code,
                'native_id' => $code,
                'label' => trim((string) ($row['Description'] ?? '')),
                'attrs' => [],
            ];
        }

        return $out;
    }

    /**
     * Zoekt één Exact-relatie op een stabiele sleutel (VATNumber, anders exacte Name) voor de
     * lazy relatie-resolutie. Geeft `{id, code, name, is_sales, is_supplier, status}` van de
     * eerste match, of null. De rol-vlaggen laten de caller een relatie naar de juiste rol
     * promoveren (debiteur ↔ crediteur) vóór de boeking.
     *
     * Exact's `$filter` kan niet normaliseren, dus een btw-nummer-match haalt de kandidaten
     * met een ingevuld VATNumber op en vergelijkt lokaal genormaliseerd (hoofdletters, geen
     * spaties/punten/streepjes) — anders mist `NL8037.25.802.B01` op `NL803725802B01`. Een
     * ambigu btw-nummer (2+ treffers) stopt hard: nooit terugvallen op naam, dat zou een
     * andere relatie kunnen kiezen dan de btw-treffers bedoelen. Levert het btw-nummer géén
     * of geen enkele treffer op (of ontbreekt het), dan valt de zoekopdracht terug op een
     * exacte Name-match.
     *
     * @return array{id: string, code: string, name: string, is_sales: bool, is_supplier: bool, status: ?string}|null
     */
    public function findRelation(?string $vatNumber, ?string $name): ?array
    {
        $normalizedVat = self::normalizeVatNumber($vatNumber);

        if ($normalizedVat !== '') {
            $vatMatches = $this->matchesByVatNumber($normalizedVat);

            if (count($vatMatches) === 1) {
                return $this->mapRelationRow($vatMatches[0]);
            }

            if (count($vatMatches) > 1) {
                return null;
            }
        }

        return $this->matchByName($name);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function matchesByVatNumber(string $normalizedVat): array
    {
        $rows = $this->fetch(new GetRelations([
            '$select' => 'ID,Code,Name,IsSales,IsSupplier,Status,VATNumber',
            '$filter' => "VATNumber ne ''",
        ]));

        return array_values(array_filter(
            $rows,
            fn (array $row) => self::normalizeVatNumber($row['VATNumber'] ?? null) === $normalizedVat
        ));
    }

    private function matchByName(?string $name): ?array
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $rows = $this->fetch(new GetRelations([
            '$select' => 'ID,Code,Name,IsSales,IsSupplier,Status',
            '$filter' => "Name eq '".$this->escapeOData($name)."'",
            '$top' => '2',
        ]));

        // Geen of meerdere matches → niet automatisch kiezen (ambigu).
        return count($rows) === 1 ? $this->mapRelationRow($rows[0]) : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, code: string, name: string, is_sales: bool, is_supplier: bool, status: ?string}|null
     */
    private function mapRelationRow(array $row): ?array
    {
        $id = (string) ($row['ID'] ?? '');

        return $id === '' ? null : [
            'id' => $id,
            'code' => trim((string) ($row['Code'] ?? '')),
            'name' => (string) ($row['Name'] ?? ''),
            'is_sales' => (bool) ($row['IsSales'] ?? false),
            'is_supplier' => (bool) ($row['IsSupplier'] ?? false),
            'status' => isset($row['Status']) ? (string) $row['Status'] : null,
        ];
    }

    private static function normalizeVatNumber(?string $vatNumber): string
    {
        $vatNumber = trim((string) $vatNumber);

        if ($vatNumber === '') {
            return '';
        }

        return mb_strtoupper(preg_replace('/[\s.\-]+/', '', $vatNumber) ?? $vatNumber);
    }

    /**
     * Leest de rol-vlaggen van één relatie op GUID — gebruikt om een uit de mirror
     * herbruikte relatie naar de juiste rol te promoveren vóór de boeking. Fail-soft:
     * niet leesbaar → null (de caller slaat de promotie dan over en laat de boeking
     * zelf de fout opleveren).
     *
     * @return array{is_sales: bool, is_supplier: bool, status: ?string}|null
     */
    public function relationRoles(string $guid): ?array
    {
        $guid = trim($guid);

        if ($guid === '') {
            return null;
        }

        $rows = $this->fetch(new GetRelations([
            '$select' => 'ID,IsSales,IsSupplier,Status',
            '$filter' => "ID eq guid'".$this->escapeOData($guid)."'",
            '$top' => '1',
        ]));

        if ($rows === []) {
            return null;
        }

        return [
            'is_sales' => (bool) ($rows[0]['IsSales'] ?? false),
            'is_supplier' => (bool) ($rows[0]['IsSupplier'] ?? false),
            'status' => isset($rows[0]['Status']) ? (string) $rows[0]['Status'] : null,
        ];
    }

    private function escapeOData(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetch(SdkRequest $request): array
    {
        $division = (string) $this->connection->administratie_id;

        if ($division === '') {
            return [];
        }

        try {
            app()->instance(ExactCredentialResolver::class, new HubExactCredentialResolver($this->connection));
            app()->instance(TokenStore::class, new ConnectionTokenStore($this->connection));
            app()->forgetInstance(Exact::class);

            /** @var Exact $exact */
            $exact = app(Exact::class);

            $response = $exact->connector($division)->send($request);

            if ($response->failed()) {
                return [];
            }

            return Envelope::results($response->json());
        } catch (Throwable) {
            return [];
        }
    }

    private function label(string $code, ?string $description): string
    {
        $description = trim((string) $description);

        if ($code !== '' && $description !== '') {
            return "{$code} — {$description}";
        }

        return $code !== '' ? $code : $description;
    }

    private function percentageSuffix(mixed $percentage): string
    {
        if ($percentage === null || $percentage === '') {
            return '';
        }

        $value = (float) $percentage;
        $formatted = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return " ({$formatted}%)";
    }
}
