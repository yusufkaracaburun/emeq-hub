<?php

declare(strict_types=1);

namespace App\Integrations\Exact\PassThrough;

use App\Integrations\PassThrough\HeaderWhitelist;
use Emeq\ExactApi\Http\ExactConnector;
use Illuminate\Http\Request;
use Saloon\Http\Response as SaloonResponse;

final class HeaderForwarder
{
    /** @var list<string> */
    private const ALLOWED = ['Accept', 'Content-Type', 'If-Match', 'If-None-Match'];

    /** @return array<string, string> */
    public static function forward(Request $request): array
    {
        return HeaderWhitelist::filter($request, self::ALLOWED);
    }

    /** @return array<string, string> */
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
