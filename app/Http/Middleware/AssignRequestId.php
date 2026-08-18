<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

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
