<?php

declare(strict_types=1);

namespace App\Integrations\OAuth;

use App\Models\Consumer;

class ReturnUrlResolver
{
    public function resolve(Consumer $consumer, ?string $requested, ?string $origin = null): ?string
    {
        return $this->resolveInternal($consumer, $requested, $origin, fallbackToAppUrl: true);
    }

    public function resolveHandoff(Consumer $consumer, ?string $requested, ?string $origin = null): ?string
    {
        return $this->resolveInternal($consumer, $requested, $origin, fallbackToAppUrl: false);
    }

    private function resolveInternal(
        Consumer $consumer,
        ?string $requested,
        ?string $origin,
        bool $fallbackToAppUrl,
    ): ?string {
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

        return $fallbackToAppUrl ? $appUrl : null;
    }

    /** @var list<string> */
    private const MULTI_PART_SUFFIXES = [
        'co.uk', 'org.uk', 'gov.uk', 'ac.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'net.uk', 'sch.uk',
        'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au', 'id.au',
        'co.nz', 'net.nz', 'org.nz', 'govt.nz',
        'co.za', 'org.za', 'net.za',
        'com.br', 'net.br', 'org.br',
        'co.jp', 'or.jp', 'ne.jp', 'go.jp',
        'co.in', 'net.in', 'org.in', 'gen.in', 'firm.in',
        'com.mx', 'com.ar', 'com.tr', 'com.sg', 'com.hk', 'com.cn',
        'co.kr', 'or.kr',
    ];

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

        if (count($labels) <= 2) {
            return $host;
        }

        $lastTwo = implode('.', array_slice($labels, -2));
        $take = in_array($lastTwo, self::MULTI_PART_SUFFIXES, true) ? 3 : 2;

        return count($labels) <= $take ? $host : implode('.', array_slice($labels, -$take));
    }
}
