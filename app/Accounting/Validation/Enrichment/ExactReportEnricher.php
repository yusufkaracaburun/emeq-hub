<?php

declare(strict_types=1);

namespace App\Accounting\Validation\Enrichment;

use App\Accounting\Enums\TaxTreatment;
use App\Accounting\Exact\ConnectionMappingExactReferenceResolver;
use App\Accounting\Validation\Finding;
use App\Accounting\Validation\Severity;
use App\Accounting\Validation\Support\Money;
use App\Models\Connection;
use App\Services\Exact\ExactReferenceData;
use Throwable;

/**
 * Exact-specifieke verrijking van het validate-rapport. Draait ná de provider-agnostische
 * DocumentInspector (gated op een Exact-connection) en voegt findings toe die de consumer
 * vóór de boek-POST laten zien of de mapping compleet is: per BTW-tarief de concrete
 * Exact-VATCode (of "nog niet gekoppeld"), en of de leverancier al als Exact-relatie bestaat
 * of nieuw is.
 *
 * Findings krijgen het prefix `exact.` om provider-specifieke enrichment te onderscheiden van
 * de agnostische validators. Muteert niets — een dry-run hoort read-only te zijn.
 */
final class ExactReportEnricher
{
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
        ];
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
                continue; // ongeldige tax_rate flaggen de agnostische validators al
            }

            $treatment = TaxTreatment::tryFrom((string) ($line['tax_treatment'] ?? '')) ?? TaxTreatment::Standard;
            $key = $treatment->value.'|'.number_format($rate, 2, '.', '');

            if (isset($seen[$key])) {
                continue; // één finding per distinct (behandeling, tarief)
            }

            $seen[$key] = true;

            $code = $this->resolver->vatCodeOrNull($rate, $treatment, $connection);
            $label = $this->rateLabel($rate).($treatment === TaxTreatment::ReverseCharge ? '% verlegd' : '%');
            $path = "lines.{$index}.tax_rate";

            $findings[] = $code !== null
                ? new Finding(
                    code: 'exact.vat_code.matched',
                    severity: Severity::Info,
                    path: $path,
                    message: "Tarief {$label} komt overeen met Exact-VATCode '{$code}'.",
                    current: $line['tax_rate'] ?? null,
                    suggestion: $code,
                )
                : new Finding(
                    code: 'exact.vat_code.unmapped',
                    severity: Severity::Warning,
                    path: $path,
                    message: "Tarief {$label} is nog niet gekoppeld aan een Exact-VATCode op deze koppeling.",
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
        $vatNumber = is_string($party['vat_number'] ?? null) ? $party['vat_number'] : null;
        $name = is_string($party['name'] ?? null) ? $party['name'] : null;
        $role = is_string($party['role'] ?? null) ? $party['role'] : null;

        if ($this->blank($vatNumber) && $this->blank($name)) {
            return [];
        }

        try {
            $match = (new ExactReferenceData($connection))->findRelation($vatNumber, $name);
        } catch (Throwable) {
            // Een dry-run mag nooit breken op een live Exact-call → geen finding.
            return [];
        }

        $label = $this->partyLabel($role);

        if ($match !== null) {
            return [new Finding(
                code: 'exact.relation.matched',
                severity: Severity::Info,
                path: 'party',
                message: "{$label} gevonden als bestaande Exact-relatie '{$match['name']}'.",
                current: $name,
                suggestion: $match['id'],
            )];
        }

        return [new Finding(
            code: 'exact.relation.new',
            severity: Severity::Info,
            path: 'party',
            message: "{$label} nog niet als eenduidige Exact-relatie gevonden — wordt bij het boeken als nieuw behandeld.",
            current: $name,
            suggestion: null,
        )];
    }

    /**
     * Afnemer (debtor) of leverancier (creditor) — de canonical party-rol bepaalt het
     * woord; onbekende/ontbrekende rol valt terug op het neutrale "Relatie".
     */
    private function partyLabel(?string $role): string
    {
        return match ($role) {
            'debtor' => 'Afnemer',
            'creditor' => 'Leverancier',
            default => 'Relatie',
        };
    }

    private function rateLabel(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
    }

    private function blank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
