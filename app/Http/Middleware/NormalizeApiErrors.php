<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Errors\ErrorCode;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Geeft elke `/v1/*`-fout dezelfde envelope, zonder de ~50 plekken aan te raken die
 * er één produceren.
 *
 * Additief: de bestaande `error`-sleutel blijft exact zoals hij was, want consumers
 * lezen die. Erbij komen `category` (de provider-onafhankelijke klasse fout) en
 * `request_id` (zodat een supportvraag met één waarde te herleiden is).
 *
 * Twee gaten die het onderweg dicht:
 * - `abort_unless(..., 403, 'insufficient_ability')` levert Laravel-breed een kale
 *   `{message}` zonder `error`. Een consumer die op `error` leest kreeg daar niets.
 * - Framework-fouten (validatie, 404, throttle) hadden helemaal geen `error`.
 *
 * Draait buitenom in de globale stack, dus ná het renderen van een exception: de
 * pipeline zet die om in een response vóórdat hij hier langskomt.
 */
class NormalizeApiErrors
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->is('v1/*') || ! $response instanceof JsonResponse) {
            return $response;
        }

        $status = $response->getStatusCode();

        if ($status < 400) {
            return $response;
        }

        $payload = $response->getData(true);

        if (! is_array($payload)) {
            return $response;
        }

        $error = $this->errorKey($payload, $status);

        return $response->setData([
            ...$payload,
            'error' => $error,
            'category' => ErrorCode::for($status, $error)->value,
            'request_id' => Context::get('request_id'),
        ]);
    }

    /**
     * De bestaande code wint altijd. Ontbreekt hij, dan is `message` vaak al een
     * snake_case-code (dat is wat `abort_unless` ervan maakt); anders vallen we terug
     * op een generieke naam die uit de status volgt.
     *
     * @param  array<string, mixed>  $payload
     */
    private function errorKey(array $payload, int $status): string
    {
        $existing = $payload['error'] ?? null;

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        // Legacy: `code` was alleen in gebruik voor de 401.
        $code = $payload['code'] ?? null;

        if (is_string($code) && $code !== '') {
            return $code;
        }

        $message = $payload['message'] ?? null;

        if (is_string($message) && preg_match('/^[a-z][a-z0-9_]{2,63}$/', $message) === 1) {
            return $message;
        }

        return mb_strtolower(ErrorCode::for($status)->value);
    }
}
