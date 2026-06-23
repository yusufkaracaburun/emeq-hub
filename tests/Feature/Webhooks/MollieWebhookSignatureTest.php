<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\Webhooks\ForwardMollieWebhookToConsumerJob;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\InboundWebhookEvent;
use Emeq\MollieApi\Mollie;
use Emeq\MollieApi\Webhooks\MollieWebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Api\MollieApiClient;
use Tests\TestCase;
use Throwable;

class MollieWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'whsec_test_xyz';

    protected function setUp(): void
    {
        parent::setUp();
        config(['mollie.webhook.secret' => $this->secret]);
    }

    public function test_valid_signature_returns_202_and_writes_inbound_audit_row(): void
    {
        Bus::fake();
        $this->fakeMolliePaymentGet('tr_test123');

        $connection = $this->makeMollieConnection();
        $payload = json_encode(['id' => 'tr_test123']);
        $signature = MollieWebhookSignature::sign($payload, $this->secret);

        $response = $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(202);
        $response->assertJsonPath('status', 'accepted');

        $event = InboundWebhookEvent::query()->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('mollie', $event->provider);
        $this->assertSame('processed', $event->outcome);
        $this->assertSame(202, $event->status);
        $this->assertNull($event->event_id);
        $this->assertSame($connection->id, $event->connection_id);
        Bus::assertDispatched(ForwardMollieWebhookToConsumerJob::class);
    }

    public function test_tampered_signature_returns_400_and_no_dispatch(): void
    {
        Bus::fake();
        $connection = $this->makeMollieConnection();
        $payload = json_encode(['id' => 'tr_test123']);
        $tampered = MollieWebhookSignature::sign($payload, 'wrong_secret');

        $response = $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $tampered, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(400);
        $response->assertJsonPath('error', 'invalid_signature');
        Bus::assertNotDispatched(ForwardMollieWebhookToConsumerJob::class);

        $event = InboundWebhookEvent::query()->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('invalid_signature', $event->outcome);
        $this->assertSame(400, $event->status);
    }

    public function test_missing_signature_header_returns_400_with_missing_signature(): void
    {
        Bus::fake();
        $connection = $this->makeMollieConnection();
        $payload = json_encode(['id' => 'tr_test123']);

        $response = $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(400);
        $response->assertJsonPath('error', 'missing_signature');
        Bus::assertNotDispatched(ForwardMollieWebhookToConsumerJob::class);

        $event = InboundWebhookEvent::query()->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('invalid_signature', $event->outcome);
        $this->assertSame(400, $event->status);
    }

    public function test_unknown_connection_id_returns_410_gone(): void
    {
        Bus::fake();
        $payload = json_encode(['id' => 'tr_test123']);
        $signature = MollieWebhookSignature::sign($payload, $this->secret);

        $response = $this->call(
            'POST',
            '/webhooks/mollie/99999',
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(410);
        $response->assertJsonPath('error', 'connection_gone');
        Bus::assertNotDispatched(ForwardMollieWebhookToConsumerJob::class);

        $event = InboundWebhookEvent::query()->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('unknown_tenant', $event->outcome);
        $this->assertSame(410, $event->status);
    }

    public function test_revoked_connection_returns_410_gone(): void
    {
        Bus::fake();
        $connection = $this->makeMollieConnection();
        $connection->update(['revoked_at' => now()->subMinute()]);

        $payload = json_encode(['id' => 'tr_test123']);
        $signature = MollieWebhookSignature::sign($payload, $this->secret);

        $response = $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(410);
        Bus::assertNotDispatched(ForwardMollieWebhookToConsumerJob::class);
    }

    public function test_payload_without_id_returns_400_missing_id(): void
    {
        Bus::fake();
        $this->fakeMolliePaymentGet('tr_unused');

        $connection = $this->makeMollieConnection();
        $payload = json_encode([]);
        $signature = MollieWebhookSignature::sign($payload, $this->secret);

        $response = $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(400);
        $response->assertJsonPath('error', 'missing_id');
        Bus::assertNotDispatched(ForwardMollieWebhookToConsumerJob::class);

        $event = InboundWebhookEvent::query()->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('malformed', $event->outcome);
        $this->assertSame(400, $event->status);
        $this->assertSame($connection->id, $event->connection_id);
    }

    public function test_null_platform_secret_returns_500_and_does_not_dispatch(): void
    {
        Bus::fake();
        config(['mollie.webhook.secret' => null]);

        $connection = $this->makeMollieConnection();
        $payload = json_encode(['id' => 'tr_test123']);
        // Signature is irrelevant — guard moet faillen vóór verify
        $signature = MollieWebhookSignature::sign($payload, 'any-value');

        $response = $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(500);
        $response->assertJsonPath('error', 'webhook_misconfigured');
        Bus::assertNotDispatched(ForwardMollieWebhookToConsumerJob::class);

        $event = InboundWebhookEvent::query()->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('misconfigured', $event->outcome);
        $this->assertSame(500, $event->status);
    }

    public function test_empty_string_platform_secret_returns_500_and_does_not_dispatch(): void
    {
        Bus::fake();
        config(['mollie.webhook.secret' => '']);

        $connection = $this->makeMollieConnection();
        $payload = json_encode(['id' => 'tr_test123']);
        $signature = MollieWebhookSignature::sign($payload, '');

        $response = $this->call(
            'POST',
            "/webhooks/mollie/{$connection->id}",
            [], [], [],
            ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(500);
        $response->assertJsonPath('error', 'webhook_misconfigured');
        Bus::assertNotDispatched(ForwardMollieWebhookToConsumerJob::class);

        $event = InboundWebhookEvent::query()->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('misconfigured', $event->outcome);
        $this->assertSame(500, $event->status);
    }

    private function makeMollieConnection(): Connection
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();

        return Connection::factory()->forMollie()->active()->for($account)->create();
    }

    /**
     * Bind een MollieApiClient::fake()-instance op de container via een Mollie-mock,
     * zodat controller's anti-spoofing-fetch (Mollie::client()->payments->get($id))
     * een MockResponse retourneert zonder echte HTTP-call. Optionele $throwable wordt
     * gegooid in plaats van een succes-response.
     */
    private function fakeMolliePaymentGet(string $id, ?Throwable $throwable = null): MollieApiClient
    {
        $response = $throwable !== null
            ? fn () => throw $throwable
            : MockResponse::ok([
                'resource' => 'payment',
                'id' => $id,
                'status' => 'paid',
                'mode' => 'test',
                'createdAt' => '2026-01-01T00:00:00+00:00',
                'amount' => ['currency' => 'EUR', 'value' => '10.00'],
                'description' => 'test',
                'redirectUrl' => 'https://example.test/return',
            ]);

        $fake = MollieApiClient::fake([
            GetPaymentRequest::class => $response,
        ]);

        $mollie = $this->createMock(Mollie::class);
        $mollie->method('client')->willReturn($fake);
        $this->app->instance(Mollie::class, $mollie);

        return $fake;
    }
}
