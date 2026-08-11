<?php

declare(strict_types=1);

namespace App\Accounting\Read;

/**
 * Ondoorzichtige positie-aanduiding in een resultatenreeks.
 *
 * De inhoud verschilt per bron — voor de mirror is het de laatst geziene code
 * (keyset op de unieke index), voor een live OData-lijst is het Exact's
 * `$skiptoken`. Door 'm te verpakken belooft het contract niets over die vorm, en
 * kan de bron later veranderen zonder dat consumers breken.
 */
final readonly class Cursor
{
    private function __construct(public string $value) {}

    public static function of(string $value): self
    {
        return new self($value);
    }

    /**
     * Een onleesbare cursor is geen fout: hij betekent gewoon "begin opnieuw".
     * Een 400 teruggeven zou een consumer laten vastlopen op een waarde die hij
     * per contract niet mag interpreteren of repareren.
     */
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
