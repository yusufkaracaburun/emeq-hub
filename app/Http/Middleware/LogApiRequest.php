<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);

        $response = $next($request);

        if (! $request->is('v1/*')) {
            return $response;
        }

        $body = (string) $request->getContent();
        $content = $response->getContent();

        Log::info('api.request', [
            'method' => $request->method(),
            'path' => $request->path(),
            'query_keys' => implode(',', array_keys($request->query())) ?: null,
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'consumer_id' => $request->user()?->getKey(),
            'request_fingerprint' => $request->isMethodSafe() || $body === '' ? null : substr(hash('sha256', $body), 0, 12),
            'response_size_bytes' => $content === false ? null : strlen($content),
        ]);

        return $response;
    }
}
