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

        // De Hub is een backend en blijft standaard noindex; alleen de publieke
        // marketing-surface is indexeerbaar (PublicPages = enige bron, gedeeld
        // met robots.txt en de sitemap). Buiten productie staat alles dicht —
        // de dev-tunnel draait op een publiek bereikbaar domein.
        $indexable = app()->isProduction() && $request->routeIs(...PublicPages::INDEXABLE_ROUTES);

        if (! $indexable) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
        }

        return $response;
    }
}
