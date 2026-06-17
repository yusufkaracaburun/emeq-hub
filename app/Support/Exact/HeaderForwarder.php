<?php

declare(strict_types=1);

namespace App\Support\Exact;

use Emeq\ExactApi\Http\ExactConnector;
use Illuminate\Http\Request;
use Saloon\Http\Response as SaloonResponse;

/**
 * Filtert headers tussen Hub en Exact (whitelist, beide richtingen).
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

    /**
     * Stuurt Exact's X-RateLimit-* quota-headers door naar de consumer zodat die
     * proactief kan throttlen vóór de 429 valt. De allowlist leeft in de SDK
     * (`ExactConnector::RATE_LIMIT_HEADERS`) als single source.
     *
     * @return array<string, string>
     */
    public static function forwardResponse(SaloonResponse $response): array
    {
        $out = [];

        foreach (ExactConnector::RATE_LIMIT_HEADERS as $name) {
            $value = $response->header($name);

            if (is_string($value) && $value !== '') {
                $out[$name] = $value;
            }
        }

        return $out;
    }
}
