<?php

declare(strict_types=1);

namespace App\Support\Filament;

use App\Webhooks\InboundWebhookRecorder;

/**
 * Eén bron voor Filament-badge-kleuren van de raw (niet-enum) audit-velden, zodat
 * lijst-tabellen en detail-infolists exact dezelfde kleur tonen voor dezelfde staat.
 *
 * Enum-gedragen velden (provider, subscription-status) dragen hun eigen getColor()
 * via de Filament HasColor-contracten — die horen niet hier maar op de enum.
 */
final class BadgeColor
{
    /**
     * HTTP-statuscode → kleur per klasse (2xx ok, 3xx neutraal, 4xx client-fout,
     * 5xx server-fout).
     */
    public static function httpStatus(int|string|null $status): string
    {
        return match (intdiv((int) $status, 100)) {
            2 => 'success',
            3 => 'gray',
            4 => 'warning',
            5 => 'danger',
            default => 'gray',
        };
    }

    /**
     * Inbound-webhook-outcome → kleur. processed = ok, duplicate = benign,
     * unknown_tenant = aandacht, de overige = fout.
     */
    public static function webhookOutcome(?string $outcome): string
    {
        return match ($outcome) {
            'processed' => 'success',
            'duplicate' => 'gray',
            'unknown_tenant' => 'warning',
            'malformed', 'invalid_signature', 'misconfigured' => 'danger',
            default => 'gray',
        };
    }

    /**
     * Fan-out-status (Hub → consumer-callback) → kleur. Waardenset uit
     * {@see InboundWebhookRecorder}.
     */
    public static function fanoutStatus(?string $status): string
    {
        return match ($status) {
            'dispatched' => 'success',
            'skipped_no_callback' => 'warning',
            'not_applicable' => 'gray',
            default => 'gray',
        };
    }

    /**
     * Pass-through-richting → kleur.
     */
    public static function passThroughDirection(?string $direction): string
    {
        return match ($direction) {
            'inbound' => 'info',
            'outbound' => 'success',
            default => 'gray',
        };
    }

    /**
     * Connection-status → kleur. 'revoked' wordt door de table afgeleid uit
     * revoked_at; active/pending komen rechtstreeks uit de status-kolom.
     */
    public static function connectionStatus(?string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'pending' => 'warning',
            'revoked' => 'danger',
            default => 'gray',
        };
    }

    /**
     * Cashier (Stripe/Mollie) afgeleide subscription-status → kleur. De afleiding
     * zelf (onTrial/onGracePeriod/ended/active) leeft in de Cashier-resource.
     */
    public static function cashierStatus(?string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'trialing' => 'info',
            'grace' => 'warning',
            'cancelled' => 'danger',
            'ended' => 'gray',
            default => 'gray',
        };
    }
}
