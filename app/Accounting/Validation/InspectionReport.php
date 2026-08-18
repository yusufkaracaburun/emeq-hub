<?php

declare(strict_types=1);

namespace App\Accounting\Validation;

final readonly class InspectionReport
{
    /** @param  list<Finding>  $findings */
    public function __construct(public array $findings) {}

    public function valid(): bool
    {
        return $this->blocking() === 0;
    }

    /** @param  list<Finding>  $findings */
    public function with(array $findings): self
    {
        return new self([...$this->findings, ...$findings]);
    }

    /** @return array<string, mixed> */
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
