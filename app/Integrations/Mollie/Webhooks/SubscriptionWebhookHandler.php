<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Webhooks;

use App\Billing\Account\AccountSubscriptionManager;
use App\Integrations\Mollie\MollieConnectionContext;
use App\Models\AccountSubscription;
use App\Models\Connection;

/**
 * Webhook-handler voor Mollie Subscription-id's (`sub_*`-prefix) per
 * 07-CONTEXT.md D-15.
 *
 * Flow:
 *  1. Bind MollieConnectionContext voor de juiste access_token.
 *  2. Lookup matching `AccountSubscription` op `connection_id +
 *     mollie_subscription_id`. Geen match → `skip('unknown_subscription')`
 *     zodat de Mollie-quota niet verbrand wordt voor onbekende subs (de
 *     Mollie-GET in `syncFromMollie` is duur).
 *  3. Delegeer naar `AccountSubscriptionManager::syncFromMollie()` die zelf
 *     de Mollie-GET doet + state-machine bijwerkt (D-17: 404 → Unknown).
 */
class SubscriptionWebhookHandler
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
