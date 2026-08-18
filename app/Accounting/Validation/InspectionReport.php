<?php

declare(strict_types=1);

namespace App\Accounting\Validation;

/**
 * Uitkomst van een dry-run validatie. `valid` beantwoordt één vraag: gaat de boek-POST
 * hierna lukken? Daarom telt elke blokkerende finding mee, niet alleen de errors —
 * `exact.vat_code.unmapped` draagt severity `warning` maar de boeking strandt er wél op.
 * `severity` blijft zeggen hóe ernstig een bevinding is en kantelt niet mee. De findings
 * zijn de payload — het endpoint antwoordt altijd 200.
 */
final readonly class InspectionReport
{
    /**
     * @param  list<Finding>  $findings
     */
    public function __construct(public array $findings) {}

    public function valid(): bool
    {
        return $this->blocking() === 0;
    }

    /**
     * Geeft een nieuw rapport met extra findings erbij (bv. provider-specifieke
     * enrichment ná de agnostische inspectie). Immutable — de bron blijft ongewijzigd.
     *
     * @param  list<Finding>  $findings
     */
    public function with(array $findings): self
    {
        return new self([...$this->findings, ...$findings]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $sorted = $this->findings;
        usort($sorted, fn (Finding $a, Finding $b): int => $b->severity->weight() <=> $a->severity->weight());

        return [
            'valid' => $this->valid(),
            'summary' => [
                'errors' => $this->count(Severity::Error),
                'warnings' => $this->count(Severity::Warning),
                'infos' => $this->count(Severity::Info),
                'blocking' => $this->blocking(),
            ],
            'findings' => array_map(fn (Finding $f): array => $f->toArray(), $sorted),
        ];
    }

    private function blocking(): int
    {
        return count(array_filter($this->findings, fn (Finding $f): bool => $f->blocking));
    }

    private function count(Severity $severity): int
    {
        return count(array_filter($this->findings, fn (Finding $f): bool => $f->severity === $severity));
    }
}
