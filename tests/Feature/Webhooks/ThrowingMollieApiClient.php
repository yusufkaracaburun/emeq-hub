<?php

namespace Tests\Feature\Webhooks;

use Mollie\Api\MollieApiClient;

/**
 * Test-only stub: MollieApiClient subclass die magic __get('payments') overschrijft
 * en een vooraf-gegeven stub returnt. Gebruikt door MollieWebhookAntiSpoofingTest om
 * een payments->get() te laten gooien zonder de echte EndpointCollection te raken.
 */
class ThrowingMollieApiClient extends MollieApiClient
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
