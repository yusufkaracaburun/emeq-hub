<?php

declare(strict_types=1);

namespace App\Support\OAuth;

use App\Models\Consumer;

/**
 * Bepaalt waar de eindgebruiker na een OAuth-connect heen mag. Prioriteit:
 *
 *  1. Een expliciete per-request `return_url` (mag een pad bevatten).
 *  2. De browser-`Origin` van de init-call — die zet de browser automatisch op
 *     de CORS-fetch, dus een multi-tenant consumer-SPA hoeft niets mee te sturen
 *     en landt toch terug op het juiste tenant-subdomein (root).
 *  3. De geregistreerde `Consumer.app_url` (admin/server-initiated fallback).
 *
 * (1) en (2) worden alleen geaccepteerd als de host de app_url-host is óf een
 * subdomein van hetzelfde basisdomein — open-redirect-guard op de publieke
 * signed landing. Ontbreekt app_url, dan null (het admin-pad houdt zo de
 * Hub-fallback).
 */
class ReturnUrlResolver
{
    public function resolve(Consumer $consumer, ?string $requested, ?string $origin = null): ?string
    {
        $appUrl = $consumer->app_url;

        if ($appUrl === null) {
            return null;
        }

        if ($requested !== null && $this->hostAllowed($requested, $appUrl)) {
            return $requested;
        }

        if ($origin !== null && $this->hostAllowed($origin, $appUrl)) {
            return $origin;
        }

        return $appUrl;
    }

    /**
     * De host van $requested mag de app_url-host zijn, of een subdomein van
     * hetzelfde basisdomein (laatste twee labels) — zo werken multi-tenant
     * consumers (admin.emeq.nl + bob/tbi/… .emeq.nl) zonder per-tenant
     * registratie. Geanchord op een leading dot, dus `emeq.nl.evil.com` matcht
     * niet. Caveat: basisdomein = laatste 2 labels, geen public-suffix-lijst
     * (co.uk); de consumers draaien op enkelvoudige .nl/.com-domeinen.
     */
    private function hostAllowed(string $requested, string $appUrl): bool
    {
        $requestedHost = parse_url($requested, PHP_URL_HOST);
        $appHost = parse_url($appUrl, PHP_URL_HOST);

        if (! is_string($requestedHost) || ! is_string($appHost)) {
            return false;
        }

        $requestedHost = strtolower($requestedHost);
        $appHost = strtolower($appHost);

        if ($requestedHost === $appHost) {
            return true;
        }

        $base = $this->baseDomain($appHost);

        return $requestedHost === $base || str_ends_with($requestedHost, '.'.$base);
    }

    private function baseDomain(string $host): string
    {
        $labels = explode('.', $host);

        return count($labels) <= 2 ? $host : implode('.', array_slice($labels, -2));
    }
}
