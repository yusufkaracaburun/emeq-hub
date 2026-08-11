<?php

declare(strict_types=1);

namespace App\Integrations\Snelstart\PassThrough;

use Illuminate\Http\Request;

/**
 * Filtert inkomende Hub-headers naar de set die naar Snelstart mag.
 *
 * Whitelist > blacklist — voorkomt dat toekomstige Hub-headers automatisch
 * naar Snelstart lekken (zie CONTEXT.md §<decisions> ### Header forwarding
 * en threat T-05b-09).
 *
 * De Snelstart-auth-headers worden door de SDK zelf gezet via de
 * credential-resolver; die mogen NIET uit het inkomende Hub-request komen.
 */
final class HeaderForwarder
{
    /**
     * Canonieke casing van de toegestane headers. Vergelijking gebeurt
     * case-insensitive (Laravel's `Request::header()` doet dat al), de output
     * preserveert deze casing zodat de SDK-call deterministisch is.
     *
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
