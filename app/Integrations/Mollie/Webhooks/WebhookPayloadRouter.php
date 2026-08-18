<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Webhooks;

use App\Models\Connection;

final class WebhookPayloadRouter
{
    public function __construct(
        private readonly SubscriptionWebhookHandler $subscriptionHandler,
        private readonly PaymentWebhookHandler $paymentHandler,
    ) {}

    /** @param  array<string, mixed>  $payload */
    public function routeFor(string $id, array $payload, Connection $connection): WebhookHandlerResult
    {
        return match (true) {
            str_starts_with($id, 'sub_') => $this->subscriptionHandler->handle($id, $payload, $connection),
            str_starts_with($id, 'tr_') => $this->paymentHandler->handle($id, $payload, $connection),
            str_starts_with($id, 'mdt_') => WebhookHandlerResult::skip('mandate_events_not_implemented'),
            default => $this->paymentHandler->handle($id, $payload, $connection),
        };
    }
}
