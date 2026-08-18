<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Errors;

use App\Enums\Provider;
use App\Integrations\Errors\UpstreamErrorMapperRegistry;
use Emeq\ExactApi\Exceptions\AuthenticationException as ExactAuthenticationException;
use Emeq\MollieApi\Exceptions\AuthenticationException as MollieAuthenticationException;
use RuntimeException;
use Tests\TestCase;

class UpstreamErrorMapperRegistryTest extends TestCase
{
    public function test_each_provider_is_mapped_by_its_own_mapper(): void
    {
        $registry = $this->registry();

        $exact = $registry->map(Provider::Exact->value, ExactAuthenticationException::apiUnauthorized(401, 'nope'));
        $mollie = $registry->map(Provider::Mollie->value, new MollieAuthenticationException('401 from Mollie'));

        $this->assertSame('exact_auth', $exact['short_code']);
        $this->assertSame('mollie_auth', $mollie['short_code']);
        $this->assertSame('mollie_auth_failed', $mollie['body']['error']);
    }

    public function test_every_known_provider_has_a_mapper(): void
    {
        $registry = $this->registry();

        foreach (Provider::cases() as $provider) {
            $this->assertTrue(
                $registry->supports($provider->value),
                "Provider '{$provider->value}' heeft geen UpstreamErrorMapper geregistreerd in AppServiceProvider."
            );
        }
    }

    public function test_an_unregistered_provider_falls_back_instead_of_throwing(): void
    {
        $mapped = $this->registry()->map('moneybird', new RuntimeException('boom'));

        $this->assertSame(502, $mapped['status']);
        $this->assertSame('unmapped_provider', $mapped['short_code']);
    }

    private function registry(): UpstreamErrorMapperRegistry
    {
        return $this->app->make(UpstreamErrorMapperRegistry::class);
    }
}
