<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\Consumer;
use App\Models\WebhookCall;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan 10-01 — D-2: Hub-eigen WebhookCall subclass + consumer() belongs-to.
 *
 * Bewijst:
 *  - App\Models\WebhookCall extends de Spatie-class (drop-in-compat)
 *  - consumer() relatie is null wanneer consumer_id NULL
 *  - consumer() relatie hydrates de juiste Consumer wanneer consumer_id is gevuld
 *  - Consumer::webhookCalls() HasMany werkt symmetrisch
 *  - config/webhook-client.php webhook_model verwijst naar Hub-subclass
 *    (zodat Spatie's storeWebhook() nieuwe rijen op deze class schrijft)
 */
class WebhookCallConsumerRelationTest extends TestCase
{
    use RefreshDatabase;

    private function insertWebhookCall(array $overrides = []): int
    {
        $base = [
            'name' => 'mollie.payment.test',
            'url' => 'https://hub.emeq.test/webhooks/mollie',
            'headers' => json_encode([]),
            'payload' => json_encode(['order_id' => 'ord_DEFAULT']),
            'direction' => 'incoming',
            'provider' => 'mollie',
            'consumer_id' => null,
            'status' => 'processed',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return DB::table('webhook_calls')->insertGetId(array_merge($base, $overrides));
    }

    public function test_webhook_call_extends_spatie_class(): void
    {
        $this->assertTrue(
            is_subclass_of(WebhookCall::class, \Spatie\WebhookClient\Models\WebhookCall::class),
            'App\\Models\\WebhookCall moet Spatie\\WebhookClient\\Models\\WebhookCall extenden'
        );
    }

    public function test_consumer_relation_returns_null_when_consumer_id_is_null(): void
    {
        $id = $this->insertWebhookCall(['consumer_id' => null]);

        $webhookCall = WebhookCall::find($id);

        $this->assertInstanceOf(BelongsTo::class, $webhookCall->consumer());
        $this->assertNull($webhookCall->consumer);
    }

    public function test_consumer_relation_returns_consumer_when_consumer_id_is_set(): void
    {
        $consumer = Consumer::factory()->create();
        $id = $this->insertWebhookCall(['consumer_id' => $consumer->id]);

        $webhookCall = WebhookCall::find($id);

        $this->assertInstanceOf(Consumer::class, $webhookCall->consumer);
        $this->assertSame($consumer->id, $webhookCall->consumer->id);
        $this->assertSame($consumer->slug, $webhookCall->consumer->slug);
    }

    public function test_consumer_has_many_webhook_calls_relation(): void
    {
        $consumer = Consumer::factory()->create();
        $this->insertWebhookCall(['consumer_id' => $consumer->id]);
        $this->insertWebhookCall(['consumer_id' => $consumer->id]);
        $this->insertWebhookCall(['consumer_id' => null]); // mag NIET meetellen

        $this->assertInstanceOf(HasMany::class, $consumer->webhookCalls());
        $this->assertSame(2, $consumer->webhookCalls()->count());
    }

    public function test_webhook_model_config_resolves_to_hub_subclass(): void
    {
        $this->assertSame(
            WebhookCall::class,
            config('webhook-client.configs.0.webhook_model'),
            'config/webhook-client.php webhook_model moet de Hub-subclass zijn'
        );
    }
}
