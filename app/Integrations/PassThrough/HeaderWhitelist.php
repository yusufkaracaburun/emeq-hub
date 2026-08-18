<?php

declare(strict_types=1);

namespace App\Integrations\PassThrough;

use Illuminate\Http\Request;

final class HeaderWhitelist
{
    /**
     * @param  list<string>  $allowed  canonieke casing; de vergelijking is
     *                                 case-insensitive, de output houdt deze
     *                                 schrijfwijze zodat de SDK-call deterministisch is
     * @return array<string, string>
     */
    public static function filter(Request $request, array $allowed): array
    {
        $out = [];

        foreach ($allowed as $name) {
            $value = $request->header($name);

            if (is_string($value) && $value !== '') {
                $out[$name] = $value;
            }
        }

        return $out;
    }
}
