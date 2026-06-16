<?php

declare(strict_types=1);

namespace App\Support\Exact;

use Illuminate\Http\Request;

/**
 * Filtert inkomende Hub-headers naar de set die naar Exact mag (whitelist).
 * De Authorization-header wordt door de SDK zelf gezet via de OAuthAuthenticator;
 * die mag NIET uit het inkomende Hub-request komen.
 */
final class HeaderForwarder
{
    /**
     * @var list<string>
     */
    private const ALLOWED = ['Accept', 'Content-Type', 'If-Match', 'If-None-Match'];

    /**
     * @return array<string, string>
     */
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
