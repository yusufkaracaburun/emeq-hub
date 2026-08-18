<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

final class EnsureProviderEnabled
{
    public function handle(Request $request, Closure $next, string $provider): Response
    {
        if (! Feature::active("provider-{$provider}-enabled")) {
            return response()->json([
                'error' => 'provider_disabled',
                'provider' => $provider,
            ], 503);
        }

        return $next($request);
    }
}
