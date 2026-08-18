<?php

declare(strict_types=1);

namespace App\Integrations\Exact;

use App\Integrations\Exact\Accounting\ExactRelationResolver;
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

    // ChamberOfCommerce erbij t.o.v. de eerdere relatie-select: alle drie de
    // ladder-stappen (KvK/btw/naam) mappen naar dezelfde kandidaat-vorm.
    private const RELATION_SELECT = 'ID,Code,Name,IsSales,IsSupplier,Status,VATNumber,ChamberOfCommerce';

    public function __construct(private readonly Connection $connection) {}

    /**
     * BTW-codes: [Code => label]. De Code is de waarde die de mapping als VATCode opslaat.
     *
     * @return array<string, string>
     */
    public function vatCodes(): array
    {
        $out = [];

        foreach ($this->fetchAllPages(['$select' => 'Code,Description,Percentage'], static fn (array $params): SdkRequest => new GetVatCodes($params)) as $row) {
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

        foreach ($this->fetchAllPages(['$select' => 'ID,Code,Description'], static fn (array $params): SdkRequest => new GetGlAccounts($params)) as $row) {
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

        foreach ($this->fetchAllPages(['$select' => 'ID,Name,Code'], static fn (array $params): SdkRequest => new GetRelations($params)) as $row) {
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

        foreach ($this->fetchAllPages(['$select' => 'Code,Description,Type'], static fn (array $params): SdkRequest => new GetJournals($params)) as $row) {
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

        foreach ($this->fetchAllPages(['$select' => 'Code,Description'], static fn (array $params): SdkRequest => new GetCostCenters($params)) as $row) {
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

        foreach ($this->fetchAllPages(['$select' => 'Code,Description'], static fn (array $params): SdkRequest => new GetCostUnits($params)) as $row) {
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

        foreach ($this->fetchAllPages(['$select' => 'ID,Code,Description'], static fn (array $params): SdkRequest => new GetGlAccounts($params)) as $row) {
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

        foreach ($this->fetchAllPages(['$select' => 'Code,Description,Percentage'], static fn (array $params): SdkRequest => new GetVatCodes($params)) as $row) {
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

        foreach ($this->fetchAllPages(['$select' => 'Code,Description,Type'], static fn (array $params): SdkRequest => new GetJournals($params)) as $row) {
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

        foreach ($this->fetchAllPages(['$select' => 'Code,Description'], static fn (array $params): SdkRequest => new GetCostCenters($params)) as $row) {
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

        foreach ($this->fetchAllPages(['$select' => 'Code,Description'], static fn (array $params): SdkRequest => new GetCostUnits($params)) as $row) {
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
     * KvK-stap van de relatie-resolutie ({@see ExactRelationResolver}).
     * Twee server-side `$filter`-probes — de rauwe waarde en de alleen-cijfers-variant
     * (Exact draagt een KvK-nummer soms met spaties/streepjes) — nooit een volledige scan:
     * deze stap moet goedkoop blijven, hij loopt bij elke boeking op een `company`-party.
     *
     * Geeft alle kandidaten terug (0, 1 of meer) — de caller beslist wat "ambigu" betekent.
     *
     * @return list<array{id: string, code: string, name: string, is_sales: bool, is_supplier: bool, status: ?string, chamber_of_commerce: ?string, vat_number: ?string}>
     */
    public function relationsByChamberOfCommerce(?string $chamberOfCommerce): array
    {
        $chamberOfCommerce = trim((string) $chamberOfCommerce);

        if ($chamberOfCommerce === '') {
            return [];
        }

        $rows = [];

        foreach ($this->probeVariants($chamberOfCommerce) as $variant) {
            $rows[] = $this->fetchAllPages([
                '$select' => self::RELATION_SELECT,
                '$filter' => "ChamberOfCommerce eq '".$this->escapeOData($variant)."'",
            ], self::relationRequest());
        }

        return $this->mapRelationRows($this->dedupeById(array_merge(...$rows)));
    }

    /**
     * Btw-stap: eerst dezelfde twee goedkope server-side probes (rauw + genormaliseerd),
     * en alleen wanneer die niets opleveren de bestaande volledige scan met lokale
     * normalisatie als vangnet — nodig voor `NL8037.25.802.B01` vs `NL803725802B01`,
     * waar Exact's `$filter` (letterlijke string-equality) niet voor normaliseert.
     *
     * @return list<array{id: string, code: string, name: string, is_sales: bool, is_supplier: bool, status: ?string, chamber_of_commerce: ?string, vat_number: ?string}>
     */
    public function relationsByVatNumber(?string $vatNumber): array
    {
        $normalized = self::normalizeVatNumber($vatNumber);

        if ($normalized === '') {
            return [];
        }

        $rows = [];

        foreach ($this->probeVariants((string) $vatNumber, $normalized) as $variant) {
            $rows[] = $this->fetchAllPages([
                '$select' => self::RELATION_SELECT,
                '$filter' => "VATNumber eq '".$this->escapeOData($variant)."'",
            ], self::relationRequest());
        }

        $candidates = $this->dedupeById(array_merge(...$rows));

        if ($candidates !== []) {
            return $this->mapRelationRows($candidates);
        }

        $all = $this->fetchAllPages([
            '$select' => self::RELATION_SELECT,
            '$filter' => "VATNumber ne ''",
        ], self::relationRequest());

        return $this->mapRelationRows(array_values(array_filter(
            $all,
            fn (array $row) => self::normalizeVatNumber($row['VATNumber'] ?? null) === $normalized
        )));
    }

    /**
     * Naam-stap: volledige scan (Exact's `$filter` kan geen rechtsvorm/interpunctie
     * negeren) + lokale genormaliseerde vergelijking — lowercase, interpunctie weg,
     * rechtsvorm-suffixen weg ("Acme B.V." matcht zo "Acme BV"). Laatste en duurste
     * stap van de ladder, bewust: hij draait vlak vóór het enige onomkeerbare moment
     * (aanmaken in andermans administratie).
     *
     * @return list<array{id: string, code: string, name: string, is_sales: bool, is_supplier: bool, status: ?string, chamber_of_commerce: ?string, vat_number: ?string}>
     */
    public function relationsByName(?string $name): array
    {
        $normalized = self::normalizeName((string) $name);

        if ($normalized === '') {
            return [];
        }

        $all = $this->fetchAllPages(['$select' => self::RELATION_SELECT], self::relationRequest());

        return $this->mapRelationRows(array_values(array_filter(
            $all,
            fn (array $row) => self::normalizeName((string) ($row['Name'] ?? '')) === $normalized
        )));
    }

    /**
     * @return list<string>
     */
    private function probeVariants(string $raw, ?string $normalized = null): array
    {
        $raw = trim($raw);
        $normalized ??= preg_replace('/\D+/', '', $raw) ?? '';

        return array_values(array_unique(array_filter([$raw, $normalized], fn (string $v): bool => $v !== '')));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function dedupeById(array $rows): array
    {
        $byId = [];

        foreach ($rows as $row) {
            $id = (string) ($row['ID'] ?? '');

            if ($id !== '') {
                $byId[$id] = $row;
            }
        }

        return array_values($byId);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{id: string, code: string, name: string, is_sales: bool, is_supplier: bool, status: ?string, chamber_of_commerce: ?string, vat_number: ?string}>
     */
    private function mapRelationRows(array $rows): array
    {
        return array_values(array_filter(array_map($this->mapRelationRow(...), $rows)));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, code: string, name: string, is_sales: bool, is_supplier: bool, status: ?string, chamber_of_commerce: ?string, vat_number: ?string}|null
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
            'chamber_of_commerce' => $this->nullableString($row['ChamberOfCommerce'] ?? null),
            'vat_number' => $this->nullableString($row['VATNumber'] ?? null),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
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
     * Rechtsvorm-suffixen die de dedupe-vergelijking negeert — "Acme B.V." en "Acme
     * Holding" moeten allebei op "Acme" matchen. Vaste lijst i.p.v. een regex-heuristiek:
     * expliciet houdt de aannames zichtbaar en voorkomt dat een gewone bedrijfsnaam met
     * bv. "inc" erin per ongeluk wordt afgeknipt.
     *
     * @var list<string>
     */
    private const LEGAL_FORM_SUFFIXES = ['bv', 'nv', 'vof', 'cv', 'bvba', 'gmbh', 'ltd', 'inc', 'holding', 'beheer'];

    private static function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        // Punten eerst apart weghalen (zonder spatie): "B.V." moet als één token "bv"
        // overblijven, niet als de twee losse tokens "b" en "v".
        $name = str_replace('.', '', $name);
        $name = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name) ?? $name;
        $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter($words, fn (string $word): bool => ! in_array($word, self::LEGAL_FORM_SUFFIXES, true)));

        return implode(' ', $words);
    }

    /**
     * Leest de rol-vlaggen van één relatie op GUID — gebruikt om een uit de mirror
     * herbruikte relatie naar de juiste rol te promoveren vóór de boeking. Fail-soft:
     * niet leesbaar → null (de caller slaat de promotie dan over en laat de boeking
     * zelf de fout opleveren). Bewust één pagina: het filter is een unieke sleutel,
     * dus een tweede pagina kan geen kandidaat verbergen.
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
     * @return callable(array<string, scalar|null>): SdkRequest
     */
    private static function relationRequest(): callable
    {
        return static fn (array $params): SdkRequest => new GetRelations($params);
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
     * Haalt een lees volledig op, pagina voor pagina, via Exact's OData-
     * continuation-token (`$skiptoken`) — zelfde patroon als
     * `ExactAccountingTarget::readPage()`. Faalt zacht als `fetch()`: elke fout (ook op een
     * latere pagina, een `MAX_PAGES`-overschrijding, of een herhaald skiptoken) levert een
     * lege lijst op, nooit een onvolledige set — een onvolledige set zou de
     * ambiguïteitsdetectie in de relatie-resolutie ({@see ExactRelationResolver})
     * een treffer laten missen, en de mirror structureel incompleet vullen.
     *
     * @param  array<string, scalar|null>  $params
     * @param  callable(array<string, scalar|null>): SdkRequest  $make
     * @return list<array<string, mixed>>
     */
    private function fetchAllPages(array $params, callable $make): array
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

                $response = $connector->send($make($pageParams));

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
