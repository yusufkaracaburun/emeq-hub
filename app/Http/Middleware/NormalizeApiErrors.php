<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Integrations\Errors\ErrorCode;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
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

        if (! $request->is('v1/*') || $response->getStatusCode() < 400) {
            return $response;
        }

        $status = $response->getStatusCode();
        $payload = $this->decode($response);

        // Alleen een JSON-object krijgt de envelope. Een gevulde lijst zou door de
        // spread in een object veranderen (`{"0":…}`) en dus van vorm wisselen; een
        // niet-JSON body (HTML-foutpagina, bestand, stream) laten we met rust.
        if ($payload === null || ($payload !== [] && array_is_list($payload))) {
            return $response;
        }

        $error = $this->errorKey($payload, $status);

        $enveloped = [
            ...$payload,
            'error' => $error,
            'category' => ErrorCode::for($status, $error)->value,
            'request_id' => Context::get('request_id'),
        ];

        if ($response instanceof JsonResponse) {
            return $response->setData($enveloped);
        }

        // De pass-through-controllers geven `response($body, $status)` terug — een
        // gewone Response met een JSON content-type, geen JsonResponse. Dat is het
        // merendeel van het foutverkeer; die overslaan zou de envelope grotendeels
        // leeg laten lopen.
        $response->setContent((string) json_encode($enveloped));

        return $response;
    }

    /**
     * De body als array, of null wanneer dit geen JSON is dat we mogen aanraken.
     *
     * @return array<mixed>|null
     */
    private function decode(Response $response): ?array
    {
        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);

            return is_array($data) ? $data : null;
        }

        // Streamed of binary: `getContent()` is dan onbetrouwbaar of leeg.
        if (! $response instanceof HttpResponse) {
            return null;
        }

        if (! str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return null;
        }

        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded) ? $decoded : null;
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
