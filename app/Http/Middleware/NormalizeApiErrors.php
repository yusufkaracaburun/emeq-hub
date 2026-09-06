<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Integrations\Errors\ErrorCode;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class NormalizeApiErrors
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->is('v1/*') || $response->getStatusCode() < 400) {
            return $response;
        }

        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            $bearer = $request->bearerToken();

            Log::warning('api.auth_rejected', [
                'status' => $status,
                'method' => $request->method(),
                'path' => $request->path(),
                'consumer_id' => $request->user()?->getKey(),
                'token_fingerprint' => $bearer ? substr(hash('sha256', $bearer), 0, 12) : null,
            ]);
        }

        $payload = $this->decode($response);

        if ($payload === null || ($payload !== [] && array_is_list($payload))) {
            return $response;
        }

        $error = $this->errorKey($payload, $status);

        $enveloped = [
            ...$payload,
            'error' => $error,
            'category' => ErrorCode::for($status, $error)->value,
            'retryable' => ErrorCode::retryableFor($status, $error),
            'request_id' => Context::get('request_id'),
        ];

        if ($response instanceof JsonResponse) {
            return $response->setData($enveloped);
        }

        $response->setContent((string) json_encode($enveloped));

        return $response;
    }

    /** @return array<mixed>|null */
    private function decode(Response $response): ?array
    {
        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);

            return is_array($data) ? $data : null;
        }

        if (! $response instanceof HttpResponse) {
            return null;
        }

        if (! str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return null;
        }

        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param  array<string, mixed>  $payload */
    private function errorKey(array $payload, int $status): string
    {
        $existing = $payload['error'] ?? null;

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

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
