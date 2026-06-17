<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hub-brede idempotentie voor write-requests. Consumer-scoped: een tweede request met
 * dezelfde Idempotency-Key herhaalt de eerste succesvolle respons in plaats van de
 * handler opnieuw uit te voeren — voorkomt dubbele boekingen/aanmaak bij retries. Eén
 * alias, herbruikbaar op elke write-route (accounting, pass-through, toekomstige partners);
 * geen partner-specifieke duplicatie.
 *
 * Modus `required` → ontbrekende key geeft 400; default → dedupliceert alleen als een key
 * meekomt. Alleen 2xx-responses worden bewaard, zodat een mislukte poging opnieuw mag.
 */
class EnsureIdempotency
{
    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        $key = $request->header('Idempotency-Key');
        $consumerId = $request->user()?->getKey();

        if (! is_string($key) || $key === '') {
            if ($mode === 'required') {
                return response()->json([
                    'error' => 'idempotency_key_required',
                    'message' => 'Vereiste header Idempotency-Key ontbreekt.',
                ], 400);
            }

            return $next($request);
        }

        $existing = IdempotencyKey::query()
            ->where('consumer_id', $consumerId)
            ->where('key', $key)
            ->first();

        if ($existing !== null) {
            return response($existing->response_body, $existing->response_status)
                ->header('Content-Type', $existing->content_type ?? 'application/json');
        }

        $response = $next($request);
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            IdempotencyKey::create([
                'consumer_id' => $consumerId,
                'key' => $key,
                'method' => $request->method(),
                'path' => $request->path(),
                'response_status' => $status,
                'content_type' => $response->headers->get('Content-Type'),
                'response_body' => $response->getContent(),
                'created_at' => now(),
            ]);
        }

        return $response;
    }
}
