<?php

declare(strict_types=1);

namespace App\Accounting\Read;

final readonly class Cursor
{
    private function __construct(public string $value) {}

    public static function of(string $value): self
    {
        return new self($value);
    }

    public static function decode(string $encoded): ?self
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $decoded === false || $decoded === '' ? null : new self($decoded);
    }

    public function encode(): string
    {
        return rtrim(strtr(base64_encode($this->value), '+/', '-_'), '=');
    }
}
