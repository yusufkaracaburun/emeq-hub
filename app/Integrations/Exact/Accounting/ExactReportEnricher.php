<?php

declare(strict_types=1);

namespace App\Integrations\Exact\Accounting;

use App\Accounting\Enums\TaxTreatment;
use App\Accounting\Validation\Finding;
use App\Accounting\Validation\Severity;
use App\Accounting\Validation\Support\Money;
use App\Integrations\Exact\ExactReferenceData;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use Throwable;

/**
 * Exact-specifieke verrijking van het validate-rapport. Draait ná de provider-agnostische
 * DocumentInspector (gated op een Exact-connection) en voegt findings toe die de consumer
 * vóór de boek-POST laten zien of de boeking gaat slagen: is het BTW-tarief ingericht, staat
 * de relatie al in de administratie, en bestaan de opgegeven kostenplaats/-drager.
 *
 * Findings zijn geschreven voor de eindgebruiker die de factuur goedkeurt, niet voor de
 * integrator: geen VATCode/GUID/mirror-jargon in de tekst, wél de consequentie ("de boeking
 * wordt geweigerd") en de handeling die dat oplost. De machine-leesbare kant zit in `code`,
 * `current` en `suggestion`.
 *
 * Findings krijgen het prefix `exact.` om provider-specifieke enrichment te onderscheiden van
 * de agnostische validators. Muteert niets — een dry-run hoort read-only te zijn.
 */
final class ExactReportEnricher
{
    /**
     * Per regel-veld de mirror-soort + het label voor de finding-tekst. De veldnaam is
     * tevens het finding-code-segment (`exact.cost_center.*`, `exact.cost_unit.*`).
     *
     * @var array<string, array{kind: string, label: string}>
     */
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

            // Een gekoppeld tarief is goed nieuws zonder actie — de interne Exact-VATCode zegt
            // de consument niets, dus geen finding. Alleen een ontbrekende koppeling is actionable.
            if ($this->resolver->vatCodeOrNull($rate, $treatment, $connection) !== null) {
                continue;
            }

            $label = $this->rateLabel($rate).($treatment === TaxTreatment::ReverseCharge ? '% verlegd' : '%');

            $findings[] = new Finding(
                code: 'exact.vat_code.unmapped',
                severity: Severity::Warning,
                path: "lines.{$index}.tax_rate",
                message: "BTW-tarief {$label} is nog niet ingericht voor deze administratie — de boeking wordt hierop geweigerd. Richt het tarief in of gebruik een tarief dat de administratie al kent.",
                current: $line['tax_rate'] ?? null,
                suggestion: null,
            );
        }

        return $findings;
    }

    /**
     * Spiegelt de relatie-resolutie van het schrijfpad (ExactRelationResolver) zodat de dry-run
     * hetzelfde oordeelt als de boeking: eerst de eerder geleerde koppeling op `external_id`,
     * pas daarna een match op BTW-nummer/naam. Zonder die eerste stap meldt validate "nieuw"
     * terwijl het boeken de relatie wél terugvindt.
     *
     * @param  array<string, mixed>  $payload
     * @return list<Finding>
     */
    private function relationFindings(array $payload, Connection $connection): array
    {
        $party = is_array($payload['party'] ?? null) ? $payload['party'] : [];
        $externalId = $this->scalarString($party['external_id'] ?? null);
        $vatNumber = is_string($party['vat_number'] ?? null) ? $party['vat_number'] : null;
        $name = is_string($party['name'] ?? null) ? $party['name'] : null;
        $label = $this->partyLabel(is_string($party['role'] ?? null) ? $party['role'] : null);

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

        if ($this->blank($vatNumber) && $this->blank($name)) {
            return [];
        }

        try {
            $match = (new ExactReferenceData($connection))->findRelation($vatNumber, $name);
        } catch (Throwable) {
            // Een dry-run mag nooit breken op een live Exact-call → geen finding.
            return [];
        }

        if ($match !== null) {
            return [$this->relationMatched($label, $match['name'], $match['id'], $name)];
        }

        return [$this->relationNew($label, $name, $connection)];
    }

    private function relationMatched(string $label, ?string $matchedName, string $nativeId, ?string $current): Finding
    {
        $suffix = $this->blank($matchedName) ? '' : " '{$matchedName}'";

        return new Finding(
            code: 'exact.relation.matched',
            severity: Severity::Info,
            path: 'party',
            message: "{$label} is herkend als bestaande relatie{$suffix} in de administratie — de boeking komt daarop te staan.",
            current: $current,
            suggestion: $nativeId,
        );
    }

    /**
     * De relatie ontbreekt. Of dat erg is hangt aan de koppeling: met automatisch aanmaken aan
     * maakt de boeking 'm zelf (Info), zonder weigert die met een 422 (Warning). Beide gevallen
     * dezelfde `code` — de severity draagt het verschil, zodat een consumer die alleen op codes
     * filtert niet stilzwijgend in de weigering loopt.
     */
    private function relationNew(string $label, ?string $name, Connection $connection): Finding
    {
        $mapping = $connection->metadata['accounting_mapping'] ?? [];
        $autoCreates = is_array($mapping) && ($mapping['auto_create_relations'] ?? false) === true;

        return new Finding(
            code: 'exact.relation.new',
            severity: $autoCreates ? Severity::Info : Severity::Warning,
            path: 'party',
            message: $autoCreates
                ? "{$label} staat nog niet in de administratie en wordt bij het boeken automatisch aangemaakt."
                : "{$label} staat nog niet in de administratie — de boeking wordt geweigerd. Voeg de relatie toe in de administratie, of laat automatisch aanmaken inschakelen op deze koppeling.",
            current: $name,
            suggestion: null,
        );
    }

    /**
     * Kostenplaats/-drager-Codes (cost_center/cost_unit) dragen direct op de boeking en worden
     * tegen de Exact-mirror gevalideerd. Dry-run-spiegel van de boeking: een onbekende Code →
     * `unmapped`-Warning (de boeking zou er met een 422 op weigeren), een bekende → `matched`-Info.
     * Eén finding per distinct (veld, Code).
     *
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
                    continue; // één finding per distinct (veld, Code)
                }

                $seen[$key] = true;

                $exists = $this->resolver->refCodeExists($code, $dimension['kind'], $connection);
                $path = "lines.{$index}.{$field}";

                $findings[] = $exists
                    ? new Finding(
                        code: "exact.{$field}.matched",
                        severity: Severity::Info,
                        path: $path,
                        message: "{$dimension['label']} '{$code}' is bekend in de administratie.",
                        current: $code,
                        suggestion: $code,
                    )
                    : new Finding(
                        code: "exact.{$field}.unmapped",
                        severity: Severity::Warning,
                        path: $path,
                        message: "{$dimension['label']} '{$code}' bestaat niet in de administratie — de boeking wordt hierop geweigerd. Corrigeer de {$this->lowerLabel($dimension['label'])} of voeg 'm toe in de administratie; is die net aangemaakt, ververs dan eerst de gegevens.",
                        current: $code,
                        suggestion: null,
                    );
            }
        }

        return $findings;
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

    /**
     * Een scalar (string/int) regel-veld naar een getrimde non-lege string, anders null.
     */
    private function scalarString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
