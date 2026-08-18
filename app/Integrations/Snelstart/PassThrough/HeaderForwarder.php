<?php

declare(strict_types=1);

namespace App\Integrations\Snelstart\PassThrough;

use App\Integrations\PassThrough\HeaderWhitelist;
use Illuminate\Http\Request;

final class HeaderForwarder
{
    /** @var list<string> */
    private const ALLOWED = ['Accept', 'Content-Type', 'If-Match', 'If-None-Match'];

    /** @return array<string, string> */
    public static function forward(Request $request): array
    {
        return HeaderWhitelist::filter($request, self::ALLOWED);
    }
}
