<?php

declare(strict_types=1);

namespace App\Support\Filament;

final class BadgeColor
{
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

    public static function fanoutStatus(?string $status): string
    {
        return match ($status) {
            'dispatched' => 'success',
            'skipped_no_callback' => 'warning',
            'not_applicable' => 'gray',
            default => 'gray',
        };
    }

    public static function passThroughDirection(?string $direction): string
    {
        return match ($direction) {
            'inbound' => 'info',
            'outbound' => 'success',
            default => 'gray',
        };
    }

    public static function requestStatus(?string $status): string
    {
        return match ($status) {
            'handled' => 'success',
            'declined' => 'gray',
            default => 'warning',
        };
    }

    public static function connectionStatus(?string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'pending' => 'warning',
            'revoked' => 'danger',
            default => 'gray',
        };
    }

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
