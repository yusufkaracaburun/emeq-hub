<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Webhooks;

use App\Models\Connection;

/**
 * Dispatcher die Mollie-webhook-payloads op id-prefix naar de juiste
 * resource-type-handler routes (07-CONTEXT.md D-15).
 *
 * Prefix-tabel:
 *  - `sub_`  → SubscriptionWebhookHandler (state-sync via Mollie GET)
 *  - `tr_`   → PaymentWebhookHandler (anti-spoof + optionele
 *              recordPaymentEvent als payment.subscriptionId matched)
 *  - `mdt_`  → no-op skip (Mollie stuurt geen mandate-events vandaag;
 *              gereserveerd voor v0.3+)
 *  - default → PaymentWebhookHandler (Phase 5a-coëxistentie, D-31 invariant)
 */
final class WebhookPayloadRouter
{
    public function __construct(
        private readonly SubscriptionWebhookHandler $subscriptionHandler,
        private readonly PaymentWebhookHandler $paymentHandler,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
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
