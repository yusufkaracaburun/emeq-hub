<?php

declare(strict_types=1);

namespace App\Accounting\Validation;

/**
 * Uitkomst van een dry-run validatie. `valid` is false zodra er één error-finding is
 * (= zou een foute/illegale boeking opleveren); warnings/infos blokkeren niet maar
 * vragen aandacht. De findings zijn de payload — het endpoint antwoordt altijd 200.
 */
final readonly class InspectionReport
{
    /**
     * @param  list<Finding>  $findings
     */
    public function __construct(public array $findings) {}

    public function valid(): bool
    {
        return $this->count(Severity::Error) === 0;
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
            ],
            'findings' => array_map(fn (Finding $f): array => $f->toArray(), $sorted),
        ];
    }

    private function count(Severity $severity): int
    {
        return count(array_filter($this->findings, fn (Finding $f): bool => $f->severity === $severity));
    }
}
