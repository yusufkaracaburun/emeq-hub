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

final class ExactReferenceData
{
    private const MAX_PAGES = 500;

    private const PERIOD_CACHE_SECONDS = 3600;

    private const RELATION_SELECT = 'ID,Code,Name,IsSales,IsSupplier,Status,VATNumber,ChamberOfCommerce';

    public function __construct(private readonly Connection $connection) {}

    /** @return array<string, string> */
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

    /** @return array<string, string> */
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

    /** @return array<string, string> */
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

    /** @return array<string, string> */
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

    /** @return array<string, string> */
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

    /** @return array<string, string> */
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

    /** @return list<array{start: DateTimeImmutable, end: DateTimeImmutable, fiscal_year: int, period: int}> */
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

    /** @return list<array{start: string, end: string, fiscal_year: int, period: int}> */
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

    private static function odataDate(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\/Date\((-?\d+)/', $value, $matches) !== 1) {
            return null;
        }

        return (new DateTimeImmutable('@'.intdiv((int) $matches[1], 1000)))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d');
    }

    /** @return list<array{kind: string, code: string, native_id: string, label: string, attrs: array<string, mixed>}> */
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

    /** @return list<array{kind: string, code: string, native_id: string, label: string, attrs: array<string, mixed>}> */
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

    /** @return list<array{kind: string, code: string, native_id: string, label: string, attrs: array<string, mixed>}> */
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
                'attrs' => ['percentage' => isset($row['Percentage']) ? (float) $row['Percentage'] * 100 : null],
            ];
        }

        return $out;
    }

    /** @return list<array{kind: string, code: string, native_id: string, label: string, attrs: array<string, mixed>}> */
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

    /** @return list<array{kind: string, code: string, native_id: string, label: string, attrs: array<string, mixed>}> */
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

    /** @return list<array{kind: string, code: string, native_id: string, label: string, attrs: array<string, mixed>}> */
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

    /** @return list<array{id: string, code: string, name: string, is_sales: bool, is_supplier: bool, status: ?string, chamber_of_commerce: ?string, vat_number: ?string}> */
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

    /** @return list<array{id: string, code: string, name: string, is_sales: bool, is_supplier: bool, status: ?string, chamber_of_commerce: ?string, vat_number: ?string}> */
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

    /** @return list<array{id: string, code: string, name: string, is_sales: bool, is_supplier: bool, status: ?string, chamber_of_commerce: ?string, vat_number: ?string}> */
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

    /** @return list<string> */
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

    /** @var list<string> */
    private const LEGAL_FORM_SUFFIXES = ['bv', 'nv', 'vof', 'cv', 'bvba', 'gmbh', 'ltd', 'inc', 'holding', 'beheer'];

    private static function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = str_replace('.', '', $name);
        $name = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name) ?? $name;
        $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter($words, fn (string $word): bool => ! in_array($word, self::LEGAL_FORM_SUFFIXES, true)));

        return implode(' ', $words);
    }

    /** @return array{is_sales: bool, is_supplier: bool, status: ?string}|null */
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

    public function relationIsGone(string $guid): bool
    {
        $guid = trim($guid);
        $division = (string) $this->connection->administratie_id;

        if ($guid === '' || $division === '') {
            return false;
        }

        try {
            $response = $this->connector($division)->send(new GetRelations([
                '$select' => 'ID',
                '$filter' => "ID eq guid'".$this->escapeOData($guid)."'",
                '$top' => '1',
            ]));

            return $response->failed() ? false : Envelope::results($response->json()) === [];
        } catch (Throwable) {
            return false;
        }
    }

    private function escapeOData(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /** @return callable(array<string, scalar|null>): SdkRequest */
    private static function relationRequest(): callable
    {
        return static fn (array $params): SdkRequest => new GetRelations($params);
    }

    /** @return list<array<string, mixed>> */
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
