<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\Webhooks\ForwardWebhookToConsumerJob;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\InboundWebhookEvent;
use Emeq\MollieApi\Exceptions\AuthenticationException;
use Emeq\MollieApi\Exceptions\NotFoundException;
use Emeq\MollieApi\Mollie;
use Emeq\MollieApi\Webhooks\MollieWebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;
use Throwable;

class MollieWebhookAntiSpoofingTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'whsec_test_xyz';

    protected function setUp(): void
    {
        parent::setUp();
        config(['mollie.webhook.secret' => $this->secret]);
    }

    public function test_webhook_for_id_that_returns_404_from_mollie_returns_400_resource_ownership_failed(): void
    {
        Bus::fake();
        $this->bindMollieClientThatThrows(new NotFoundException('payment tr_spoof_1 not found for this connection'));

        $connection = $this->makeMollieConnection();
        $payload = json_encode(['id' => 'tr_spoof_1']);
        $signature = MollieWebhookSignature::sign($payload, $this->secret);

        $response = $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(400);
        $response->assertJsonPath('error', 'resource_ownership_failed');
        Bus::assertNotDispatched(ForwardWebhookToConsumerJob::class);

        $event = InboundWebhookEvent::query()->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('invalid_signature', $event->outcome);
        $this->assertSame(400, $event->status);
    }

    public function test_webhook_for_id_that_returns_auth_error_from_mollie_returns_400(): void
    {
        Bus::fake();
        $this->bindMollieClientThatThrows(new AuthenticationException('access_token revoked'));

        $connection = $this->makeMollieConnection();
        $payload = json_encode(['id' => 'tr_spoof_2']);
        $signature = MollieWebhookSignature::sign($payload, $this->secret);

        $response = $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(400);
        $response->assertJsonPath('error', 'resource_ownership_failed');
        Bus::assertNotDispatched(ForwardWebhookToConsumerJob::class);

        $event = InboundWebhookEvent::query()->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('invalid_signature', $event->outcome);
        $this->assertSame(400, $event->status);
    }

    private function makeMollieConnection(): Connection
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();

        return Connection::factory()->forMollie()->active()->for($account)->create();
    }

    /**
     * Bind een Mollie-mock waarbij client()->payments->get($id) altijd $throwable gooit.
     * Gebruikt een endpoint-stub die op het payments-property leeft van een echte
     * MollieApiClient niet werkt (magic __get bouwt een nieuwe EndpointCollection elke call).
     *
     * In plaats daarvan mocken we direct het Mollie-wrapper-object zodat client() een
     * stub-MollieApiClient-subclass returnt waar payments een vooraf-bepaalde stub is.
     */
    private function bindMollieClientThatThrows(Throwable $throwable): void
    {
        $payments = new class($throwable)
        {
            public function __construct(private Throwable $throwable) {}

            public function get(string $id): never
            {
                throw $this->throwable;
            }
        };

        $client = new ThrowingMollieApiClient($payments);

        $mollie = $this->createMock(Mollie::class);
        $mollie->method('client')->willReturn($client);
        $this->app->instance(Mollie::class, $mollie);
    }
}
