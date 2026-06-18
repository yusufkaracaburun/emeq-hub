<?php

declare(strict_types=1);

namespace App\Jobs\Exact;

use App\Models\Connection;
use App\Services\Exact\ExactWebhookSubscriptionManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Registreert de Exact-webhook-subscriptions van een Connection ná OAuth-connect.
 * Async zodat de OAuth-callback niet blokkeert op Exact's subscribe-handshake
 * (Exact POST't tijdens de create direct een validatie-ping naar onze CallbackURL).
 */
final class RegisterExactWebhookSubscriptionsJob implements ShouldQueue
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
        $manager->register($this->exactConnection);
    }
}
