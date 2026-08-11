<?php

declare(strict_types=1);

namespace App\Integrations\OAuth;

use App\Models\Consumer;

/**
 * Bepaalt waar de eindgebruiker na een OAuth-connect heen mag. Prioriteit:
 *
 *  1. Een expliciete per-request `return_url` (mag een pad bevatten).
 *  2. De browser-`Origin` van de init-call — die zet de browser automatisch op
 *     de CORS-fetch, dus een multi-tenant consumer-SPA hoeft niets mee te sturen
 *     en landt toch terug op het juiste tenant-subdomein (root).
 *  3. De geregistreerde `Consumer.app_url` (admin/server-initiated fallback) —
 *     alleen via {@see resolve()}; handoff gebruikt {@see resolveHandoff()}.
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
        return $this->resolveInternal($consumer, $requested, $origin, fallbackToAppUrl: true);
    }

    /**
     * Voor de hosted `/connect`-handoff: geen stille terugval op bare `app_url`.
     *
     * Anders landt "Terug naar …" op het marketing-domein wanneer de consumer
     * een lokale of eigen-domein `return_url` meestuurde die de guard weigert.
     * Geen geldige URL → null; de pagina gebruikt dan `document.referrer`.
     */
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

    /**
     * Gangbare meerdelige public suffixes. Bewust kort gehouden i.p.v. een
     * volledige PSL-dependency; breid uit als een consumer op zo'n TLD landt.
     *
     * @var list<string>
     */
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

    /**
     * De host van $requested mag de app_url-host zijn, of een subdomein van
     * hetzelfde registreerbare basisdomein — zo werken multi-tenant consumers
     * (admin.emeq.nl + bob/tbi/… .emeq.nl) zonder per-tenant registratie.
     * Geanchord op een leading dot, dus `emeq.nl.evil.com` matcht niet, en het
     * basisdomein respecteert meerdelige suffixes (`evil.co.uk` matcht niet op
     * een `acme.co.uk`-consumer).
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

        if (count($labels) <= 2) {
            return $host;
        }

        $lastTwo = implode('.', array_slice($labels, -2));
        $take = in_array($lastTwo, self::MULTI_PART_SUFFIXES, true) ? 3 : 2;

        return count($labels) <= $take ? $host : implode('.', array_slice($labels, -$take));
    }
}
