<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Webhooks;

use App\Billing\Account\AccountSubscriptionManager;
use App\Integrations\Mollie\MollieConnectionContext;
use App\Models\AccountSubscription;
use App\Models\Connection;

class SubscriptionWebhookHandler
{
    public function __construct(
        private readonly MollieConnectionContext $context,
        private readonly AccountSubscriptionManager $manager,
    ) {}

    /** @param  array<string, mixed>  $payload */
    public function handle(string $id, array $payload, Connection $connection): WebhookHandlerResult
    {
        $this->context->set($connection);

        /** @var AccountSubscription|null $sub */
        $sub = AccountSubscription::query()
            ->where('connection_id', $connection->id)
            ->where('mollie_subscription_id', $id)
            ->first();

        if ($sub === null) {
            return WebhookHandlerResult::skip('unknown_subscription');
        }

        $this->manager->syncFromMollie($sub);

        return WebhookHandlerResult::ok($sub->id);
    }
}
