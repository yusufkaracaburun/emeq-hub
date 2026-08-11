<?php

declare(strict_types=1);

namespace App\Integrations\Exact\Jobs;

use App\Integrations\Exact\ExactWebhookSubscriptionManager;
use App\Models\Connection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Zegt de Exact-webhook-subscriptions van een Connection op bij revoke. Best-effort:
 * de manager faalt niet hard als de delete-call niet meer kan (token weg na revoke) —
 * Exact ruimt verweesde subscriptions 's nachts zelf op.
 */
final class DeleteExactWebhookSubscriptionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public Connection $exactConnection)
    {
        $this->onQueue('webhooks');
    }

    public function handle(ExactWebhookSubscriptionManager $manager): void
    {
        $manager->unsubscribe($this->exactConnection);
    }
}
