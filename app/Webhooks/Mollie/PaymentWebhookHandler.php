<?php

declare(strict_types=1);

namespace App\Webhooks\Mollie;

use App\Billing\Account\AccountSubscriptionManager;
use App\Models\AccountSubscription;
use App\Models\Connection;
use App\Mollie\MollieConnectionContext;
use Emeq\MollieApi\Facades\Mollie;
use Throwable;

/**
 * Webhook-handler voor Mollie Payment-id's (`tr_*`-prefix) en de default-pad
 * voor onbekende prefixes (07-CONTEXT.md D-15 prefix-tabel).
 *
 * Flow:
 *  1. Bind MollieConnectionContext zodat HubMollieCredentialResolver de juiste
 *     access_token gebruikt.
 *  2. Mollie GET op het Payment-id — dit is de anti-spoofing-check uit
 *     Phase 5a's flow. Faalt de GET (404/auth/timeout) → resultaat
 *     `antiSpoofFailed` zodat de controller fan-out blokkeert (D-31 invariant
 *     + `MollieWebhookAntiSpoofingTest`).
 *  3. Heeft de Payment een `subscriptionId`? Match op
 *     `connection_id + mollie_subscription_id` en delegeer naar
 *     `AccountSubscriptionManager::recordPaymentEvent` (D-16, SC-2).
 *  4. Geen matching `AccountSubscription` of geen `subscriptionId` → return
 *     `ok()` zodat de bestaande Phase-5a-flow (audit + fan-out) ongewijzigd
 *     doorloopt.
 */
class PaymentWebhookHandler
{
    public function __construct(
        private readonly MollieConnectionContext $context,
        private readonly AccountSubscriptionManager $manager,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(string $id, array $payload, Connection $connection): WebhookHandlerResult
    {
        $this->context->set($connection);

        try {
            $payment = Mollie::client()->payments->get($id);
        } catch (Throwable $e) {
            return WebhookHandlerResult::antiSpoofFailed($e->getMessage());
        }

        $subscriptionId = is_string($payment->subscriptionId ?? null) ? $payment->subscriptionId : null;
        if ($subscriptionId === null) {
            return WebhookHandlerResult::ok();
        }

        /** @var AccountSubscription|null $sub */
        $sub = AccountSubscription::query()
            ->where('connection_id', $connection->id)
            ->where('mollie_subscription_id', $subscriptionId)
            ->first();

        if ($sub === null) {
            return WebhookHandlerResult::ok();
        }

        // Mollie\Api\Resources\Payment heeft geen toArray() — gebruik object→array
        // cast op de dynamic-properties container. Filter NUL-prefixed (protected)
        // sleutels zoals " * connector" en " * origin" weg zodat de manager alleen
        // payment-data ziet (status, details, subscriptionId, ...).
        $paymentArray = array_filter(
            (array) $payment,
            fn (string|int $key): bool => is_string($key) && ! str_contains($key, "\0"),
            ARRAY_FILTER_USE_KEY,
        );

        $this->manager->recordPaymentEvent($sub, $paymentArray);

        return WebhookHandlerResult::ok($sub->id);
    }
}
