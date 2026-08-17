<?php

declare(strict_types=1);

namespace App\Accounting\Validation\Validators;

use App\Accounting\Party;
use App\Accounting\Validation\Contracts\DocumentValidator;
use App\Accounting\Validation\Finding;
use App\Accounting\Validation\Severity;

/**
 * Controleert of de draft de velden draagt die het boekpad hoe dan ook eist. De andere
 * validators oordelen over de inhoud (klopt dit btw-nummer, klopt deze optelsom); deze
 * kijkt of er überhaupt iets te boeken valt.
 *
 * Zonder deze stap gaf de dry-run `valid: true` op een leeg document: `/validate` laat
 * per-veldproblemen bewust door de edge-validatie heen omdat het vinden ervan hier hoort,
 * en hier keek niemand. Een consumer die de dry-run als "boekt dit?" leest, kreeg groen
 * voor een payload die het boeken met een 422 weigert.
 *
 * Spiegel van StoreDocumentRequest, maar met het onderscheid dat een draft verdient:
 *
 * - **Error** — een waarde die er staat maar niet kan kloppen (onbekend documentsoort,
 *   onbekende rol, onleesbare regel), of een leegte die het document zinloos maakt (geen
 *   soort, geen tegenpartij, geen regels). Dit boekt nooit.
 * - **Warning** — een veld dat de consumer bij het boeken nog invult en dat een verse OCR-
 *   draft legitiem mist (`external_id`, `issue_date`, de rol van de tegenpartij). Blokkeert
 *   de dry-run niet, maar het boeken weigert erop, net als bij `exact.relation.new`.
 *
 * Alleen aanwezigheid en de twee gesloten waardenlijsten — vorm en inhoud zijn andermans werk.
 */
final class CompletenessValidator implements DocumentValidator
{
    private const TYPES = ['sales_invoice', 'purchase_invoice', 'credit_note', 'income', 'expense'];

    private const ROLES = ['debtor', 'creditor'];

    public function validate(array $payload): array
    {
        return [
            ...$this->typeFindings($payload),
            // Niet-blocking: de consumer vult external_id/issue_date/party.role nog in
            // tijdens het boeken, ook al zegt de tekst "de boeking wordt geweigerd".
            ...$this->missing($payload, 'external_id', 'document.external_id.missing',
                'Het kenmerk waarmee jij deze factuur zelf kent ontbreekt. De boeking wordt hierop geweigerd: de boekhouding heeft het nodig om de boeking terug te vinden, en het voorkomt dat een tweede poging hetzelfde document nog een keer boekt.',
                blocking: false),
            ...$this->missing($payload, 'issue_date', 'document.issue_date.missing',
                'De factuurdatum ontbreekt. De boeking wordt hierop geweigerd — de datum bepaalt in welke periode de boeking valt.',
                blocking: false),
            ...$this->partyFindings($payload),
            ...$this->lineFindings($payload),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<Finding>
     */
    private function typeFindings(array $payload): array
    {
        $type = $payload['type'] ?? null;

        if ($this->blank($type)) {
            return [$this->error(
                'document.type.missing',
                'type',
                'Het documentsoort ontbreekt. Zonder soort weet de boekhouding niet of dit een verkoopfactuur, inkoopfactuur, creditnota, inkomst of uitgave is.',
                suggestion: $this->list(self::TYPES),
            )];
        }

        if (! in_array($type, self::TYPES, true)) {
            return [$this->error(
                'document.type.unknown',
                'type',
                'Dit documentsoort kent de boekhouding niet. De boeking wordt hierop geweigerd.',
                current: $type,
                suggestion: $this->list(self::TYPES),
            )];
        }

        return [];
    }

    /**
     * Ontbreekt de tegenpartij helemaal, dan is dat één bevinding — niet drie over
     * velden binnen iets dat er niet is.
     *
     * @param  array<string, mixed>  $payload
     * @return list<Finding>
     */
    private function partyFindings(array $payload): array
    {
        $party = $payload['party'] ?? null;

        if (! is_array($party) || $party === []) {
            return [$this->error(
                'document.party.missing',
                'party',
                'De tegenpartij ontbreekt. Een boeking staat altijd op een klant of leverancier.',
            )];
        }

        $findings = [];
        $role = $party['role'] ?? null;

        if ($this->blank($role)) {
            // Niet-blocking: de rol wordt bij het boeken nog ingevuld, ondanks de tekst.
            $findings[] = $this->finding(
                'document.party.role.missing',
                Severity::Warning,
                blocking: false,
                path: 'party.role',
                message: 'Onbekend of de tegenpartij een klant of een leverancier is. Dat bepaalt op welke kant van de boekhouding de boeking komt; zonder rol wordt de boeking geweigerd.',
                suggestion: $this->list(self::ROLES),
            );
        } elseif (! in_array($role, self::ROLES, true)) {
            $findings[] = $this->error(
                'document.party.role.unknown',
                'party.role',
                'Deze rol kent de boekhouding niet. Een tegenpartij is een klant (`debtor`) of een leverancier (`creditor`).',
                current: $role,
                suggestion: $this->list(self::ROLES),
            );
        }

        if ($this->blank($party['name'] ?? null)) {
            $findings[] = $this->error(
                'document.party.name.missing',
                'party.name',
                'De naam van de tegenpartij ontbreekt. Zonder naam is de relatie in de administratie niet terug te vinden.',
            );
        }

        $kind = $party['kind'] ?? null;

        if ($this->blank($kind)) {
            // Niet-blocking, net als role/external_id hierboven: een OCR-draft kent het
            // onderscheid bedrijf/particulier nog niet. De ladder eist dit pas bij het boeken.
            $findings[] = $this->finding(
                'document.party.kind.missing',
                Severity::Warning,
                blocking: false,
                path: 'party.kind',
                message: 'Onbekend of de tegenpartij een bedrijf of een particulier is. Dat bepaalt hoe de boeking de relatie herkent; zonder kind wordt de boeking geweigerd.',
                suggestion: $this->list([Party::KIND_COMPANY, Party::KIND_PERSON]),
            );
        } elseif ($kind === Party::KIND_COMPANY && $this->blank($party['chamber_of_commerce'] ?? null) && $this->blank($party['vat_number'] ?? null)) {
            $findings[] = $this->finding(
                'document.party.identifier.missing',
                Severity::Warning,
                blocking: false,
                path: 'party.chamber_of_commerce',
                message: 'Een zakelijke tegenpartij zonder KvK- of btw-nummer kan de boekhouding niet met zekerheid herkennen; de boeking wordt hierop geweigerd.',
            );
        }

        if ($this->blank($party['external_id'] ?? null)) {
            $findings[] = $this->finding(
                'document.party.external_id.missing',
                Severity::Warning,
                blocking: false,
                path: 'party.external_id',
                message: 'Het kenmerk waarmee jij deze tegenpartij zelf kent ontbreekt. De boeking wordt hierop geweigerd: zonder deze sleutel kan de boekhouding een eerder herkende relatie niet hergebruiken.',
            );
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<Finding>
     */
    private function lineFindings(array $payload): array
    {
        $lines = $payload['lines'] ?? null;

        if (! is_array($lines) || $lines === []) {
            return [$this->error(
                'document.lines.missing',
                'lines',
                'Het document heeft geen regels. Er valt zo niets te boeken — voeg minstens één regel toe met een omschrijving, een bedrag en een btw-tarief.',
            )];
        }

        $findings = [];

        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                $findings[] = $this->error(
                    'document.line.invalid',
                    "lines.{$index}",
                    'Deze regel is niet te lezen. Een regel bestaat uit een omschrijving, een bedrag en een btw-tarief.',
                    current: $line,
                );

                continue;
            }

            if ($this->blank($line['description'] ?? null)) {
                $findings[] = $this->error(
                    'document.line.description.missing',
                    "lines.{$index}.description",
                    'Deze regel heeft geen omschrijving. Die komt op de boeking te staan.',
                );
            }

            if ($this->blank($line['amount'] ?? null)) {
                $findings[] = $this->error(
                    'document.line.amount.missing',
                    "lines.{$index}.amount",
                    'Deze regel heeft geen bedrag. Vul het bedrag exclusief btw in.',
                );
            }

            if ($this->blank($line['tax_rate'] ?? null)) {
                $findings[] = $this->error(
                    'document.line.tax_rate.missing',
                    "lines.{$index}.tax_rate",
                    'Deze regel heeft geen btw-tarief. Vul het percentage in (0, 9 of 21); bij 0% hoort dat er expliciet te staan.',
                );
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<Finding>
     */
    private function missing(array $payload, string $key, string $code, string $message, bool $blocking): array
    {
        return $this->blank($payload[$key] ?? null)
            ? [$this->finding($code, Severity::Warning, $blocking, $key, $message)]
            : [];
    }

    /**
     * `0` en `0.0` zijn geldige bedragen en tarieven, dus leeg is hier strikt: null,
     * lege string, of een array/object waar een waarde hoort.
     */
    private function blank(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) === '';
        }

        return $value === null || is_array($value);
    }

    /**
     * @param  list<string>  $values
     */
    private function list(array $values): string
    {
        return implode(', ', $values);
    }

    /**
     * Elke error is per definitie blocking (zie Finding-docblock) — geen aparte
     * $blocking-parameter nodig aan deze aanroepplekken.
     */
    private function error(string $code, string $path, string $message, mixed $current = null, mixed $suggestion = null): Finding
    {
        return $this->finding($code, Severity::Error, blocking: true, path: $path, message: $message, current: $current, suggestion: $suggestion);
    }

    private function finding(string $code, Severity $severity, bool $blocking, string $path, string $message, mixed $current = null, mixed $suggestion = null): Finding
    {
        return new Finding(
            code: $code,
            severity: $severity,
            blocking: $blocking,
            path: $path,
            message: $message,
            current: $current,
            suggestion: $suggestion,
        );
    }
}
