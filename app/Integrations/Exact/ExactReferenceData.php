<?php

declare(strict_types=1);

namespace App\Integrations\Exact;

use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use DateTimeImmutable;
use DateTimeZone;
use Emeq\ExactApi\Contracts\ExactCredentialResolver;
use Emeq\ExactApi\Contracts\TokenStore;
use Emeq\ExactApi\Exact;
use Emeq\ExactApi\Http\ExactConnector;
use Emeq\ExactApi\Http\Request\RawExactRequest;
use Emeq\ExactApi\Http\Request\Read\GetCostCenters;
use Emeq\ExactApi\Http\Request\Read\GetCostUnits;
use Emeq\ExactApi\Http\Request\Read\GetGlAccounts;
use Emeq\ExactApi\Http\Request\Read\GetJournals;
use Emeq\ExactApi\Http\Request\Read\GetRelations;
use Emeq\ExactApi\Http\Request\Read\GetVatCodes;
use Emeq\ExactApi\OData\Envelope;
use Illuminate\Support\Facades\Cache;
use Saloon\Enums\Method;
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
    // Voorkomt dat een oneindige `__next`-lus een Octane-worker op het boekingspad vastpint.
    private const MAX_PAGES = 500;

    // Kort genoeg dat een nieuw boekjaar dezelfde dag doorkomt, lang genoeg dat een
    // backlog-run van honderden validaties er één Exact-call over doet.
    private const PERIOD_CACHE_SECONDS = 3600;

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
     * De boekperioden die de administratie kent, als datumbereiken.
     *
     * Exact levert `FinancialPeriods` zonder status-veld: een periode die er staat is
     * boekbaar, een datum die in geen enkele periode valt levert bij het boeken
     * `Verplicht: Boekjaar` op. De lijst wisselt hooguit bij een jaarwisseling, dus hij
     * wordt kort gecachet in plaats van gespiegeld — een gespiegelde lijst die niemand
     * ververst zou in januari elk document van het nieuwe jaar onterecht blokkeren.
     *
     * @return list<array{start: DateTimeImmutable, end: DateTimeImmutable, fiscal_year: int, period: int}>
     */
    public function financialPeriods(): array
    {
        $division = (string) $this->connection->administratie_id;

        if ($division === '') {
            return [];
        }

        $cached = Cache::remember(
            "exact:financial-periods:{$this->connection->getKey()}:{$division}",
            self::PERIOD_CACHE_SECONDS,
            fn (): array => $this->readFinancialPeriods(),
        );

        return array_map(
            static fn (array $row): array => [
                'start' => new DateTimeImmutable($row['start'].' 00:00:00', new DateTimeZone('UTC')),
                'end' => new DateTimeImmutable($row['end'].' 00:00:00', new DateTimeZone('UTC')),
                'fiscal_year' => $row['fiscal_year'],
                'period' => $row['period'],
            ],
            $cached,
        );
    }

    /**
     * Alleen scalars: de cache serialiseert wat hier uit komt, en een teruggelezen
     * `DateTimeImmutable` kwam als incomplete object terug zodra de store écht
     * serialiseert (Redis op productie; de array-store in tests niet).
     *
     * @return list<array{start: string, end: string, fiscal_year: int, period: int}>
     */
    private function readFinancialPeriods(): array
    {
        $request = new RawExactRequest(
            method: Method::GET,
            endpoint: '/financial/FinancialPeriods',
            query: ['$select' => 'FinYear,FinPeriod,StartDate,EndDate'],
        );

        $out = [];

        foreach ($this->fetch($request) as $row) {
            $start = self::odataDate($row['StartDate'] ?? null);
            $end = self::odataDate($row['EndDate'] ?? null);

            if ($start === null || $end === null) {
                continue;
            }

            $out[] = [
                'start' => $start,
                'end' => $end,
                'fiscal_year' => (int) ($row['FinYear'] ?? 0),
                'period' => (int) ($row['FinPeriod'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Exact serialiseert OData-datums als `/Date(1793491200000)/` — milliseconden sinds epoch.
     */
    private static function odataDate(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\/Date\((-?\d+)/', $value, $matches) !== 1) {
            return null;
        }

        return (new DateTimeImmutable('@'.intdiv((int) $matches[1], 1000)))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d');
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
     * exacte Name-match. Beide kandidaten-ophalen doorpagineren tot Exact geen `__next`
     * meer teruggeeft — anders staat de tweede helft van een ambigu paar buiten beeld en
     * kiest de code stilletjes de enige treffer die het wél zag.
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
        $rows = $this->fetchAllPages([
            '$select' => 'ID,Code,Name,IsSales,IsSupplier,Status,VATNumber',
            '$filter' => "VATNumber ne ''",
        ]);

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

        $rows = $this->fetchAllPages([
            '$select' => 'ID,Code,Name,IsSales,IsSupplier,Status',
            '$filter' => "Name eq '".$this->escapeOData($name)."'",
        ]);

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
            $response = $this->connector($division)->send($request);

            if ($response->failed()) {
                return [];
            }

            return Envelope::results($response->json());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Haalt een `GetRelations`-lees volledig op, pagina voor pagina, via Exact's OData-
     * continuation-token (`$skiptoken`) — zelfde patroon als
     * `ExactAccountingTarget::readPage()`. Faalt zacht als `fetch()`: elke fout (ook op een
     * latere pagina, een `MAX_PAGES`-overschrijding, of een herhaald skiptoken) levert een
     * lege lijst op, nooit een onvolledige set — een onvolledige set zou de
     * ambiguïteitsdetectie in `findRelation()` een treffer laten missen.
     *
     * @param  array<string, scalar|null>  $params
     * @return list<array<string, mixed>>
     */
    private function fetchAllPages(array $params): array
    {
        $division = (string) $this->connection->administratie_id;

        if ($division === '') {
            return [];
        }

        $rows = [];

        try {
            $connector = $this->connector($division);
            $skipToken = null;
            $seenSkipTokens = [];

            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $pageParams = $params;

                if ($skipToken !== null) {
                    $pageParams['$skiptoken'] = $skipToken;
                }

                $response = $connector->send(new GetRelations($pageParams));

                if ($response->failed()) {
                    return [];
                }

                $json = (array) $response->json();
                array_push($rows, ...Envelope::results($json));
                $skipToken = Envelope::nextSkipToken($json);

                if ($skipToken === null) {
                    return $rows;
                }

                if (isset($seenSkipTokens[$skipToken])) {
                    return [];
                }

                $seenSkipTokens[$skipToken] = true;
            }

            return [];
        } catch (Throwable) {
            return [];
        }
    }

    private function connector(string $division): ExactConnector
    {
        app()->instance(ExactCredentialResolver::class, new HubExactCredentialResolver($this->connection));
        app()->instance(TokenStore::class, new ConnectionTokenStore($this->connection));
        app()->forgetInstance(Exact::class);

        /** @var Exact $exact */
        $exact = app(Exact::class);

        return $exact->connector($division);
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
