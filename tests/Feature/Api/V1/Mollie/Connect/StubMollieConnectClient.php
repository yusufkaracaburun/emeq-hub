<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mollie\Connect;

use Mollie\Api\MollieApiClient;

/**
 * @property mixed $clientLinks
 * @property mixed $onboarding
 * @property mixed $organizations
 * @property mixed $profiles
 * @property mixed $permissions
 */
class StubMollieConnectClient extends MollieApiClient
{
    public ?string $lastUsedAccessToken = null;

    public ?string $lastIdempotencyKey = null;

    /** @var array<string, object> */
    private array $stubs;

    /** @param  array<string, object>  $stubs  Map van endpoint-property → stub-object. */
    public function __construct(array $stubs = [])
    {
        parent::__construct();
        $this->stubs = $stubs;
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
