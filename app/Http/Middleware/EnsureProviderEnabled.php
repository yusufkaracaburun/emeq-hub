<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kill-switch-middleware per partner-provider. Geeft 503 zonder upstream-call
 * als `provider-<provider>-enabled` op false staat.
 *
 * Gebruik via route alias: `->middleware('feature.provider:mollie')`.
 *
 * 503 ipv 4xx omdat een uitgeschakelde provider geen Consumer-fout is — het is
 * een tijdelijk Hub-side besluit (kill-switch bij partner-outage of rollout-gate).
 */
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
