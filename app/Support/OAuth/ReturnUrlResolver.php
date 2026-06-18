<?php

declare(strict_types=1);

namespace App\Support\OAuth;

use App\Models\Consumer;

/**
 * Bepaalt waar de eindgebruiker na een OAuth-connect heen mag. Een per-request
 * return_url wordt alleen geaccepteerd als de host gelijk is aan de
 * geregistreerde Consumer.app_url — open-redirect-guard op de publieke signed
 * landing. Zonder geldige return_url valt de flow terug op app_url; ontbreekt
 * die ook, dan null (het admin-pad houdt zo de Hub-fallback).
 */
class ReturnUrlResolver
{
    public function resolve(Consumer $consumer, ?string $requested): ?string
    {
        $appUrl = $consumer->app_url;

        if ($requested !== null && $appUrl !== null && $this->sameHost($requested, $appUrl)) {
            return $requested;
        }

        return $appUrl;
    }

    private function sameHost(string $a, string $b): bool
    {
        $hostA = parse_url($a, PHP_URL_HOST);
        $hostB = parse_url($b, PHP_URL_HOST);

        return is_string($hostA) && is_string($hostB) && strcasecmp($hostA, $hostB) === 0;
    }
}
