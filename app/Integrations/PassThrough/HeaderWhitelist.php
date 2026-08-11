<?php

declare(strict_types=1);

namespace App\Integrations\PassThrough;

use Illuminate\Http\Request;

/**
 * Kopieert alleen de expliciet toegestane headers uit het consumer-request.
 *
 * Whitelist boven blacklist: een nieuwe Hub-header lekt zo niet vanzelf naar een
 * partner. Auth-headers zet elke SDK zelf via de credential-resolver — die mogen
 * nooit uit het inkomende request komen.
 *
 * Wat een provider toestaat verschilt (Snelstart en Exact kennen ETag-conditionals,
 * Mollie niet); de filterlus was drie keer dezelfde. Die staat nu hier, zodat per
 * provider alleen de lijst overblijft — het enige wat er te beslissen valt.
 */
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
