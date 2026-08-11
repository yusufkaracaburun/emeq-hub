<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Mollie\Webhooks;

use App\Integrations\Mollie\Webhooks\PaymentWebhookHandler;
use App\Integrations\Mollie\Webhooks\SubscriptionWebhookHandler;
use App\Integrations\Mollie\Webhooks\WebhookHandlerResult;
use App\Integrations\Mollie\Webhooks\WebhookPayloadRouter;
use App\Models\Connection;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

class WebhookPayloadRouterTest extends TestCase
{
    private SubscriptionWebhookHandler&MockInterface $subscriptionHandler;

    private PaymentWebhookHandler&MockInterface $paymentHandler;

    private WebhookPayloadRouter $router;

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subscriptionHandler = Mockery::mock(SubscriptionWebhookHandler::class);
        $this->paymentHandler = Mockery::mock(PaymentWebhookHandler::class);
        $this->router = new WebhookPayloadRouter($this->subscriptionHandler, $this->paymentHandler);
        $this->connection = new Connection;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_sub_prefix_routes_to_subscription_handler(): void
    {
        $expected = WebhookHandlerResult::ok(42);
        $this->subscriptionHandler->shouldReceive('handle')
            ->once()
            ->with('sub_abc', [], $this->connection)
            ->andReturn($expected);
        $this->paymentHandler->shouldNotReceive('handle');

        $result = $this->router->routeFor('sub_abc', [], $this->connection);

        $this->assertSame($expected, $result);
    }

    public function test_tr_prefix_routes_to_payment_handler(): void
    {
        $expected = WebhookHandlerResult::ok();
        $this->paymentHandler->shouldReceive('handle')
            ->once()
            ->with('tr_xyz', [], $this->connection)
            ->andReturn($expected);
        $this->subscriptionHandler->shouldNotReceive('handle');

        $result = $this->router->routeFor('tr_xyz', [], $this->connection);

        $this->assertSame($expected, $result);
    }

    public function test_mdt_prefix_returns_skip_without_calling_handlers(): void
    {
        $this->subscriptionHandler->shouldNotReceive('handle');
        $this->paymentHandler->shouldNotReceive('handle');

        $result = $this->router->routeFor('mdt_foo', [], $this->connection);

        $this->assertSame('skip', $result->status);
        $this->assertSame('mandate_events_not_implemented', $result->reason);
        $this->assertFalse($result->isOk());
        $this->assertTrue($result->shouldAudit());
    }

    public function test_unknown_prefix_falls_back_to_payment_handler(): void
    {
        $expected = WebhookHandlerResult::ok();
        $this->paymentHandler->shouldReceive('handle')
            ->once()
            ->with('bar_xyz', [], $this->connection)
            ->andReturn($expected);
        $this->subscriptionHandler->shouldNotReceive('handle');

        $result = $this->router->routeFor('bar_xyz', [], $this->connection);

        $this->assertSame($expected, $result);
    }
}
