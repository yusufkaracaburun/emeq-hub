<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

use Illuminate\Support\Facades\Context;

final class ConsumerWebhookHeaders
{
    /** @return array<string, string> */
    public static function make(?string $eventId = null): array
    {
        return array_filter([
            'Accept' => 'application/json',
            'X-Emeq-Event-Id' => $eventId,
            'X-Emeq-Request-Id' => Context::get('request_id'),
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');
    }
}
