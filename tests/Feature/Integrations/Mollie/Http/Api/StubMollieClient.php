<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Mollie\Http\Api;

use Mollie\Api\MollieApiClient;

/**
 * @property mixed $payments
 * @property mixed $customers
 * @property mixed $methods
 * @property mixed $paymentRefunds
 * @property mixed $refunds
 * @property mixed $mandates
 * @property mixed $subscriptions
 * @property mixed $paymentLinks
 */
class StubMollieClient extends MollieApiClient
{
    public ?string $lastUsedAccessToken = null;

    public ?string $lastIdempotencyKey = null;

    /** @var array<string, object> */
    private array $stubs;

    /**
     * @param  object  $paymentsStub  Achterwaarts-compatibel met Plan 05a-03's PaymentsController-tests.
     * @param  array<string, object>  $extraStubs  Map van endpoint-property → stub-object voor 05a-04+ resources.
     */
    public function __construct(object $paymentsStub, array $extraStubs = [])
    {
        parent::__construct();
        $this->setAccessToken('access_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');

        $this->stubs = array_merge(['payments' => $paymentsStub], $extraStubs);
    }

    public function setAccessToken(string $accessToken): self
    {
        $this->lastUsedAccessToken = $accessToken;
        parent::setAccessToken($accessToken);

        return $this;
    }

    public function setIdempotencyKey($key): self
    {
        $this->lastIdempotencyKey = is_string($key) ? $key : (string) $key;
        parent::setIdempotencyKey($key);

        return $this;
    }

    public function __get(string $name): mixed
    {
        if (isset($this->stubs[$name])) {
            return $this->stubs[$name];
        }

        return parent::__get($name);
    }
}
