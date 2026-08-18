<?php

namespace App\Http\Middleware;

use App\Support\PublicPages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetNoIndexHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $indexable = app()->isProduction() && $request->routeIs(...PublicPages::INDEXABLE_ROUTES);

        if (! $indexable) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
        }

        return $response;
    }
}
