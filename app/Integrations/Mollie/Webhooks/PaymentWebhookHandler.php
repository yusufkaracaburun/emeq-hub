<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Webhooks;

use App\Integrations\Mollie\Billing\AccountSubscriptionManager;
use App\Integrations\Mollie\MollieConnectionContext;
use App\Models\AccountSubscription;
use App\Models\Connection;
use Emeq\MollieApi\Facades\Mollie;
use Throwable;

class PaymentWebhookHandler
{
    public function __construct(
        private readonly MollieConnectionContext $context,
        private readonly AccountSubscriptionManager $manager,
    ) {}

    /** @param  array<string, mixed>  $payload */
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

        $paymentArray = array_filter(
            (array) $payment,
            fn (string|int $key): bool => is_string($key) && ! str_contains($key, "\0"),
            ARRAY_FILTER_USE_KEY,
        );

        $this->manager->recordPaymentEvent($sub, $paymentArray);

        return WebhookHandlerResult::ok($sub->id);
    }
}
