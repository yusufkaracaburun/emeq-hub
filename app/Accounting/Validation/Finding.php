<?php

declare(strict_types=1);

namespace App\Accounting\Validation;

final readonly class Finding
{
    public function __construct(
        public string $code,
        public Severity $severity,
        public bool $blocking,
        public string $path,
        public string $message,
        public mixed $current = null,
        public mixed $suggestion = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity->value,
            'blocking' => $this->blocking,
            'path' => $this->path,
            'message' => $this->message,
            'current' => $this->current,
            'suggestion' => $this->suggestion,
        ];
    }
}
