<?php

declare(strict_types=1);

namespace App\Support\Mollie;

use Illuminate\Http\Request;

/**
 * Whitelist headers die we naar de Mollie-SDK forwarden. Mollie kent geen
 * ETag/If-Match-pad (in tegenstelling tot Snelstart), dus de whitelist is
 * beperkter. Idempotency-Key gaat NIET via deze forwarder — die wordt
 * via SDK-config gepropageerd (zie 05a-CONTEXT.md §<decisions> D-06).
 */
final class MollieHeaderForwarder
{
    /** @var list<string> */
    private const ALLOWED = ['Accept', 'Content-Type'];

    /** @return array<string, string> */
    public static function forward(Request $request): array
    {
        $out = [];

        foreach (self::ALLOWED as $name) {
            $value = $request->header($name);

            if (is_string($value) && $value !== '') {
                $out[$name] = $value;
            }
        }

        return $out;
    }
}
