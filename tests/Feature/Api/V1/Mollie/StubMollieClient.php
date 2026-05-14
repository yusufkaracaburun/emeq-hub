<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mollie;

use Mollie\Api\MollieApiClient;

/**
 * Test-only MollieApiClient-subclass: vervangt endpoint-properties door
 * vooraf-gegeven endpoint-stubs zodat tests precies kunnen sturen wat
 * `Mollie::client()->{property}->...` retourneert ÉN wat de
 * controller via `setIdempotencyKey()` heeft gezet vlak vóór de call.
 *
 * Hergebruik-pattern van Tests\Feature\Webhooks\ThrowingMollieApiClient
 * (Plan 05a-02). Plan 05a-04 breidt de subclass uit met customers,
 * methods, paymentRefunds, refunds en customerMandates zodat de extra
 * resource-controllers dezelfde stub-strategie kunnen hergebruiken.
 *
 * @property mixed $payments
 * @property mixed $customers
 * @property mixed $methods
 * @property mixed $paymentRefunds
 * @property mixed $refunds
 * @property mixed $mandates
 */
class StubMollieClient extends MollieApiClient
{
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

    public function __get(string $name): mixed
    {
        if (isset($this->stubs[$name])) {
            return $this->stubs[$name];
        }

        return parent::__get($name);
    }
}
