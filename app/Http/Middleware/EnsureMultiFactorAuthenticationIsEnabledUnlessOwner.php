<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * `isRequired` op `multiFactorAuthentication()` wordt eenmalig geëvalueerd bij
 * route-caching (geen live auth()->user() op dat moment), dus een per-user
 * uitzondering kan daar niet. Dit middleware draait wél per-request.
 */
class EnsureMultiFactorAuthenticationIsEnabledUnlessOwner extends EnsureMultiFactorAuthenticationIsEnabled
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (Filament::auth()->user()?->email === 'info@emeq.nl') {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
