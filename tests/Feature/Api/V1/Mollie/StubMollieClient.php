<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mollie;

use Mollie\Api\MollieApiClient;

/**
 * Test-only MollieApiClient-subclass: vervangt het `payments`-property door
 * een vooraf-gegeven endpoint-stub zodat tests precies kunnen sturen wat
 * `Mollie::client()->payments->create/get/cancel` retourneert ÉN wat de
 * controller via `setIdempotencyKey()` heeft gezet vlak vóór de call.
 *
 * Hergebruik-pattern van Tests\Feature\Webhooks\ThrowingMollieApiClient
 * (Plan 05a-02). Het verschil hier is dat de stub niet alleen gooit maar
 * ook een Payment-resource kan retourneren.
 */
class StubMollieClient extends MollieApiClient
{
    public function __construct(private object $paymentsStub)
    {
        parent::__construct();
        $this->setAccessToken('access_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
    }

    public function __get(string $name): mixed
    {
        if ($name === 'payments') {
            return $this->paymentsStub;
        }

        return parent::__get($name);
    }
}
