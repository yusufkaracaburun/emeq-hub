<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Enums\Provider;
use App\Support\ProviderAccess;
use Tests\TestCase;

class ProviderAccessTest extends TestCase
{
    public function test_scopes_keep_their_partner_order_and_get_a_dutch_explanation(): void
    {
        $described = ProviderAccess::describeScopes(['payments.write', 'customers.read']);

        $this->assertSame(['payments.write', 'customers.read'], array_keys($described));
        $this->assertSame('Betalingen aanmaken, wijzigen en terugbetalen', $described['payments.write']);
    }

    public function test_an_unknown_scope_is_still_listed_without_explanation(): void
    {
        // Een scope verbergen omdat wij 'm niet kennen zou toegang verzwijgen.
        $described = ProviderAccess::describeScopes(['balances.read']);

        $this->assertArrayHasKey('balances.read', $described);
        $this->assertSame('', $described['balances.read']);
    }

    public function test_no_scopes_yields_an_empty_list(): void
    {
        $this->assertSame([], ProviderAccess::describeScopes(null));
    }

    public function test_every_whitelisted_exact_resource_has_an_explanation(): void
    {
        // Vangt drift: een pad toevoegen aan de pass-through-whitelist zonder
        // uitleg laat de operator raden waar de Hub bij mag.
        $described = ProviderAccess::describeResources(Provider::Exact);

        $this->assertNotEmpty($described);
        $this->assertSame(config('hub-providers.exact.allowed_paths'), array_keys($described));

        foreach ($described as $path => $explanation) {
            $this->assertNotSame('', $explanation, "Geen uitleg voor resource {$path}");
        }
    }

    public function test_a_provider_without_whitelist_has_no_resource_list(): void
    {
        $this->assertSame([], ProviderAccess::describeResources(Provider::Snelstart));
    }

    public function test_every_provider_explains_how_access_is_granted(): void
    {
        foreach (Provider::cases() as $provider) {
            $this->assertNotSame('', ProviderAccess::note($provider));
        }
    }
}
