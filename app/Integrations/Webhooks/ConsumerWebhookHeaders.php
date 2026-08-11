<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

use Illuminate\Support\Facades\Context;

/**
 * Correlatie-headers op elke outbound consumer-webhook. Eén bron, zodat een
 * nieuwe fan-out-job niet stilletjes zonder correlatie de deur uit gaat.
 *
 * Bewust niet via `config('webhook-server.headers')`: dat is procesbreed en
 * statisch, dus een per-request-waarde daarin lekt onder Octane naar het
 * volgende request.
 */
final class ConsumerWebhookHeaders
{
    /**
     * @return array<string, string>
     */
    public static function make(?string $eventId = null): array
    {
        return array_filter([
            'X-Emeq-Event-Id' => $eventId,
            'X-Emeq-Request-Id' => Context::get('request_id'),
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');
    }
}
