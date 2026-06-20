<?php

declare(strict_types=1);

namespace App\Support\OAuth;

use App\Models\Consumer;

/**
 * Bepaalt waar de eindgebruiker na een OAuth-connect heen mag. Een per-request
 * return_url wordt alleen geaccepteerd als de host de geregistreerde
 * Consumer.app_url-host is óf een subdomein van hetzelfde basisdomein —
 * open-redirect-guard op de publieke signed landing. Zonder geldige return_url
 * valt de flow terug op app_url; ontbreekt die ook, dan null (het admin-pad
 * houdt zo de Hub-fallback).
 */
class ReturnUrlResolver
{
    public function resolve(Consumer $consumer, ?string $requested): ?string
    {
        $appUrl = $consumer->app_url;

        if ($requested !== null && $appUrl !== null && $this->hostAllowed($requested, $appUrl)) {
            return $requested;
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
