<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\PassThrough;

use App\Integrations\PassThrough\HeaderWhitelist;
use Illuminate\Http\Request;

final class MollieHeaderForwarder
{
    /** @var list<string> */
    private const ALLOWED = ['Accept', 'Content-Type'];

    /** @return array<string, string> */
    public static function forward(Request $request): array
    {
        return HeaderWhitelist::filter($request, self::ALLOWED);
    }
}
