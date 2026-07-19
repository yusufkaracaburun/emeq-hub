<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetNoIndexHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // De Hub is een backend en blijft standaard noindex. De publieke
        // homepage en /partners-showcase zijn de indexeerbare surfaces.
        if (! $request->routeIs('home', 'partners.*', 'privacy', 'terms')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
        }

        return $response;
    }
}
