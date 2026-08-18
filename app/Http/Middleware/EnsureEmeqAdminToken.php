<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureEmeqAdminToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $consumer = $request->user();
        $allowlist = config('billing.admin_allowlist', []);

        if ($consumer === null || ! is_array($allowlist) || ! in_array($consumer->getKey(), $allowlist, true)) {
            return response()->json([
                'error' => 'not_admin',
                'message' => 'Token hoort niet bij een Emeq-admin-Consumer.',
            ], 403);
        }

        return $next($request);
    }
}
