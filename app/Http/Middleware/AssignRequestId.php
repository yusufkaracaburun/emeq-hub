<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Eén correlatie-id per request, van consumer-request tot consumer-webhook.
 *
 * De id landt in `Context`, en daarmee automatisch in elke logregel
 * (`ContextLogProcessor`) én in elke queued job — het framework dehydrateert
 * `Context` de job-payload in en hydrateert 'm terug op `JobProcessing`. Jobs
 * hoeven dus geen `$requestId`-property te dragen.
 *
 * Draait als eerste in de globale stack, dus vóór auth en throttle: juist een
 * geweigerd of gethrottled request wil je tijdens een incident kunnen terugvinden.
 *
 * Octane: `Context` is een `scoped()` binding die door
 * `FlushTemporaryContainerInstances` per request gewist wordt. Geen statics hier.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    /**
     * Alfanumeriek plus `-` en `_`, 8–64 tekens. De waarde wordt teruggekaatst naar
     * de client en in logs geschreven, dus CR/LF en onbegrensde lengte worden hier
     * geweigerd in plaats van verderop opgeruimd.
     */
    private const SHAPE = '/^[A-Za-z0-9_-]{8,64}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $inbound = (string) $request->headers->get(self::HEADER, '');

        $id = preg_match(self::SHAPE, $inbound) === 1
            ? $inbound
            : (string) Str::ulid();

        Context::add('request_id', $id);
        $request->headers->set(self::HEADER, $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
