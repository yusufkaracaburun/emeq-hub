<?php

namespace Tests\Feature\Integrations\Mollie\Http\Webhooks;

use Mollie\Api\MollieApiClient;

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
