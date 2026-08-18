<?php

declare(strict_types=1);

namespace App\Integrations\Exact\Accounting;

use App\Accounting\Enums\TaxTreatment;
use App\Accounting\Party;
use App\Accounting\Validation\Finding;
use App\Accounting\Validation\Severity;
use App\Accounting\Validation\Support\Money;
use App\Integrations\Exact\ExactReferenceData;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class ExactReportEnricher
{
    /** @var array<string, array{kind: string, label: string}> */
    private const COST_DIMENSIONS = [
        'cost_center' => ['kind' => ConnectionAccountingRef::KIND_COST_CENTER, 'label' => 'Kostenplaats'],
        'cost_unit' => ['kind' => ConnectionAccountingRef::KIND_COST_UNIT, 'label' => 'Kostendrager'],
    ];

    public function __construct(
        private readonly ConnectionMappingExactReferenceResolver $resolver,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return list<Finding>
     */
    public function enrich(array $payload, Connection $connection): array
    {
        return [
            ...$this->vatCodeFindings($payload, $connection),
            ...$this->relationFindings($payload, $connection),
            ...$this->costDimensionFindings($payload, $connection),
            ...$this->periodFindings($payload, $connection),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<Finding>
     */
    private function periodFindings(array $payload, Connection $connection): array
    {
        $issueDate = $this->scalarString($payload['issue_date'] ?? null);

        if ($issueDate === null) {
            return [];
        }

        $date = self::dateOrNull($issueDate);

        if ($date === null) {
            return [];
        }

        try {
            $periods = (new ExactReferenceData($connection))->financialPeriods();
        } catch (Throwable) {
            return [];
        }

        if ($periods === []) {
            return [];
        }

        foreach ($periods as $period) {
            if ($date >= $period['start'] && $date <= $period['end']) {
                return [];
            }
        }

        $from = min(array_column($periods, 'start'))->format('d-m-Y');
        $until = max(array_column($periods, 'end'))->format('d-m-Y');

        return [
            new Finding(
                code: 'exact.period.closed',
                severity: Severity::Warning,
                blocking: true,
                path: 'issue_date',
                message: "De administratie kent geen boekperiode voor deze datum — de boeking wordt geweigerd. Boekbaar is {$from} t/m {$until}. Open het boekjaar in de administratie of gebruik een datum binnen dat bereik.",
                current: $issueDate,
                suggestion: null,
            ),
        ];
    }

    private static function dateOrNull(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10), new DateTimeZone('UTC'));

        return $date === false ? null : $date;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<Finding>
     */
    private function vatCodeFindings(array $payload, Connection $connection): array
    {
        $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
        $findings = [];
        $seen = [];

        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $rate = Money::toFloat($line['tax_rate'] ?? null);

            if ($rate === null) {
                continue;
            }

            $treatment = TaxTreatment::tryFrom((string) ($line['tax_treatment'] ?? '')) ?? TaxTreatment::Standard;
            $key = $treatment->value.'|'.number_format($rate, 2, '.', '');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            if ($this->resolver->vatCodeOrNull($rate, $treatment, $connection) !== null) {
                continue;
            }

            $label = $this->rateLabel($rate).($treatment === TaxTreatment::ReverseCharge ? '% verlegd' : '%');

            $findings[] = new Finding(
                code: 'exact.vat_code.unmapped',
                severity: Severity::Warning,
                blocking: true,
                path: "lines.{$index}.tax_rate",
                message: "BTW-tarief {$label} is nog niet ingericht voor deze administratie — de boeking wordt hierop geweigerd. Richt het tarief in of gebruik een tarief dat de administratie al kent.",
                current: $line['tax_rate'] ?? null,
                suggestion: null,
            );
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<Finding>
     */
    private function relationFindings(array $payload, Connection $connection): array
    {
        $party = is_array($payload['party'] ?? null) ? $payload['party'] : [];
        $externalId = $this->scalarString($party['external_id'] ?? null);
        $relationId = $this->scalarString($party['relation_id'] ?? null);
        $chamberOfCommerce = $this->scalarString($party['chamber_of_commerce'] ?? null);
        $vatNumber = is_string($party['vat_number'] ?? null) ? $party['vat_number'] : null;
        $name = is_string($party['name'] ?? null) ? $party['name'] : null;
        $kind = is_string($party['kind'] ?? null) ? $party['kind'] : Party::KIND_COMPANY;
        $label = $this->partyLabel(is_string($party['role'] ?? null) ? $party['role'] : null);

        if ($relationId !== null) {
            return [$this->relationMatched($label, null, $relationId, $name)];
        }

        if ($externalId !== null) {
            $known = ConnectionAccountingRef::query()
                ->where('connection_id', $connection->getKey())
                ->where('kind', ConnectionAccountingRef::KIND_RELATION)
                ->where('code', $externalId)
                ->first();

            if ($known !== null) {
                return [$this->relationMatched($label, $known->label ?? $name, (string) $known->native_id, $name)];
            }
        }

        if ($kind === Party::KIND_PERSON) {
            return [$this->relationNew($label, $name)];
        }

        if ($this->blank($chamberOfCommerce) && $this->blank($vatNumber) && $this->blank($name)) {
            return [];
        }

        try {
            $data = new ExactReferenceData($connection);

            $kvkMatches = $data->relationsByChamberOfCommerce($chamberOfCommerce);

            if (count($kvkMatches) > 1) {
                return [$this->relationAmbiguous($label, 'KvK-nummer', $kvkMatches)];
            }

            if (count($kvkMatches) === 1) {
                return [$this->relationMatched($label, $kvkMatches[0]['name'], $kvkMatches[0]['id'], $name)];
            }

            $vatMatches = $data->relationsByVatNumber($vatNumber);

            if (count($vatMatches) > 1) {
                return [$this->relationAmbiguous($label, 'btw-nummer', $vatMatches)];
            }

            if (count($vatMatches) === 1) {
                return [$this->relationMatched($label, $vatMatches[0]['name'], $vatMatches[0]['id'], $name)];
            }

            $nameMatches = $data->relationsByName($name);

            if (count($nameMatches) === 1) {
                return [$this->relationMatched($label, $nameMatches[0]['name'], $nameMatches[0]['id'], $name)];
            }
        } catch (Throwable) {
            return [];
        }

        return [$this->relationNew($label, $name)];
    }

    private function relationMatched(string $label, ?string $matchedName, string $nativeId, ?string $current): Finding
    {
        $suffix = $this->blank($matchedName) ? '' : " '{$matchedName}'";

        return new Finding(
            code: 'exact.relation.matched',
            severity: Severity::Info,
            blocking: false,
            path: 'party',
            message: "{$label} is herkend als bestaande relatie{$suffix} in de administratie — de boeking komt daarop te staan.",
            current: $current,
            suggestion: $nativeId,
        );
    }

    /** @param  list<array{id: string, name: string}>  $matches */
    private function relationAmbiguous(string $label, string $on, array $matches): Finding
    {
        $names = implode(', ', array_map(fn (array $match): string => $match['name'], $matches));

        return new Finding(
            code: 'exact.relation.ambiguous',
            severity: Severity::Warning,
            blocking: true,
            path: 'party',
            message: "{$label} matcht meerdere relaties op {$on} in de administratie ({$names}) — de boeking wordt geweigerd. Kies er één en stuur die mee als party.relation_id.",
            current: null,
            suggestion: null,
        );
    }

    private function relationNew(string $label, ?string $name): Finding
    {
        return new Finding(
            code: 'exact.relation.new',
            severity: Severity::Info,
            blocking: false,
            path: 'party',
            message: "{$label} staat nog niet in de administratie en wordt bij het boeken automatisch aangemaakt.",
            current: $name,
            suggestion: null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<Finding>
     */
    private function costDimensionFindings(array $payload, Connection $connection): array
    {
        $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
        $findings = [];
        $seen = [];

        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            foreach (self::COST_DIMENSIONS as $field => $dimension) {
                $code = $this->scalarString($line[$field] ?? null);

                if ($code === null) {
                    continue;
                }

                $key = $field.'|'.$code;

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;

                $exists = $this->resolver->refCodeExists($code, $dimension['kind'], $connection);
                $path = "lines.{$index}.{$field}";

                $findings[] = $exists
                    ? new Finding(
                        code: "exact.{$field}.matched",
                        severity: Severity::Info,
                        blocking: false,
                        path: $path,
                        message: "{$dimension['label']} '{$code}' is bekend in de administratie.",
                        current: $code,
                        suggestion: $code,
                    )
                    : new Finding(
                        code: "exact.{$field}.unmapped",
                        severity: Severity::Warning,
                        blocking: true,
                        path: $path,
                        message: "{$dimension['label']} '{$code}' bestaat niet in de administratie — de boeking wordt hierop geweigerd. Corrigeer de {$this->lowerLabel($dimension['label'])} of voeg 'm toe in de administratie; is die net aangemaakt, ververs dan eerst de gegevens.",
                        current: $code,
                        suggestion: null,
                    );
            }
        }

        return $findings;
    }

    private function partyLabel(?string $role): string
    {
        return match ($role) {
            'debtor' => 'Afnemer',
            'creditor' => 'Leverancier',
            default => 'Relatie',
        };
    }

    private function lowerLabel(string $label): string
    {
        return mb_strtolower($label);
    }

    private function rateLabel(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
    }

    private function blank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    private function scalarString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
